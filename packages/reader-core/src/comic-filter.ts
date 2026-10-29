/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { ReaderLayout } from './types.ts'

export const COMIC_FILTER_MIN = 50
export const COMIC_FILTER_MAX = 200

/**
 * @param value percent
 */
function clamp(value: number | undefined): number {
	if (typeof value !== 'number' || !Number.isFinite(value)) {
		return 100
	}
	return Math.min(COMIC_FILTER_MAX, Math.max(COMIC_FILTER_MIN, Math.round(value)))
}

/**
 * CSS filter for comic pages (brightness, contrast, grayscale). 'none' when all values are the defaults.
 *
 * @param layout
 */
export function comicFilter(layout: Pick<ReaderLayout, 'comicBrightness' | 'comicContrast' | 'comicGrayscale'>): string {
	const parts: string[] = []
	const brightness = clamp(layout.comicBrightness)
	const contrast = clamp(layout.comicContrast)
	if (brightness !== 100) {
		parts.push(`brightness(${brightness}%)`)
	}
	if (contrast !== 100) {
		parts.push(`contrast(${contrast}%)`)
	}
	if (layout.comicGrayscale) {
		parts.push('grayscale(100%)')
	}
	return parts.length ? parts.join(' ') : 'none'
}
