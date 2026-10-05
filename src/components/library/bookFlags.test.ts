/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { ageBadge, ageEntries, ageTermLabel, completionEntries, completionTermLabel, isAgeRating, normalizeAgeTerm, offersNextVolume, volumeLabel } from './bookFlags.ts'

describe('age terms', () => {
	it('normalises like the server', () => {
		expect(normalizeAgeTerm('12')).toBe('12')
		expect(normalizeAgeTerm(' <= 16 ')).toBe('<=16')
		expect(normalizeAgeTerm('NONE')).toBe('none')
		expect(normalizeAgeTerm('00')).toBe('0')
		expect(normalizeAgeTerm('15')).toBeNull()
		expect(normalizeAgeTerm('>=12')).toBeNull()
		expect(normalizeAgeTerm('<=')).toBeNull()
		expect(normalizeAgeTerm('abc')).toBeNull()
	})

	it('labels terms', () => {
		expect(ageTermLabel('16')).toBe('16+')
		expect(ageTermLabel('<=12')).toBe('Up to 12')
		expect(ageTermLabel('none')).toBe('No age rating')
		expect(ageBadge(0)).toBe('0+')
	})

	it('knows the levels', () => {
		expect(isAgeRating(16)).toBe(true)
		expect(isAgeRating(15)).toBe(false)
		expect(isAgeRating('16')).toBe(false)
	})
})

describe('navigation entries', () => {
	it('lists completion entries with books only', () => {
		expect(completionEntries([
			{ name: 'ongoing', count: 2 },
			{ name: 'completed', count: 0 },
			{ name: 'unknown', count: 5 },
			{ name: 'bogus', count: 1 },
		])).toEqual([
			{ name: 'ongoing', label: 'Ongoing', count: 2 },
			{ name: 'unknown', label: 'Unknown', count: 5 },
		])
		expect(completionEntries(undefined)).toEqual([])
		expect(completionTermLabel('completed')).toBe('Completed')
	})

	it('adds cumulative "up to" entries before the levels', () => {
		const entries = ageEntries([
			{ name: '0', count: 1 },
			{ name: '6', count: 0 },
			{ name: '12', count: 2 },
			{ name: '16', count: 0 },
			{ name: '18', count: 4 },
			{ name: 'none', count: 9 },
		])
		expect(entries.map((e) => [e.name, e.count])).toEqual([
			['<=0', 1],
			['<=6', 1],
			['<=12', 3],
			['<=16', 3],
			['0', 1],
			['12', 2],
			['18', 4],
			['none', 9],
		])
	})

	it('hides the age group when no book is rated', () => {
		expect(ageEntries([{ name: 'none', count: 9 }])).toEqual([])
		expect(ageEntries(undefined)).toEqual([])
	})
})

describe('next volume', () => {
	it('labels volumes by index', () => {
		expect(volumeLabel({ seriesIndex: 3 }, 'The End')).toBe('Volume 3: The End')
		expect(volumeLabel({ seriesIndex: null }, 'The End')).toBe('The End')
	})

	it('is offered at the end of a book in a series', () => {
		expect(offersNextVolume({ series: 'Saga' }, 0.99)).toBe(true)
		expect(offersNextVolume({ series: 'Saga' }, 0.5)).toBe(false)
		expect(offersNextVolume({ series: null }, 1)).toBe(false)
		expect(offersNextVolume(null, 1)).toBe(false)
	})
})
