/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { allFormatInfos, comparisonRows, FORMAT_KEYS, formatInfo, isConvertFormat, RECOMMENDED_FORMAT, translateReason } from './formats.ts'

describe('format info', () => {
	it('has an entry for every format key', () => {
		expect(FORMAT_KEYS).toEqual(['cbz', 'cbr', 'cb7', 'cbt', 'epub'])
		expect(allFormatInfos().map((i) => i.key)).toEqual([...FORMAT_KEYS])
	})

	it.each(FORMAT_KEYS)('%s is complete', (key) => {
		const info = formatInfo(key)
		expect(info.key).toBe(key)
		expect(info.name.length).toBeGreaterThan(0)
		expect(info.extension).toBe(key)
		expect(info.summary.length).toBeGreaterThan(0)
		expect(info.pros.length).toBeGreaterThan(0)
		expect(info.cons.length).toBeGreaterThan(0)
		expect(info.compatibility.length).toBeGreaterThan(0)
		for (const text of [...info.pros, ...info.cons]) {
			expect(text.trim()).not.toBe('')
		}
	})

	it('recommends CBZ and warns about RAR', () => {
		expect(RECOMMENDED_FORMAT).toBe('cbz')
		expect(formatInfo('cbz').pros.join(' ')).toMatch(/Recommended/)
		expect(formatInfo('cbr').cons.join(' ')).toMatch(/proprietary/i)
		expect(formatInfo('cbr').cons.join(' ')).toMatch(/convert to CBZ/)
	})

	it('has a comparison cell for every format in every row', () => {
		const rows = comparisonRows()
		expect(rows.length).toBeGreaterThan(2)
		for (const row of rows) {
			for (const key of FORMAT_KEYS) {
				expect(row.cells[key], `${row.key}/${key}`).toBeTruthy()
			}
		}
	})

	it('recognises format keys', () => {
		expect(isConvertFormat('cb7')).toBe(true)
		expect(isConvertFormat('pdf')).toBe(false)
	})

	it('keeps unknown server reasons as they are', () => {
		expect(translateReason(undefined)).toBe('')
		expect(translateReason('RAR can only be written with proprietary software')).toBe('RAR can only be written with proprietary software')
		expect(translateReason('something else')).toBe('something else')
	})
})
