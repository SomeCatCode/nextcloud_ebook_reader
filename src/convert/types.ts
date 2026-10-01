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
}

export interface ConvertTargets {
	source: string
	targets: ConvertTarget[]
}

export interface ConvertCapabilities {
	tools: { sevenZip: boolean, unrar: boolean, bsdtar: boolean }
	server: { read: string[], write: string[] }
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
