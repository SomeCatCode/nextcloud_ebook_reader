/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Annotation } from '../types.ts'

/**
 * RFC 4122 v4 UUID (the server only accepts this shape).
 */
export function newUuid(): string {
	if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
		return crypto.randomUUID()
	}
	const b = new Uint8Array(16)
	crypto.getRandomValues(b)
	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80
	const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('')
	return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`
}

/**
 * Display kind: bookmarks stay bookmarks; everything with a note is a note, the rest are plain highlights.
 *
 * @param a
 */
export function kindOf(a: Pick<Annotation, 'type' | 'note'>): 'bookmark' | 'note' | 'highlight' {
	if (a.type === 'bookmark') {
		return 'bookmark'
	}
	return a.note ? 'note' : 'highlight'
}
