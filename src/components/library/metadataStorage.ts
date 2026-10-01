/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { MetadataTarget, MetadataWriteMode } from '../../types.ts'

export const METADATA_TARGETS: readonly MetadataTarget[] = ['sidecar', 'file', 'both', 'library']

/** Formats the app can write metadata into (other formats only have the library and the sidecar). */
export const EMBED_FORMATS: readonly string[] = ['epub', 'cbz', 'fb2', 'fbz']

/** Books above this size are embedded by a server task instead of within the request. */
export const EMBED_SYNC_MAX_BYTES = 20 * 1024 * 1024

/**
 * Whether the target writes into the book file, i.e. whether "when to write" (background/immediate) matters.
 *
 * @param target
 */
export function writesBookFile(target: MetadataTarget): boolean {
	return target === 'file' || target === 'both'
}

/**
 * Settings value of a stored/loaded target; older servers only know the write mode ("never" = library only).
 *
 * @param target
 * @param legacyMode
 */
export function resolveTarget(target: unknown, legacyMode?: unknown): MetadataTarget {
	if (typeof target === 'string' && (METADATA_TARGETS as readonly string[]).includes(target)) {
		return target as MetadataTarget
	}
	return legacyMode === 'never' ? 'library' : 'sidecar'
}

/**
 * The write mode to send: only "background" or "immediate" are valid, anything else means the default.
 *
 * @param mode
 */
export function resolveWriteMode(mode: unknown): MetadataWriteMode {
	return mode === 'immediate' ? 'immediate' : 'background'
}

/**
 * Whether "Write metadata into the book file" is offered for a book.
 *
 * @param format
 * @param editable
 * @param downloadable
 */
export function canEmbed(format: string, editable: boolean, downloadable: boolean): boolean {
	return editable && downloadable && EMBED_FORMATS.includes(format.toLowerCase())
}
