/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Book } from '../types.ts'

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import * as api from '../services/api.ts'
import { pollTask } from '../services/tasks.ts'
import { PAGE_SIZE, queryToState, SEARCH_DEBOUNCE_MS, shelfTerm, smartQueryToState, stateToQuery, stateToSmartQuery, useLibraryStore } from './library.ts'

vi.mock('../services/api.ts', () => ({
	listBooks: vi.fn(),
	getFacets: vi.fn(),
	recentBooks: vi.fn(),
	patchAppData: vi.fn(),
	bulkTags: vi.fn(),
	bulkMetadata: vi.fn(),
	patchMetadata: vi.fn(),
	resetOverrides: vi.fn(),
	embedMetadata: vi.fn(),
	listSeries: vi.fn(),
	listShelves: vi.fn(),
}))
vi.mock('../services/tasks.ts', () => ({ pollTask: vi.fn() }))

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
		downloadable: true,
		overrides: [],
		hasSidecar: false,
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

	it('cycles a term through include, exclude and off', () => {
		const store = useLibraryStore()
		const term = { type: 'genre' as const, name: 'Fantasy' }
		store.cycleTerm(term)
		expect(store.termState(term)).toBe('include')
		expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({ include: [term], exclude: undefined, match: undefined }))
		store.cycleTerm(term)
		expect(store.termState(term)).toBe('exclude')
		expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({ include: undefined, exclude: [term] }))
		store.cycleTerm(term)
		expect(store.termState(term)).toBeNull()
		expect(store.hasFilters).toBe(false)
	})

	it('builds include, exclude and match into the query', () => {
		const store = useLibraryStore()
		const a = { type: 'genre' as const, name: 'Fantasy' }
		const b = { type: 'tag' as const, name: 'Favorite' }
		const c = { type: 'author' as const, name: 'X' }
		store.setTermState(a, 'include')
		store.setTermState(b, 'include')
		store.setTermState(c, 'exclude')
		store.setMatch('any')
		store.setStatus('unread')
		expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({
			include: [a, b],
			exclude: [c],
			match: 'any',
			status: 'unread',
		}))
		store.onlyTerm(b)
		expect(store.filters.include).toEqual([b])
		expect(store.filters.exclude).toEqual([])
		store.resetFilters()
		expect(store.hasFilters).toBe(false)
	})

	it('round-trips filter state through the URL query', () => {
		const state = queryToState({
			include: ['genre:Sci-Fi: Space', 'bogus', 'tag:x'],
			exclude: 'author:Y',
			match: 'any',
			q: 'dune',
			status: 'reading',
			sort: 'added',
			order: 'asc',
		})
		expect(state.filters.include).toEqual([{ type: 'genre', name: 'Sci-Fi: Space' }, { type: 'tag', name: 'x' }])
		expect(state.filters.exclude).toEqual([{ type: 'author', name: 'Y' }])
		expect(state.filters.match).toBe('any')
		expect(state.sort).toBe('added')
		expect(state.order).toBe('asc')
		const q = stateToQuery(state.filters, state.sort, state.order)
		expect(queryToState(q)).toEqual(state)
		expect(stateToQuery(queryToState({}).filters, 'title', 'asc')).toEqual({})
	})

	it('saves book tags optimistically and rolls back on failure', async () => {
		const store = useLibraryStore()
		mocked.getFacets.mockResolvedValue({ genres: [], tags: [], authors: [], series: [], formats: [], missing: { genre: 0, tag: 0, author: 0, series: 0, description: 0, cover: 0, language: 0 } })
		await store.reload()
		mocked.patchMetadata.mockImplementationOnce(() => Promise.resolve({ book: book(1, { tags: ['a'] }), warnings: ['app only'] }))
		const p = store.saveBookTags(1, { genres: [], tags: ['a'] })
		expect(store.books[0].tags).toEqual(['a'])
		expect(await p).toEqual({ warnings: ['app only'], writeQueued: false })
		mocked.patchMetadata.mockRejectedValueOnce(new Error('nope'))
		await expect(store.saveBookTags(1, { genres: ['G'], tags: [] })).rejects.toThrow('nope')
		expect(store.books[0].tags).toEqual(['a'])
		expect(store.books[0].genres).toEqual([])
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
		mocked.getFacets.mockResolvedValue({ genres: [], tags: [], authors: [], series: [], formats: [], missing: { genre: 0, tag: 0, author: 0, series: 0, description: 0, cover: 0, language: 0 } })
		const res = await store.bulkTags({ addGenres: ['X'], removeGenres: [], addTags: [], removeTags: [] })
		expect(res.updated).toBe(1)
		expect(mocked.bulkTags).toHaveBeenCalledWith(expect.objectContaining({ fileIds: [2], addGenres: ['X'] }))
		store.setSelectMode(false)
		expect(store.selectedIds).toEqual([])
	})

	it('orders the selection like the list and keeps it after a bulk metadata edit', async () => {
		const store = useLibraryStore()
		mocked.getFacets.mockResolvedValue({ genres: [], tags: [], authors: [], series: [], formats: [], missing: { genre: 0, tag: 0, author: 0, series: 0, description: 0, cover: 0, language: 0 } })
		await store.reload()
		const listOrder = store.books.map((b) => b.fileId)
		store.toggleSelected(listOrder[1])
		store.toggleSelected(999)
		store.toggleSelected(listOrder[0])
		expect(store.orderedSelectedIds).toEqual([listOrder[0], listOrder[1], 999])
		mocked.bulkMetadata.mockResolvedValueOnce({ sync: { updated: 3, unchanged: 0, failed: [], writeQueued: false } })
		const res = await store.bulkMetadata({ fileIds: store.orderedSelectedIds, publisher: { mode: 'clear' } })
		expect(res.updated).toBe(3)
		expect(mocked.bulkMetadata).toHaveBeenCalledWith(expect.objectContaining({ fileIds: [listOrder[0], listOrder[1], 999] }), false)
		expect(store.selectedIds).toHaveLength(3)
	})

	it('polls a bulk metadata task and reports its result', async () => {
		const store = useLibraryStore()
		mocked.getFacets.mockResolvedValue({ genres: [], tags: [], authors: [], series: [], formats: [], missing: { genre: 0, tag: 0, author: 0, series: 0, description: 0, cover: 0, language: 0 } })
		await store.reload()
		mocked.bulkMetadata.mockResolvedValueOnce({ taskId: 7 })
		vi.mocked(pollTask).mockResolvedValueOnce({
			id: 7,
			fileId: 1,
			type: 'bulk',
			status: 'done',
			progress: 1,
			step: 'Done',
			result: { updated: 80, unchanged: 1, failed: [{ fileId: 4, error: 'x' }], writeQueued: true },
			error: null,
			createdAt: 0,
			updatedAt: 0,
		})
		const res = await store.bulkMetadata({ fileIds: [1], publisher: { mode: 'clear' } })
		expect(res).toEqual({ updated: 80, unchanged: 1, failed: [{ fileId: 4, error: 'x' }], writeQueued: true })
	})

	it('resets an override and applies the book re-read from the file', async () => {
		const store = useLibraryStore()
		mocked.getFacets.mockResolvedValue({ genres: [], tags: [], authors: [], series: [], formats: [], missing: { genre: 0, tag: 0, author: 0, series: 0, description: 0, cover: 0, language: 0 } })
		await store.reload()
		mocked.resetOverrides.mockResolvedValueOnce(book(1, { title: 'From file', overrides: [] }))
		await store.resetOverrides(1, 'title')
		expect(mocked.resetOverrides).toHaveBeenCalledWith(1, 'title')
		expect(store.books[0].title).toBe('From file')
	})

	it('embeds metadata into the book file right away for small books', async () => {
		const store = useLibraryStore()
		mocked.getFacets.mockResolvedValue({ genres: [], tags: [], authors: [], series: [], formats: [], missing: { genre: 0, tag: 0, author: 0, series: 0, description: 0, cover: 0, language: 0 } })
		await store.reload()
		mocked.embedMetadata.mockResolvedValueOnce({ sync: { book: book(1, { title: 'Embedded' }), warnings: [], written: true } })
		expect(await store.embedMetadata(1)).toEqual({ written: true, warnings: [] })
		expect(mocked.embedMetadata).toHaveBeenCalledWith(1, false)
		expect(store.books[0].title).toBe('Embedded')
	})

	it('embeds large books through a server task', async () => {
		const store = useLibraryStore()
		mocked.getFacets.mockResolvedValue({ genres: [], tags: [], authors: [], series: [], formats: [], missing: { genre: 0, tag: 0, author: 0, series: 0, description: 0, cover: 0, language: 0 } })
		mocked.listBooks.mockResolvedValue({ books: [book(1, { size: 50 * 1024 * 1024 })], total: 1 })
		await store.reload()
		mocked.embedMetadata.mockResolvedValueOnce({ taskId: 9 })
		vi.mocked(pollTask).mockResolvedValueOnce({ id: 9, status: 'done', result: { book: book(1, { title: 'From task' }), written: false, warnings: ['w'] } } as never)
		expect(await store.embedMetadata(1)).toEqual({ written: false, warnings: ['w'] })
		expect(mocked.embedMetadata).toHaveBeenCalledWith(1, true)
		expect(pollTask).toHaveBeenCalledWith(9)
		expect(store.books[0].title).toBe('From task')
	})

	it('shows that saving tags queued a background write', async () => {
		const store = useLibraryStore()
		mocked.getFacets.mockResolvedValue({ genres: [], tags: [], authors: [], series: [], formats: [], missing: { genre: 0, tag: 0, author: 0, series: 0, description: 0, cover: 0, language: 0 } })
		await store.reload()
		mocked.patchMetadata.mockResolvedValueOnce({ book: book(1, { tags: ['a'] }), warnings: [], writeQueued: true })
		expect(await store.saveBookTags(1, { genres: [], tags: ['a'] })).toEqual({ warnings: [], writeQueued: true })
	})
})

