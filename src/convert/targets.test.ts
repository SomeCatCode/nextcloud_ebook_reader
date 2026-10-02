/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { ConvertTarget } from './types.ts'

import { describe, expect, it } from 'vitest'
import { browserCanConvert, defaultTarget, isRecommended, isSelectable, selectableTargets, targetPath } from './targets.ts'

function targets(modes: Record<string, ConvertTarget['mode']>): ConvertTarget[] {
	return (['cbz', 'cbr', 'cb7', 'cbt', 'epub'] as const).map((format) => ({ format, mode: modes[format] ?? 'unavailable' }))
}

describe('target selection', () => {
	it('keeps only usable targets', () => {
		const list = targets({ cbz: 'server', cb7: 'client', epub: 'server' })
		expect(selectableTargets(list).map((t) => t.format)).toEqual(['cbz', 'cb7', 'epub'])
	})

	it('preselects CBZ, else the first usable target', () => {
		expect(defaultTarget(targets({ cbz: 'client', cbt: 'server' }))).toBe('cbz')
		expect(defaultTarget(targets({ cbt: 'server', epub: 'server' }))).toBe('cbt')
		expect(defaultTarget(targets({}))).toBeNull()
		expect(defaultTarget([])).toBeNull()
	})

	it('offers the current format only as "optimize only" and only with the server', () => {
		const same: ConvertTarget = { format: 'cbz', mode: 'server', optimizeOnly: true, reason: 'This is the current format' }
		const client: ConvertTarget = { format: 'cb7', mode: 'client' }
		const server: ConvertTarget = { format: 'cbt', mode: 'server' }
		expect(isSelectable(same)).toBe(false)
		expect(isSelectable(same, true)).toBe(true)
		// the browser cannot optimize images
		expect(isSelectable(client)).toBe(true)
		expect(isSelectable(client, true)).toBe(false)
		expect(selectableTargets([same, client, server]).map((t) => t.format)).toEqual(['cb7', 'cbt'])
		expect(selectableTargets([same, client, server], true).map((t) => t.format)).toEqual(['cbz', 'cbt'])
		expect(defaultTarget([client, same], true)).toBe('cbz')
		expect(defaultTarget([client, same])).toBe('cb7')
	})

	it('recommends CBZ unless the book is one already', () => {
		expect(isRecommended('cbz', 'cbr')).toBe(true)
		expect(isRecommended('cbz', 'cbz')).toBe(false)
		expect(isRecommended('epub', 'cbr')).toBe(false)
	})

	it('builds the target path next to the original', () => {
		expect(targetPath('/Comics/Marvel/Spider-Man 01.cbr', 'cbz')).toBe('/Comics/Marvel/Spider-Man 01.cbz')
		expect(targetPath('/Comics/A.B.cbz', 'epub')).toBe('/Comics/A.B.epub')
		expect(targetPath('Book.cb7', 'cbt')).toBe('Book.cbt')
	})

	it('knows what the browser can convert: all comic sources, no RAR output', () => {
		for (const source of ['cbz', 'cbr', 'cb7', 'cbt']) {
			for (const target of ['cbz', 'cb7', 'cbt', 'epub']) {
				expect(browserCanConvert(source, target), `${source} -> ${target}`).toBe(source !== target)
			}
			expect(browserCanConvert(source, 'cbr')).toBe(false)
		}
		expect(browserCanConvert('epub', 'cbz')).toBe(false)
	})
})
