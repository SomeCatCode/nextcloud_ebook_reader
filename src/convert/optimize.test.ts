/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it } from 'vitest'
import {
	estimateQuery,
	formatBytes,
	isOptimizable,
	isOptimizeActive,
	loadOptions,
	OPTIMIZE_OFF,
	optimizeBody,
	parseOptions,
	savedPercent,
	saveOptions,
	splitOptimizable,
} from './optimize.ts'

describe('optimize options', () => {
	it('is only active with a height limit or the PNG conversion', () => {
		expect(isOptimizeActive(OPTIMIZE_OFF)).toBe(false)
		expect(isOptimizeActive({ maxHeight: 1920, pngToJpeg: false })).toBe(true)
		expect(isOptimizeActive({ maxHeight: 0, pngToJpeg: true })).toBe(true)
	})

	it('sends no optimize body when nothing is selected', () => {
		expect(optimizeBody(OPTIMIZE_OFF)).toBeUndefined()
		expect(optimizeBody({ maxHeight: 2560, pngToJpeg: true })).toEqual({ maxHeight: 2560, pngToJpeg: true })
	})

	it('builds the estimate query', () => {
		expect(estimateQuery({ maxHeight: 2560, pngToJpeg: false })).toBe('maxHeight=2560&pngToJpeg=0')
		expect(estimateQuery({ maxHeight: 1920, pngToJpeg: true })).toBe('maxHeight=1920&pngToJpeg=1')
	})

	it('sanitises foreign values to the allowlist', () => {
		expect(parseOptions({ maxHeight: 1920, pngToJpeg: true })).toEqual({ maxHeight: 1920, pngToJpeg: true })
		expect(parseOptions({ maxHeight: 1234, pngToJpeg: 'yes' })).toEqual({ maxHeight: 0, pngToJpeg: false })
		expect(parseOptions(null)).toEqual(OPTIMIZE_OFF)
	})

	describe('remembered options', () => {
		beforeEach(() => {
			localStorage.clear()
		})

		it('starts with "off" and restores the last choice', () => {
			expect(loadOptions()).toEqual(OPTIMIZE_OFF)
			saveOptions({ maxHeight: 2560, pngToJpeg: true })
			expect(loadOptions()).toEqual({ maxHeight: 2560, pngToJpeg: true })
		})

		it('ignores damaged storage', () => {
			localStorage.setItem('ebookreader.convert.optimize', '{nope')
			expect(loadOptions()).toEqual(OPTIMIZE_OFF)
		})
	})
})

describe('selection', () => {
	const books = [
		{ fileId: 1, format: 'cbz' as const },
		{ fileId: 2, format: 'cbr' as const },
		{ fileId: 3, format: 'epub' as const },
		{ fileId: 4, format: 'cb7' as const },
	]

	it('knows the optimizable formats', () => {
		expect(['cbz', 'cbr', 'cb7', 'cbt'].every(isOptimizable)).toBe(true)
		expect(isOptimizable('epub')).toBe(false)
		expect(isOptimizable('pdf')).toBe(false)
	})

	it('splits a selection into comics and the rest, keeping the selection order', () => {
		expect(splitOptimizable(books, [4, 3, 1, 99])).toEqual({ eligible: [4, 1], other: [3, 99] })
		expect(splitOptimizable(books, [])).toEqual({ eligible: [], other: [] })
	})
})

describe('sizes', () => {
	it('formats bytes', () => {
		expect(formatBytes(0)).toBe('0 KB')
		expect(formatBytes(512 * 1024)).toBe('512 KB')
		expect(formatBytes(180 * 1024 * 1024)).toBe('180 MB')
		expect(formatBytes(1.5 * 1024 * 1024 * 1024)).toBe('1.5 GB')
	})

	it('computes the saved share', () => {
		const est = { pages: 10, oversizedPages: 5, currentBytes: 1000, estimatedBytes: 400, exact: false }
		expect(savedPercent(est)).toBe(60)
		expect(savedPercent({ ...est, estimatedBytes: 1200 })).toBe(0)
		expect(savedPercent({ ...est, currentBytes: 0 })).toBe(0)
	})
})
