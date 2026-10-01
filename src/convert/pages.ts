/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Locator } from '../types.ts'
import type { ConvertFormat } from './types.ts'

import { epubPageHrefs, pageId } from './epub.ts'

const IMAGE_RE = /\.(jpe?g|png|gif|webp|avif|bmp)$/i

/**
 * @param a
 * @param b
 */
export function naturalCompare(a: string, b: string): number {
	return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' })
}

/**
 * Image entries in reading order (natural sort); macOS resource forks and hidden files are skipped.
 *
 * @param names
 */
export function pageEntries(names: string[]): string[] {
	return names
		.filter((n) => IMAGE_RE.test(n) && !n.includes('__MACOSX/') && !(n.split('/').pop() ?? '').startsWith('.'))
		.sort(naturalCompare)
}

/**
 * Final entry name of page `index` (0-based), zero padded like the server does.
 *
 * @param index
 * @param count
 * @param sourceName
 */
export function pageName(index: number, count: number, sourceName: string): string {
	const ext = (sourceName.split('.').pop() ?? 'jpg').toLowerCase()
	return `${pageId(index + 1, Math.max(4, String(count).length))}.${ext === 'jpeg' ? 'jpg' : ext}`
}

/**
 * Locator of the same page in the converted book, or null if the old locator does not point at a page.
 *
 * @param old
 * @param oldPages entry names of the source pages in reading order
 * @param target
 */
export function remapLocator(old: Locator, oldPages: string[], target: ConvertFormat): Locator | null {
	const path = old.href.split('#')[0]
	let index = oldPages.indexOf(path)
	if (index < 0 && old.locations?.position) {
		index = old.locations.position - 1
	}
	if (index < 0 || index >= oldPages.length) {
		return null
	}
	const locations: NonNullable<Locator['locations']> = { position: index + 1, progression: 0 }
	if (typeof old.locations?.totalProgression === 'number') {
		locations.totalProgression = old.locations.totalProgression
	}
	if (target === 'epub') {
		return { href: epubPageHrefs(oldPages.length)[index], type: 'application/xhtml+xml', locations }
	}
	const name = pageName(index, oldPages.length, oldPages[index])
	const ext = name.split('.').pop() ?? 'jpg'
	return { href: name, type: `image/${ext === 'jpg' ? 'jpeg' : ext}`, locations }
}
