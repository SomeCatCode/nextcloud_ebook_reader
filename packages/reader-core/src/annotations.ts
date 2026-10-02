/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { AnnotationColor, ReaderLocator } from './types.ts'

import * as CFI from '../vendor/foliate-js/epubcfi.js'

/** CSS colors of the highlight colors (drawn into an SVG overlay, 30 % opacity by the overlayer). */
export const ANNOTATION_COLORS: Record<AnnotationColor, string> = {
	yellow: '#ffd43b',
	green: '#51cf66',
	blue: '#4dabf7',
	pink: '#f06595',
	purple: '#9775fa',
}

export const ANNOTATION_COLOR_NAMES = Object.keys(ANNOTATION_COLORS) as AnnotationColor[]

/**
 * @param value
 */
export function isAnnotationColor(value: unknown): value is AnnotationColor {
	return typeof value === 'string' && value in ANNOTATION_COLORS
}

/**
 * CSS color of a highlight, falls back to yellow for unknown/missing values.
 *
 * @param color
 */
export function colorValue(color: string | null | undefined): string {
	return isAnnotationColor(color) ? ANNOTATION_COLORS[color] : ANNOTATION_COLORS.yellow
}

/**
 * Compares two CFIs (document order). Returns null when one of them can not be parsed.
 *
 * @param a
 * @param b
 */
export function compareCfi(a: string, b: string): number | null {
	if (!CFI.isCFI.test(a) || !CFI.isCFI.test(b)) {
		return null
	}
	try {
		return Math.sign(CFI.compare(a, b))
	} catch {
		return null
	}
}

/**
 * Document order of two locators: by CFI when both have one, otherwise by comic position or the overall progression.
 * Usable as an Array.sort comparator.
 *
 * @param a
 * @param b
 */
export function compareLocators(a: ReaderLocator, b: ReaderLocator): number {
	const la = a.locations ?? {}
	const lb = b.locations ?? {}
	if (la.cfi && lb.cfi) {
		const c = compareCfi(la.cfi, lb.cfi)
		if (c) {
			return c
		}
		if (c === 0) {
			return 0
		}
	}
	if (typeof la.position === 'number' && typeof lb.position === 'number' && la.position !== lb.position && !la.cfi) {
		return la.position - lb.position
	}
	const ta = la.totalProgression
	const tb = lb.totalProgression
	if (typeof ta === 'number' && typeof tb === 'number' && ta !== tb) {
		return ta - tb
	}
	return 0
}

/**
 * Is the (point) CFI inside the CFI range of the visible page? Both can be range CFIs.
 *
 * @param cfi
 * @param pageCfi range CFI of the visible text
 */
export function cfiOnPage(cfi: string, pageCfi: string): boolean {
	try {
		const start = CFI.collapse(pageCfi, false)
		const end = CFI.collapse(pageCfi, true)
		const point = CFI.collapse(cfi, false)
		return CFI.compare(point, start) >= 0 && CFI.compare(point, end) <= 0
	} catch {
		return false
	}
}

/**
 * First position of a (range) CFI as point CFI; used for bookmarks (the start of the visible page).
 *
 * @param cfi
 */
export function collapseCfi(cfi: string): string {
	try {
		return CFI.collapse(cfi, false)
	} catch {
		return cfi
	}
}

/**
 * Is a stored locator (bookmark) on the page the reader currently shows?
 *
 * @param locator stored locator
 * @param page current page locator (as emitted by `relocate`)
 * @param pageCfi range CFI of the visible text (reflowable books)
 */
export function locatorOnPage(locator: ReaderLocator, page: ReaderLocator | null, pageCfi?: string): boolean {
	if (!page) {
		return false
	}
	const l = locator.locations ?? {}
	const p = page.locations ?? {}
	if (l.cfi && pageCfi) {
		return cfiOnPage(l.cfi, pageCfi)
	}
	if (typeof l.position === 'number' && typeof p.position === 'number' && !l.cfi) {
		return l.position === p.position
	}
	return false
}
