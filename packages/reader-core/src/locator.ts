/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { ReaderLocator } from './types.ts'

/** Data extracted from a foliate relocate event (view.lastLocation + renderer detail). */
export interface RelocateInfo {
	cfi?: string
	/** whole-book fraction 0..1 */
	fraction: number
	sectionIndex: number
	/** fraction inside the current section (renderer event), if known */
	sectionFraction?: number
	locationCurrent?: number
	tocLabel?: string
	sectionHref: string
}

const IMAGE_TYPES: Record<string, string> = {
	jpg: 'image/jpeg',
	jpeg: 'image/jpeg',
	png: 'image/png',
	gif: 'image/gif',
	bmp: 'image/bmp',
	webp: 'image/webp',
	svg: 'image/svg+xml',
	jxl: 'image/jxl',
	avif: 'image/avif',
}

const clamp01 = (n: number): number => Math.min(1, Math.max(0, Number.isFinite(n) ? n : 0))
const round = (n: number, digits = 5): number => Math.round(n * 10 ** digits) / 10 ** digits

/**
 * @param href
 * @param isComic
 */
export function mediaTypeFor(href: string, isComic: boolean): string {
	if (isComic) {
		const ext = href.split('.').pop()?.toLowerCase() ?? ''
		return IMAGE_TYPES[ext] ?? 'image/jpeg'
	}
	return /\.html?$/i.test(href) ? 'text/html' : 'application/xhtml+xml'
}

/**
 * foliate relocate -> contract locator
 *
 * @param info
 * @param isComic
 */
export function toLocator(info: RelocateInfo, isComic: boolean): ReaderLocator {
	const locations: NonNullable<ReaderLocator['locations']> = {
		totalProgression: round(clamp01(info.fraction)),
	}
	if (isComic) {
		locations.position = info.sectionIndex + 1
		locations.progression = 0
	} else {
		if (info.sectionFraction !== undefined) {
			locations.progression = round(clamp01(info.sectionFraction))
		}
		if (info.locationCurrent !== undefined) {
			locations.position = info.locationCurrent + 1
		}
		if (info.cfi) {
			locations.cfi = info.cfi
		}
	}
	const locator: ReaderLocator = {
		href: info.sectionHref,
		type: mediaTypeFor(info.sectionHref, isComic),
		locations,
	}
	if (info.tocLabel) {
		locator.title = info.tocLabel
	}
	return locator
}

export type NavStep
	= | { kind: 'cfi', cfi: string }
		| { kind: 'section', index: number, progression: number, fragment?: string }
		| { kind: 'fraction', fraction: number }

export interface NavContext {
	sectionIds: string[]
	isComic: boolean
}

/**
 * @param href
 */
function splitFragment(href: string): [string, string | undefined] {
	const i = href.indexOf('#')
	return i < 0 ? [href, undefined] : [href.slice(0, i), href.slice(i + 1) || undefined]
}

/**
 * Ordered list of strategies to try for a locator: cfi, then href (+progression),
 * comic position, then totalProgression.
 *
 * @param target
 * @param ctx
 */
export function planNavigation(target: ReaderLocator | string, ctx: NavContext): NavStep[] {
	const loc: ReaderLocator = typeof target === 'string' ? { href: target } : target
	const steps: NavStep[] = []
	const l = loc.locations ?? {}
	if (!ctx.isComic && l.cfi && /^epubcfi\(/.test(l.cfi)) {
		steps.push({ kind: 'cfi', cfi: l.cfi })
	}
	if (loc.href) {
		const [path, fragment] = splitFragment(loc.href)
		let index = ctx.sectionIds.indexOf(path)
		if (index < 0) {
			try {
				index = ctx.sectionIds.indexOf(decodeURI(path))
			} catch {
				// malformed URI, ignore
			}
		}
		if (index >= 0) {
			steps.push({
				kind: 'section',
				index,
				progression: clamp01(l.progression ?? 0),
				fragment: l.progression === undefined ? fragment : undefined,
			})
		}
	}
	if (ctx.isComic && l.position && l.position >= 1 && l.position <= ctx.sectionIds.length) {
		steps.push({ kind: 'section', index: l.position - 1, progression: 0 })
	}
	if (typeof l.totalProgression === 'number') {
		steps.push({ kind: 'fraction', fraction: clamp01(l.totalProgression) })
	}
	return steps
}
