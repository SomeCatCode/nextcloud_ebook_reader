/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { pageEntries, pageName, remapLocator } from './pages.ts'

describe('page helpers', () => {
	it('sorts pages naturally and skips non-images', () => {
		const names = ['p10.jpg', 'p2.jpg', 'p1.JPG', 'notes.txt', '__MACOSX/p1.jpg', 'dir/.hidden.png', 'ComicInfo.xml']
		expect(pageEntries(names)).toEqual(['p1.JPG', 'p2.jpg', 'p10.jpg'])
	})

	it('names pages with zero padded numbers and normalised extensions', () => {
		expect(pageName(0, 12, 'x/Page.JPEG')).toBe('0001.jpg')
		expect(pageName(11, 12, 'a.png')).toBe('0012.png')
		expect(pageName(0, 12345, 'a.webp')).toBe('00001.webp')
	})

	const pages = ['a/1.png', 'a/2.png', 'a/10.png']

	it('remaps a locator by page index for comic targets', () => {
		const out = remapLocator({ href: 'a/2.png', locations: { position: 2, totalProgression: 0.4, cfi: 'x' } }, pages, 'cbz')
		expect(out).toEqual({ href: '0002.png', type: 'image/png', locations: { position: 2, progression: 0, totalProgression: 0.4 } })
	})

	it('remaps to the XHTML page for EPUB', () => {
		const out = remapLocator({ href: 'a/10.png' }, pages, 'epub')
		expect(out?.href).toBe('OEBPS/pages/0003.xhtml')
		expect(out?.locations?.position).toBe(3)
	})

	it('falls back to the position and gives up without one', () => {
		expect(remapLocator({ href: 'unknown', locations: { position: 1 } }, pages, 'cbt')?.href).toBe('0001.png')
		expect(remapLocator({ href: 'unknown' }, pages, 'cbt')).toBeNull()
		expect(remapLocator({ href: 'unknown', locations: { position: 9 } }, pages, 'cbt')).toBeNull()
	})
})
