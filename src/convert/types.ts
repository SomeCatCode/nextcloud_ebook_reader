/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Book } from '../types.ts'

/** Format keys of the conversion feature (CBR can only be a source). */
export type ConvertFormat = 'cbz' | 'cbr' | 'cb7' | 'cbt' | 'epub'

/** `server`: converted by the server, `client`: by the browser, `unavailable`: not possible (see reason) */
export type ConvertMode = 'server' | 'client' | 'unavailable'

export interface ConvertTarget {
	format: ConvertFormat
	mode: ConvertMode
	/** English text from the server, translated with translateReason() */
	reason?: string
	/** The target equals the source format: only possible together with image optimization */
	optimizeOnly?: boolean
}

export interface OptimizeAvailability {
	available: boolean
	/** English text from the server */
	reason?: string
}

export interface ConvertTargets {
	source: string
	optimize?: OptimizeAvailability
	targets: ConvertTarget[]
}

export interface ConvertCapabilities {
	tools: { sevenZip: boolean, unrar: boolean, bsdtar: boolean }
	server: { read: string[], write: string[] }
	optimize?: { available: boolean, maxHeights: number[] }
}

/** Answer of GET /books/{fileId}/convert/estimate; `exact` = every page was looked at. */
export interface OptimizeEstimate {
	pages: number
	oversizedPages: number
	currentBytes: number
	estimatedBytes: number
	exact: boolean
}

export interface OptimizeBulkResult {
	tasks: { fileId: number, taskId: number }[]
	skipped: { fileId: number, error: string, status: number }[]
}

export interface ConvertResult {
	book: Book
	fileId: number
	path: string
}

export type ConvertStep = 'download' | 'convert' | 'upload' | 'index'

export interface FormatInfo {
	key: ConvertFormat
	name: string
	/** file extension without dot */
	extension: string
	summary: string
	pros: string[]
	cons: string[]
	compatibility: string
}

/** One row of the comparison table: a label and a short cell per format. */
export interface ComparisonRow {
	key: string
	label: string
	cells: Record<ConvertFormat, string>
}
