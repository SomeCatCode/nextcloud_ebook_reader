/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Book } from '../types.ts'
import type { OptimizeEstimate } from './types.ts'

/** Maximum page height in pixels, 0 = do not optimize. */
export type MaxHeight = 0 | 2560 | 1920

export interface OptimizeOptions {
	maxHeight: MaxHeight
	pngToJpeg: boolean
}

/** Heights the server accepts besides 0 (keep in sync with ImageOptimizer::HEIGHTS). */
export const OPTIMIZE_HEIGHTS: readonly MaxHeight[] = [2560, 1920]

/** Comic formats the server can optimize (CBR becomes CBZ). */
export const OPTIMIZABLE_FORMATS: readonly string[] = ['cbz', 'cbr', 'cb7', 'cbt']

export const OPTIMIZE_OFF: OptimizeOptions = { maxHeight: 0, pngToJpeg: false }

const STORAGE_KEY = 'ebookreader.convert.optimize'

/**
 * Whether the options change anything (a height limit or the PNG conversion).
 *
 * @param options
 */
export function isOptimizeActive(options: OptimizeOptions): boolean {
	return options.maxHeight > 0 || options.pngToJpeg
}

/**
 * Request body part: undefined when nothing is to be optimized, so plain conversions send no `optimize`.
 *
 * @param options
 */
export function optimizeBody(options: OptimizeOptions): OptimizeOptions | undefined {
	return isOptimizeActive(options) ? { maxHeight: options.maxHeight, pngToJpeg: options.pngToJpeg } : undefined
}

/**
 * Query string of the estimate request.
 *
 * @param options
 */
export function estimateQuery(options: OptimizeOptions): string {
	return `maxHeight=${options.maxHeight}&pngToJpeg=${options.pngToJpeg ? 1 : 0}`
}

/**
 * Whether a format can be optimized on the server.
 *
 * @param format
 */
export function isOptimizable(format: string): boolean {
	return OPTIMIZABLE_FORMATS.includes(format)
}

/**
 * Splits a selection into books that can be optimized and the rest (not comics).
 *
 * @param books loaded books
 * @param selectedIds selected file ids
 */
export function splitOptimizable(books: Pick<Book, 'fileId' | 'format'>[], selectedIds: number[]): { eligible: number[], other: number[] } {
	const formats = new Map(books.map((b) => [b.fileId, b.format as string]))
	const eligible: number[] = []
	const other: number[] = []
	for (const id of selectedIds) {
		const format = formats.get(id)
		if (format !== undefined && isOptimizable(format)) {
			eligible.push(id)
		} else {
			other.push(id)
		}
	}
	return { eligible, other }
}

/**
 * Human readable size: "812 KB", "180 MB", "1.4 GB".
 *
 * @param bytes
 */
export function formatBytes(bytes: number): string {
	const kb = 1024
	const mb = kb * 1024
	const gb = mb * 1024
	if (bytes >= gb) {
		return `${(bytes / gb).toFixed(1)} GB`
	}
	if (bytes >= mb) {
		return `${Math.round(bytes / mb)} MB`
	}
	return `${Math.max(0, Math.round(bytes / kb))} KB`
}

/**
 * Share of the size that is saved, 0..100 (whole percent).
 *
 * @param estimate
 */
export function savedPercent(estimate: OptimizeEstimate): number {
	if (estimate.currentBytes <= 0) {
		return 0
	}
	return Math.max(0, Math.min(100, Math.round(100 * (1 - estimate.estimatedBytes / estimate.currentBytes))))
}

/**
 * Sanitises stored or foreign values to the allowlist.
 *
 * @param value
 */
export function parseOptions(value: unknown): OptimizeOptions {
	const v = (value ?? {}) as { maxHeight?: unknown, pngToJpeg?: unknown }
	const maxHeight = OPTIMIZE_HEIGHTS.find((h) => h === v.maxHeight) ?? 0
	return { maxHeight, pngToJpeg: v.pngToJpeg === true }
}

/**
 * Remembered options of the last use (per browser).
 */
export function loadOptions(): OptimizeOptions {
	try {
		const raw = localStorage.getItem(STORAGE_KEY)
		if (raw) {
			return parseOptions(JSON.parse(raw))
		}
	} catch {
		// storage unavailable or damaged: start with "off"
	}
	return { ...OPTIMIZE_OFF }
}

/**
 * @param options
 */
export function saveOptions(options: OptimizeOptions): void {
	try {
		localStorage.setItem(STORAGE_KEY, JSON.stringify(options))
	} catch {
		// storage unavailable (private mode): just not remembered
	}
}
