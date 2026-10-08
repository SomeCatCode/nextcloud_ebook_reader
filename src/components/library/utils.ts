/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Book } from '../../types.ts'

/**
 * Display title with filename fallback.
 *
 * @param book
 */
export function bookTitle(book: Pick<Book, 'title' | 'path'>): string {
	if (book.title) {
		return book.title
	}
	const name = book.path.split('/').pop() ?? book.path
	return name.replace(/\.[^.]+$/, '')
}

/**
 *
 * @param book
 */
export function bookAuthors(book: Pick<Book, 'authors'>): string {
	return book.authors.join(', ')
}

/**
 * Up to two initials for the cover placeholder.
 *
 * @param title
 */
export function initials(title: string): string {
	const words = title.split(/\s+/).filter((w) => /[\p{L}\p{N}]/u.test(w))
	return words.slice(0, 2).map((w) => Array.from(w.replace(/^[^\p{L}\p{N}]+/u, ''))[0] ?? '').join('').toUpperCase()
}

/**
 * Stable hue (0-359) derived from a string, for placeholder colours.
 *
 * @param text
 */
export function hue(text: string): number {
	let h = 0
	for (let i = 0; i < text.length; i++) {
		h = (h * 31 + text.charCodeAt(i)) % 360
	}
	return h
}

/**
 * Reading progress as integer percent (accepts fractions 0..1 as well as 0..100).
 *
 * @param book
 */
export function progressPercent(book: Pick<Book, 'progress'>): number {
	const p = book.progress?.percentage
	if (p === undefined || p === null || Number.isNaN(p)) {
		return 0
	}
	const pct = p <= 1 ? p * 100 : p
	return Math.max(0, Math.min(100, Math.round(pct)))
}

/**
 * Formats a ms timestamp as a local date.
 *
 * @param ms
 */
export function formatDate(ms: number): string {
	return ms ? new Date(ms).toLocaleDateString() : ''
}

/**
 * Directory part of a user-relative path.
 *
 * @param path
 */
export function dirName(path: string): string {
	const i = path.lastIndexOf('/')
	return i <= 0 ? '/' : path.slice(0, i)
}

/**
 * Share state of a book or series for the badge: shared with the user by somebody else, shared by the
 * user with others, or not shared. Missing fields (older servers) count as not shared.
 *
 * @param item
 * @param item.shared
 * @param item.sharedOut
 */
export function shareState(item: { shared?: boolean, sharedOut?: boolean }): 'incoming' | 'outgoing' | null {
	if (item.shared === true) {
		return 'incoming'
	}
	return item.sharedOut === true ? 'outgoing' : null
}
