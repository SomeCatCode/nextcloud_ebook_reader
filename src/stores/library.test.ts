/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Book } from '../types.ts'

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import * as api from '../services/api.ts'
import { PAGE_SIZE, SEARCH_DEBOUNCE_MS, useLibraryStore } from './library.ts'

vi.mock('../services/api.ts', () => ({
	listBooks: vi.fn(),
	getFacets: vi.fn(),
	recentBooks: vi.fn(),
	patchAppData: vi.fn(),
	bulkTags: vi.fn(),
}))

const mocked = vi.mocked(api)

/**
 * @param fileId
 * @param extra
 */
function book(fileId: number, extra: Partial<Book> = {}): Book {
	return {
		fileId,
		format: 'epub',
		path: `/Books/${fileId}.epub`,
		size: 1,
		title: `Book ${fileId}`,
		authors: [],
		series: null,
		seriesIndex: null,
		description: null,
		language: null,
		publisher: null,
		isbn: null,
		publishedAt: null,
		genres: [],
		tags: [],
		rating: null,
		readStatus: 'unread',
		hasCover: false,
		coverEtag: null,
		mtime: 0,
		addedAt: 0,
		updatedAt: 0,
		editable: true,
		progress: null,
		...extra,
	}
}

describe('library store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.useFakeTimers()
		vi.resetAllMocks()
		mocked.listBooks.mockResolvedValue({ books: [book(1), book(2)], total: 2 })
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('loads the first page', async () => {
		const store = useLibraryStore()
		await store.reload()
		expect(store.books).toHaveLength(2)
		expect(store.hasMore).toBe(false)
		expect(mocked.listBooks).toHaveBeenCalledWith(expect.objectContaining({ offset: 0, limit: PAGE_SIZE, sort: 'title', order: 'asc' }))
	})

	it('paginates by offset and drops duplicates', async () => {
		const store = useLibraryStore()
		mocked.listBooks.mockResolvedValueOnce({ books: [book(1), book(2)], total: 3 })
		await store.reload()
		expect(store.hasMore).toBe(true)
		mocked.listBooks.mockResolvedValueOnce({ books: [book(2), book(3)], total: 3 })
		await store.loadMore()
		expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({ offset: 2 }))
		expect(store.books.map((b) => b.fileId)).toEqual([1, 2, 3])
		await store.loadMore()
		expect(mocked.listBooks).toHaveBeenCalledTimes(2)
	})

	it('debounces search by 300ms', () => {
		const store = useLibraryStore()
		store.setSearch('a')
		store.setSearch('ab')
		vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS - 1)
		expect(mocked.listBooks).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect(mocked.listBooks).toHaveBeenCalledTimes(1)
		expect(mocked.listBooks).toHaveBeenCalledWith(expect.objectContaining({ search: 'ab' }))
	})

	it('combines filters and toggles them', () => {
		const store = useLibraryStore()
		store.toggleFilter('genre', 'Fantasy')
		store.setFilter('status', 'unread')
		expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({ genre: 'Fantasy', status: 'unread' }))
		store.toggleFilter('genre', 'Fantasy')
		expect(store.filters.genre).toBeNull()
		expect(store.hasFilters).toBe(true)
		store.resetFilters()
		expect(store.hasFilters).toBe(false)
	})

	it('picks a sensible default order per sort key', () => {
		const store = useLibraryStore()
		store.setSort('added')
		expect(store.order).toBe('desc')
		store.setSort('author')
		expect(store.order).toBe('asc')
	})

	it('ignores stale responses', async () => {
		const store = useLibraryStore()
		let resolveFirst: (v: { books: Book[], total: number }) => void = () => {}
		mocked.listBooks.mockImplementationOnce(() => new Promise((r) => {
			resolveFirst = r
		}))
		const first = store.reload()
		mocked.listBooks.mockResolvedValueOnce({ books: [book(9)], total: 1 })
		await store.reload()
		resolveFirst({ books: [book(1)], total: 1 })
		await first
		expect(store.books.map((b) => b.fileId)).toEqual([9])
	})

	it('updates rating optimistically and rolls back on failure', async () => {
		const store = useLibraryStore()
		await store.reload()
		let resolve: (b: Book) => void = () => {}
		mocked.patchAppData.mockImplementationOnce(() => new Promise((r) => {
			resolve = r
		}))
		const p = store.setRating(1, 4)
		expect(store.books[0].rating).toBe(4)
		resolve(book(1, { rating: 4, updatedAt: 5 }))
		await p
		expect(store.books[0].updatedAt).toBe(5)

		mocked.patchAppData.mockRejectedValueOnce(new Error('nope'))
		await expect(store.setReadStatus(1, 'finished')).rejects.toThrow('nope')
		expect(store.books[0].readStatus).toBe('unread')
	})

	it('manages selection and bulk tags', async () => {
		const store = useLibraryStore()
		await store.reload()
		store.toggleSelected(1)
		store.toggleSelected(2)
		store.toggleSelected(1)
		expect(store.selectedIds).toEqual([2])
		mocked.bulkTags.mockResolvedValue({ updated: 1, failed: [] })
		mocked.getFacets.mockResolvedValue({ genres: [], tags: [], authors: [], series: [], formats: [] })
		const res = await store.bulkTags({ addGenres: ['X'], removeGenres: [], addTags: [], removeTags: [] })
		expect(res.updated).toBe(1)
		expect(mocked.bulkTags).toHaveBeenCalledWith(expect.objectContaining({ fileIds: [2], addGenres: ['X'] }))
		store.setSelectMode(false)
		expect(store.selectedIds).toEqual([])
	})
})
