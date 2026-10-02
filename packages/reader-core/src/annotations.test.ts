/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { cfiOnPage, collapseCfi, colorValue, compareCfi, compareLocators, isAnnotationColor, locatorOnPage } from './annotations.ts'

describe('colors', () => {
	it('knows the five colors and falls back to yellow', () => {
		expect(isAnnotationColor('pink')).toBe(true)
		expect(isAnnotationColor('red')).toBe(false)
		expect(isAnnotationColor(null)).toBe(false)
		expect(colorValue('green')).not.toBe(colorValue('yellow'))
		expect(colorValue('nope')).toBe(colorValue('yellow'))
		expect(colorValue(null)).toBe(colorValue('yellow'))
	})
})

describe('compareCfi', () => {
	it('orders in document order', () => {
		expect(compareCfi('epubcfi(/6/4!/4/2/1:0)', 'epubcfi(/6/4!/4/2/1:5)')).toBe(-1)
		expect(compareCfi('epubcfi(/6/6!/4/2/1:0)', 'epubcfi(/6/4!/4/20/1:5)')).toBe(1)
		expect(compareCfi('epubcfi(/6/4!/4/2/1:0)', 'epubcfi(/6/4!/4/2/1:0)')).toBe(0)
	})

	it('compares range cfis by their start, then end', () => {
		expect(compareCfi('epubcfi(/6/4!/4/2,/1:0,/1:5)', 'epubcfi(/6/4!/4/2,/1:3,/1:9)')).toBe(-1)
	})

	it('returns null for garbage', () => {
		expect(compareCfi('nope', 'epubcfi(/6/4)')).toBeNull()
	})
})

describe('compareLocators', () => {
	it('uses cfi when both have one', () => {
		const a = { href: 'a', locations: { cfi: 'epubcfi(/6/4!/4/2/1:0)', totalProgression: 0.9 } }
		const b = { href: 'a', locations: { cfi: 'epubcfi(/6/4!/4/8/1:0)', totalProgression: 0.1 } }
		expect(compareLocators(a, b)).toBeLessThan(0)
		expect(compareLocators(b, a)).toBeGreaterThan(0)
	})

	it('uses comic positions', () => {
		expect(compareLocators({ href: 'p2', locations: { position: 2 } }, { href: 'p9', locations: { position: 9 } })).toBeLessThan(0)
	})

	it('falls back to totalProgression', () => {
		expect(compareLocators({ href: 'a', locations: { totalProgression: 0.5 } }, { href: 'b', locations: { totalProgression: 0.2 } })).toBeGreaterThan(0)
		expect(compareLocators({ href: 'a' }, { href: 'b' })).toBe(0)
	})

	it('sorts a mixed list', () => {
		const mk = (cfi: string) => ({ href: 'x', locations: { cfi } })
		const list = [mk('epubcfi(/6/8!/4/2/1:0)'), mk('epubcfi(/6/2!/4/2/1:4)'), mk('epubcfi(/6/2!/4/2/1:0)')]
		list.sort(compareLocators)
		expect(list.map((l) => l.locations.cfi)).toEqual(['epubcfi(/6/2!/4/2/1:0)', 'epubcfi(/6/2!/4/2/1:4)', 'epubcfi(/6/8!/4/2/1:0)'])
	})
})

describe('page matching', () => {
	const page = 'epubcfi(/6/4!/4/2,/2/1:0,/10/1:20)'

	it('detects a point inside the visible range', () => {
		expect(cfiOnPage('epubcfi(/6/4!/4/2/4/1:3)', page)).toBe(true)
		expect(cfiOnPage('epubcfi(/6/4!/4/2/12/1:0)', page)).toBe(false)
		expect(cfiOnPage('epubcfi(/6/2!/4/2/4/1:3)', page)).toBe(false)
		expect(cfiOnPage('epubcfi(/6/6!/4/2/4/1:3)', page)).toBe(false)
	})

	it('collapses a range to its start', () => {
		expect(collapseCfi(page)).toBe('epubcfi(/6/4!/4/2/2/1:0)')
		expect(collapseCfi('epubcfi(/6/4!/4/2/4/1:3)')).toBe('epubcfi(/6/4!/4/2/4/1:3)')
	})

	it('a bookmark created from the page start is on that page', () => {
		const bookmark = { href: 'x', locations: { cfi: collapseCfi(page) } }
		expect(locatorOnPage(bookmark, { href: 'x' }, page)).toBe(true)
		expect(locatorOnPage(bookmark, { href: 'x' }, 'epubcfi(/6/6!/4/2,/2/1:0,/10/1:20)')).toBe(false)
	})

	it('matches comic bookmarks by page index', () => {
		expect(locatorOnPage({ href: 'p3', locations: { position: 3 } }, { href: 'p3', locations: { position: 3 } })).toBe(true)
		expect(locatorOnPage({ href: 'p3', locations: { position: 3 } }, { href: 'p4', locations: { position: 4 } })).toBe(false)
		expect(locatorOnPage({ href: 'p3', locations: { position: 3 } }, null)).toBe(false)
	})
})
