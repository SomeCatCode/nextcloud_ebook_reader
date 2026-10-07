/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Book } from './types.ts'

/**
 * Splits books into those the user may delete and those that belong to someone else (reached via a share).
 * Deleting a shared book would delete the owner's file, so the server refuses it and the UI does not offer it.
 *
 * @param books
 */
export function splitDeletable(books: Book[]): { own: Book[], shared: Book[] } {
	const own: Book[] = []
	const shared: Book[] = []
	for (const b of books) {
		(b.shared ? shared : own).push(b)
	}
	return { own, shared }
}
