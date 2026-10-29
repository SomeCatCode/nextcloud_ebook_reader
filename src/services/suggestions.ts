/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Book, ReadStatus, Suggestions } from '../types.ts'

/** Number of similar titles shown. */
export const SIMILAR_LIMIT = 4

/**
 * Whether the "finished reading" panel is due: the end of the book is on screen or the book is already finished.
 * The reading percentage is no signal: a comic reports 100 % as soon as its last page is shown, a long strip as soon
 * as the top of its last page is, and 99 % of a long text book are still several pages.
 *
 * @param atEnd the reader shows the end of the book (last page or spread, bottom of a long strip)
 * @param status read status of the book, if known
 */
export function reachedEnd(atEnd: boolean, status?: ReadStatus | null): boolean {
	return atEnd || status === 'finished'
}

/**
 * Cleans a server answer for display: no duplicates, never the book itself, at most `limit` similar titles.
 *
 * @param raw answer of GET /books/{fileId}/suggestions
 * @param fileId the book the suggestions are for
 * @param limit maximum number of similar titles
 */
export function normalizeSuggestions(raw: Partial<Suggestions> | null | undefined, fileId: number, limit = SIMILAR_LIMIT): Suggestions {
	const next = raw?.next && raw.next.fileId !== fileId ? raw.next : null
	const seen = new Set<number>([fileId])
	if (next) {
		seen.add(next.fileId)
	}
	const similar: Book[] = []
	for (const b of raw?.similar ?? []) {
		if (!seen.has(b.fileId) && similar.length < limit) {
			seen.add(b.fileId)
			similar.push(b)
		}
	}
	return { next, similar }
}

/**
 * Whether there is anything to show.
 *
 * @param s
 */
export function hasSuggestions(s: Suggestions | null | undefined): s is Suggestions {
	return !!s && (s.next !== null || s.similar.length > 0)
}

/**
 * Looks suggestions up once per book. A late answer for another book is dropped.
 *
 * @param fetchSuggestions loads the raw suggestions of a book
 * @param onResult called with the cleaned suggestions of the current book (only when there is something to show)
 */
export function createSuggestionsLookup(
	fetchSuggestions: (fileId: number, limit: number) => Promise<Suggestions>,
	onResult: (s: Suggestions) => void,
): { request: (fileId: number) => void, reset: () => void } {
	let requested: number | null = null
	let current: number | null = null
	return {
		request(fileId: number): void {
			if (requested === fileId) {
				return
			}
			requested = fileId
			current = fileId
			fetchSuggestions(fileId, SIMILAR_LIMIT).then((raw) => {
				const s = normalizeSuggestions(raw, fileId)
				if (current === fileId && hasSuggestions(s)) {
					onResult(s)
				}
			}).catch(() => {
				// nothing to suggest then
			})
		},
		reset(): void {
			requested = null
			current = null
		},
	}
}

// ---- hand-over of a book from the reader to the library ----------------------------------------------

let pendingDetails: Book | null = null

/**
 * The reader opens the library with the details of this book (a suggested title).
 *
 * @param book
 */
export function setPendingDetails(book: Book | null): void {
	pendingDetails = book
}

/** Takes (and clears) the book whose details the library should show. */
export function takePendingDetails(): Book | null {
	const b = pendingDetails
	pendingDetails = null
	return b
}
