/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { comicFilter } from './comic-filter.ts'

describe('comicFilter', () => {
	it('is none for the defaults', () => {
		expect(comicFilter({})).toBe('none')
		expect(comicFilter({ comicBrightness: 100, comicContrast: 100, comicGrayscale: false })).toBe('none')
	})

	it('combines the filters', () => {
		expect(comicFilter({ comicBrightness: 120, comicContrast: 90, comicGrayscale: true })).toBe('brightness(120%) contrast(90%) grayscale(100%)')
	})

	it('keeps values inside the range', () => {
		expect(comicFilter({ comicBrightness: 5, comicContrast: 900 })).toBe('brightness(50%) contrast(200%)')
		expect(comicFilter({ comicBrightness: Number.NaN })).toBe('none')
	})
})
