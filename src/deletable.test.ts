/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Book } from './types.ts'

import { describe, expect, it } from 'vitest'
import { splitDeletable } from './deletable.ts'

/**
 * @param fileId
 * @param shared
 */
function book(fileId: number, shared: boolean): Book {
	return { fileId, shared, owner: shared ? 'alice' : 'me' } as Book
}

describe('splitDeletable', () => {
	it('separates own books from shared ones', () => {
		const { own, shared } = splitDeletable([book(1, false), book(2, true), book(3, false)])
		expect(own.map((b) => b.fileId)).toEqual([1, 3])
		expect(shared.map((b) => b.fileId)).toEqual([2])
	})

	it('handles an empty list', () => {
		expect(splitDeletable([])).toEqual({ own: [], shared: [] })
	})
})