describe('library store: shelves, series and hierarchy', () => {
	const series = (name: string, count = 3) => ({ name, count, readCount: 1, coverFileIds: [1], firstFileId: 1, lastAddedAt: 0 })

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.useFakeTimers()
		vi.resetAllMocks()
		mocked.listBooks.mockResolvedValue({ books: [book(1)], total: 1 })
		mocked.listSeries.mockResolvedValue([series('Dune')])
	})
	afterEach(() => {
		vi.useRealTimers()
		localStorage.clear()
	})

	it('moves through grid, volumes and back', async () => {
		const store = useLibraryStore()
		store.setGroupSeries(true)
		await vi.waitFor(() => expect(store.seriesList).toHaveLength(1))
		expect(store.seriesMode).toBe(true)
		expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({ inSeries: 0, sort: 'title' }))
		expect(localStorage.getItem('ebookreader.groupSeries')).toBe('1')

		mocked.listSeries.mockClear()
		store.openSeries('Dune')
		await vi.waitFor(() => expect(mocked.listBooks).toHaveBeenCalledTimes(2))
		expect(store.seriesMode).toBe(false)
		expect(store.urlQuery).toEqual({ volumes: 'Dune' })
		expect(mocked.listSeries).not.toHaveBeenCalled()
		expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({
			include: [{ type: 'series', name: 'Dune' }],
			sort: 'series',
			order: 'asc',
			inSeries: undefined,
		}))

		store.closeSeries()
		await vi.waitFor(() => expect(mocked.listSeries).toHaveBeenCalled())
		expect(store.seriesMode).toBe(true)
		expect(store.urlQuery).toEqual({})
	})

	it('keeps the filters when grouping series and ignores match=any for volumes', async () => {
		const store = useLibraryStore()
		store.setGroupSeries(true)
		store.setTermState({ type: 'genre', name: 'A' }, 'include')
		store.setTermState({ type: 'genre', name: 'B' }, 'include')
		store.setMatch('any')
		await vi.waitFor(() => expect(mocked.listSeries).toHaveBeenLastCalledWith(expect.objectContaining({ match: 'any', sort: 'name' })))
		store.openSeries('Dune')
		await vi.waitFor(() => expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({ sort: 'series' })))
		const last = mocked.listBooks.mock.lastCall?.[0]
		expect(last?.match).toBeUndefined()
		expect(last?.include).toHaveLength(3)
	})

	it('shows a manual shelf as include term in shelf order', () => {
		const store = useLibraryStore()
		store.viewShelf({ id: 5, type: 'manual', query: null })
		expect(store.filters.include).toEqual([shelfTerm(5)])
		expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({ include: [{ type: 'shelf', name: '5' }], sort: 'shelf' }))
		expect(store.activeManualShelfId).toBe(5)
		expect(store.urlQuery).toEqual({ include: ['shelf:5'], sort: 'shelf' })
		// leaving the shelf drops the shelf order
		store.setTermState(shelfTerm(5), null)
		expect(store.sort).toBe('title')
		expect(store.activeManualShelfId).toBeNull()
	})

	it('applies the saved query of a smart shelf', () => {
		const store = useLibraryStore()
		const query = { include: ['tag:Fantasy/*'], exclude: ['author:X'], match: 'any' as const, search: 'dragon', status: 'unread' as const, sort: 'added', order: 'desc' as const }
		store.viewShelf({ id: 7, type: 'smart', query })
		expect(store.filters.include).toEqual([{ type: 'tag', name: 'Fantasy/*' }])
		expect(store.filters.exclude).toEqual([{ type: 'author', name: 'X' }])
		expect(store.sort).toBe('added')
		expect(store.smartShelfId).toBe(7)
		expect(store.urlQuery.smart).toBe('7')
		expect(stateToSmartQuery(store.filters, store.sort, store.order)).toEqual(query)
		store.resetFilters()
		expect(store.smartShelfId).toBeNull()
	})

	it('round-trips shelf, smart shelf, volumes and hierarchy terms through the URL', () => {
		const q = stateToQuery(
			{ include: [{ type: 'tag', name: 'A/*' }, shelfTerm(3)], exclude: [{ type: 'genre', name: 'B/C' }], match: 'all', search: '', status: null },
			'shelf',
			'asc',
			{ smartShelf: 4, drillSeries: 'Dune' },
		)
		expect(q).toEqual({ smart: '4', volumes: 'Dune', include: ['tag:A/*', 'shelf:3'], exclude: ['genre:B/C'], sort: 'shelf' })
		const state = queryToState(q)
		expect(state.smartShelf).toBe(4)
		expect(state.drillSeries).toBe('Dune')
		expect(state.filters.include).toEqual([{ type: 'tag', name: 'A/*' }, { type: 'shelf', name: '3' }])
		expect(state.sort).toBe('shelf')
	})

	it('drops unknown parts of a smart query', () => {
		const state = smartQueryToState({ include: ['bogus:x', 'tag:ok'], exclude: [], match: 'all', search: '', status: null, sort: 'nope', order: 'asc' })
		expect(state.filters.include).toEqual([{ type: 'tag', name: 'ok' }])
		expect(state.sort).toBe('title')
	})
})
