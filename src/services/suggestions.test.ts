/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Book, Suggestions } from '../types.ts'

import { describe, expect, it, vi } from 'vitest'
import {
	createSuggestionsLookup,
	hasSuggestions,
	normalizeSuggestions,
	reachedEnd,
	setPendingDetails,
	takePendingDetails,
} from './suggestions.ts'

const book = (fileId: number): Book => ({ fileId, title: `B${fileId}` }) as Book

describe('reachedEnd', () => {
	it('is true when the reader shows the end or for finished books', () => {
		expect(reachedEnd(true)).toBe(true)
		expect(reachedEnd(false)).toBe(false)
		expect(reachedEnd(false, 'finished')).toBe(true)
		expect(reachedEnd(false, 'reading')).toBe(false)
		expect(reachedEnd(false, null)).toBe(false)
	})
})

describe('normalizeSuggestions', () => {
	it('drops the book itself, duplicates and the next volume from the similar list', () => {
		const s = normalizeSuggestions({ next: book(2), similar: [book(1), book(2), book(3), book(3), book(4)] }, 1)
		expect(s.next?.fileId).toBe(2)
		expect(s.similar.map((b) => b.fileId)).toEqual([3, 4])
	})

	it('limits the similar titles and tolerates missing parts', () => {
		const many = [10, 11, 12, 13, 14, 15].map(book)
		expect(normalizeSuggestions({ next: null, similar: many }, 1, 3).similar).toHaveLength(3)
		expect(normalizeSuggestions({ next: null, similar: many }, 1).similar).toHaveLength(4)
		expect(normalizeSuggestions({ next: book(1), similar: [] }, 1).next).toBeNull()
		expect(normalizeSuggestions(null, 1)).toEqual({ next: null, similar: [] })
		expect(normalizeSuggestions({}, 1)).toEqual({ next: null, similar: [] })
	})
})

describe('hasSuggestions', () => {
	it('needs a next volume or a similar title', () => {
		expect(hasSuggestions({ next: null, similar: [] })).toBe(false)
		expect(hasSuggestions(null)).toBe(false)
		expect(hasSuggestions({ next: book(2), similar: [] })).toBe(true)
		expect(hasSuggestions({ next: null, similar: [book(3)] })).toBe(true)
	})
})

describe('createSuggestionsLookup', () => {
	it('asks once per book and reports only non-empty answers', async () => {
		const fetchSuggestions = vi.fn((): Promise<Suggestions> => Promise.resolve({ next: book(2), similar: [] }))
		const onResult = vi.fn()
		const lookup = createSuggestionsLookup(fetchSuggestions, onResult)
		lookup.request(1)
		lookup.request(1)
		await Promise.resolve()
		await Promise.resolve()
		expect(fetchSuggestions).toHaveBeenCalledTimes(1)
		expect(fetchSuggestions).toHaveBeenCalledWith(1, 4)
		expect(onResult).toHaveBeenCalledTimes(1)

		const empty = createSuggestionsLookup(() => Promise.resolve({ next: null, similar: [] }), onResult)
		empty.request(5)
		await Promise.resolve()
		await Promise.resolve()
		expect(onResult).toHaveBeenCalledTimes(1)
	})

	it('drops a late answer after a reset or for another book and survives errors', async () => {
		let resolveFirst: (s: Suggestions) => void = () => {}
		const onResult = vi.fn()
		const lookup = createSuggestionsLookup((id) => id === 1
			? new Promise<Suggestions>((r) => { resolveFirst = r })
			: Promise.reject(new Error('down')), onResult)
		lookup.request(1)
		lookup.request(2)
		resolveFirst({ next: book(9), similar: [] })
		await Promise.resolve()
		await Promise.resolve()
		await Promise.resolve()
		expect(onResult).not.toHaveBeenCalled()
		lookup.reset()
		lookup.request(1)
		expect(() => lookup.request(2)).not.toThrow()
	})
})

describe('pending details', () => {
	it('hands a book over exactly once', () => {
		setPendingDetails(book(7))
		expect(takePendingDetails()?.fileId).toBe(7)
		expect(takePendingDetails()).toBeNull()
	})
})
