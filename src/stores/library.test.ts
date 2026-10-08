/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Book } from '../types.ts'

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { optimizeBooks } from '../convert/convertApi.ts'
import * as api from '../services/api.ts'
import { pollTask } from '../services/tasks.ts'
import { optimisticAppData, PAGE_SIZE, progressForStatus, queryToState, SEARCH_DEBOUNCE_MS, shelfTerm, smartQueryToState, stateToQuery, stateToSmartQuery, useLibraryStore } from './library.ts'

vi.mock('../services/api.ts', () => ({
	listBooks: vi.fn(),
	getFacets: vi.fn(),
	recentBooks: vi.fn(),
	patchAppData: vi.fn(),
	bulkAppData: vi.fn(),
	bulkTags: vi.fn(),
	bulkMetadata: vi.fn(),
	patchMetadata: vi.fn(),
	resetOverrides: vi.fn(),
	embedMetadata: vi.fn(),
	listSeries: vi.fn(),
	listShelves: vi.fn(),
}))
vi.mock('../services/tasks.ts', () => ({ pollTask: vi.fn() }))
vi.mock('../convert/convertApi.ts', () => ({ optimizeBooks: vi.fn() }))

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
		owner: 'me',
		shared: false,
		overrides: [],
		hasSidecar: false,
		completion: null,
		ageRating: null,
		ageRatingManual: false,
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

	it('hides finished books by default unless a status filter is set', async () => {
		localStorage.clear()
		const store = useLibraryStore()
		await store.reload()
		expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({ hideFinished: 1 }))
		expect(store.hasFilters).toBe(false)
		store.setStatus('finished')
		expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'finished', hideFinished: undefined }))
		store.setStatus(null)
		store.setHideFinished(false)
		expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({ hideFinished: undefined }))
		expect(localStorage.getItem('ebookreader.hideFinished')).toBe('0')
	})

	it('drops a book marked finished from the list and continue reading', async () => {
		localStorage.clear()
		mocked.recentBooks.mockResolvedValue({ books: [book(1, { readStatus: 'reading' })] })
		const store = useLibraryStore()
		await store.reload()
		store.recent = [book(1, { readStatus: 'reading' })]
		mocked.patchAppData.mockResolvedValueOnce(book(1, { readStatus: 'finished' }))
		await store.setReadStatus(1, 'finished')
		expect(store.books.map((b) => b.fileId)).toEqual([2])
		expect(store.recent).toEqual([])
		expect(store.total).toBe(1)
	})

	it('couples the read status with the shown progress', async () => {
		localStorage.clear()
		const store = useLibraryStore()
		await store.reload()
		const half = { fileId: 2, locator: { href: 'a.xhtml', locations: { totalProgression: 0.5 } }, percentage: 0.5, device: null, clientUpdatedAt: 1, updatedAt: 1 }
		store.books = [book(1), book(2, { readStatus: 'reading', progress: half })]
		store.recent = [book(2, { readStatus: 'reading', progress: half })]

		let resolve: (b: Book) => void = () => {}
		mocked.patchAppData.mockImplementationOnce(() => new Promise((r) => {
			resolve = r
		}))
		const p = store.setReadStatus(2, 'unread')
		// optimistic: 0 % right away
		expect(store.books[1].progress?.percentage).toBe(0)
		const server = { ...half, locator: { href: '', locations: { position: 1, totalProgression: 0 } }, percentage: 0, updatedAt: 9 }
		resolve(book(2, { readStatus: 'unread', progress: server }))
		await p
		expect(store.books[1].progress).toEqual(server)
		// back at the start: no longer "continue reading"
		expect(store.recent).toEqual([])

		mocked.patchAppData.mockResolvedValueOnce(book(1, { readStatus: 'finished', progress: { ...server, fileId: 1, percentage: 1 } }))
		const q = store.setReadStatus(1, 'finished')
		expect(store.books[0].progress?.percentage).toBe(1)
		await q
		// hide finished is on by default
		expect(store.books.map((b) => b.fileId)).toEqual([2])
	})

	it('derives the optimistic progress of a status', () => {
		const half = { fileId: 1, locator: { href: 'a' }, percentage: 0.5, device: 'x', clientUpdatedAt: 1, updatedAt: 1 }
		expect(progressForStatus(book(1, { progress: half }), 'reading')).toBe(half)
		expect(progressForStatus(book(1), 'unread')).toBeNull()
		expect(progressForStatus(book(1, { progress: half }), 'unread')?.percentage).toBe(0)
		expect(progressForStatus(book(1), 'finished')).toMatchObject({ fileId: 1, percentage: 1, locator: { href: '', locations: { totalProgression: 1 } } })
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

	it('starts the image optimization only for the selected comics', async () => {
		const store = useLibraryStore()
		mocked.listBooks.mockResolvedValue({ books: [book(1, { format: 'cbz' }), book(2, { format: 'epub' }), book(3, { format: 'cbr' })], total: 3 })
		await store.reload()
		store.toggleSelected(3)
		store.toggleSelected(2)
		store.toggleSelected(1)
		vi.mocked(optimizeBooks).mockResolvedValueOnce({ tasks: [{ fileId: 3, taskId: 1 }], skipped: [{ fileId: 1, error: 'exists', status: 409 }] })
		const res = await store.optimizeSelected({ maxHeight: 1920, pngToJpeg: true }, true)
		expect(res).toEqual({ started: 1, skipped: 1 })
		expect(optimizeBooks).toHaveBeenCalledWith([3, 1], { maxHeight: 1920, pngToJpeg: true }, true)
		expect(store.selectedIds).toHaveLength(3)
	})

	it('does not call the server when no comic is selected', async () => {
		const store = useLibraryStore()
		await store.reload()
		store.toggleSelected(1)
		expect(await store.optimizeSelected({ maxHeight: 1920, pngToJpeg: false }, false)).toEqual({ started: 0, skipped: 0 })
		expect(optimizeBooks).not.toHaveBeenCalled()
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

	it('sets completion and age rating optimistically and refreshes the facets', async () => {
		mocked.getFacets.mockResolvedValue({ genres: [], tags: [], authors: [], series: [], formats: [], missing: { genre: 0, tag: 0, author: 0, series: 0, description: 0, cover: 0, language: 0 } })
		const store = useLibraryStore()
		await store.reload()
		let resolve: (b: Book) => void = () => {}
		mocked.patchAppData.mockImplementationOnce(() => new Promise((r) => {
			resolve = r
		}))
		const p = store.setAgeRating(1, 16)
		expect(store.books[0].ageRating).toBe(16)
		expect(store.books[0].ageRatingManual).toBe(true)
		resolve(book(1, { ageRating: 16, ageRatingManual: true }))
		await p
		expect(mocked.patchAppData).toHaveBeenLastCalledWith(1, { ageRating: 16 })
		expect(mocked.getFacets).toHaveBeenCalled()

		mocked.patchAppData.mockResolvedValueOnce(book(1, { completion: 'ongoing' }))
		await store.setCompletion(1, 'ongoing')
		expect(mocked.patchAppData).toHaveBeenLastCalledWith(1, { completion: 'ongoing' })
		expect(store.books[0].completion).toBe('ongoing')

		mocked.patchAppData.mockRejectedValueOnce(new Error('nope'))
		await expect(store.setCompletion(1, null)).rejects.toThrow('nope')
		expect(store.books[0].completion).toBe('ongoing')

		// reset: no optimistic value, the server copy carries the file's rating
		mocked.patchAppData.mockResolvedValueOnce(book(1, { ageRating: 12, ageRatingManual: false }))
		await store.resetAgeRating(1)
		expect(mocked.patchAppData).toHaveBeenLastCalledWith(1, { resetAgeRating: true })
		expect(store.books[0].ageRating).toBe(12)
		expect(store.books[0].ageRatingManual).toBe(false)
	})

	it('loads "up next" volumes with continue reading and drops a started one', async () => {
		const next = book(7, { series: 'Saga', seriesIndex: 2 })
		mocked.recentBooks.mockResolvedValue({ books: [book(3, { readStatus: 'reading' })], upNext: [{ previousFileId: 6, book: next }] })
		const store = useLibraryStore()
		await store.loadRecent()
		expect(store.upNext.map((u) => u.book.fileId)).toEqual([7])
		store.setActive(7)
		expect(store.activeBook?.fileId).toBe(7)

		mocked.patchAppData.mockResolvedValueOnce(book(7, { readStatus: 'reading', series: 'Saga' }))
		await store.setReadStatus(7, 'reading')
		expect(store.upNext).toEqual([])
	})

	it('reloads continue reading when a volume of a series is marked finished', async () => {
		mocked.recentBooks.mockResolvedValue({ books: [] })
		const store = useLibraryStore()
		store.books = [book(1, { series: 'Saga', readStatus: 'reading' })]
		mocked.patchAppData.mockResolvedValueOnce(book(1, { series: 'Saga', readStatus: 'finished' }))
		await store.setReadStatus(1, 'finished')
		expect(mocked.recentBooks).toHaveBeenCalled()
	})

	it('sends completion and age rating of the selection in one request', async () => {
		mocked.bulkAppData.mockResolvedValueOnce({ updated: 2, unchanged: 0, failed: [] })
		mocked.recentBooks.mockResolvedValue({ books: [] })
		const store = useLibraryStore()
		await store.reload()
		store.setSelectMode(true)
		store.toggleSelected(2)
		store.toggleSelected(1)
		const res = await store.bulkAppData({ completion: 'completed', ageRating: null })
		expect(mocked.bulkAppData).toHaveBeenCalledWith({ completion: 'completed', ageRating: null, fileIds: [1, 2] })
		expect(res.updated).toBe(2)
		expect(store.selectedIds).toHaveLength(2)
	})

	it('accepts completion and age terms from the URL and drops invalid ones', () => {
		const state = queryToState({ include: ['completion:ongoing', 'age:<= 12', 'age:15', 'completion:nope'], exclude: 'age:none' })
		expect(state.filters.include).toEqual([{ type: 'completion', name: 'ongoing' }, { type: 'age', name: '<=12' }])
		expect(state.filters.exclude).toEqual([{ type: 'age', name: 'none' }])
		expect(stateToQuery(state.filters, 'title', 'asc')).toEqual({ include: ['completion:ongoing', 'age:<=12'], exclude: ['age:none'] })
	})

	it('builds the optimistic copy of an app-data patch', () => {
		const b = book(1, { rating: 3, completion: 'ongoing', ageRating: 12 })
		expect(optimisticAppData(b, { completion: null })).toMatchObject({ completion: null, rating: 3, ageRating: 12, ageRatingManual: false })
		expect(optimisticAppData(b, { ageRating: null })).toMatchObject({ ageRating: null, ageRatingManual: true })
		expect(optimisticAppData(b, { resetAgeRating: true })).toEqual(b)
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

	it('moves through the series view, volumes and back', async () => {
		const store = useLibraryStore()
		store.showView('series')
		await vi.waitFor(() => expect(store.seriesList).toHaveLength(1))
		expect(store.seriesMode).toBe(true)
		// the series view lists series only: no book request
		expect(mocked.listBooks).not.toHaveBeenCalled()
		expect(store.urlQuery).toEqual({ view: 'series' })

		mocked.listSeries.mockClear()
		store.openSeries('Dune')
		await vi.waitFor(() => expect(mocked.listBooks).toHaveBeenCalledTimes(1))
		expect(store.seriesMode).toBe(false)
		expect(store.urlQuery).toEqual({ view: 'series', volumes: 'Dune' })
		expect(mocked.listSeries).not.toHaveBeenCalled()
		expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({
			include: [{ type: 'series', name: 'Dune' }],
			sort: 'series',
			order: 'asc',
		}))

		store.closeSeries()
		await vi.waitFor(() => expect(mocked.listSeries).toHaveBeenCalled())
		expect(store.seriesMode).toBe(true)
		expect(store.urlQuery).toEqual({ view: 'series' })
	})

	it('keeps the filters in the series view and ignores match=any for volumes', async () => {
		const store = useLibraryStore()
		store.showView('series')
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

describe('library store: shared and folder views', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.useFakeTimers()
		vi.resetAllMocks()
		mocked.listBooks.mockResolvedValue({ books: [book(1)], total: 1 })
		mocked.listSeries.mockResolvedValue([])
	})
	afterEach(() => {
		vi.useRealTimers()
		localStorage.clear()
	})

	it('lists shared books with the chosen filter and pages through them', async () => {
		const store = useLibraryStore()
		store.showView('shared')
		expect(store.view).toBe('shared')
		expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({ shared: 'any', offset: 0, limit: PAGE_SIZE }))
		await vi.waitFor(() => expect(store.loaded).toBe(true))

		store.setSharedFilter('outgoing')
		expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({ shared: 'outgoing' }))
		expect(store.urlQuery).toEqual({ view: 'shared', shared: 'outgoing' })

		// further pages keep the filter
		mocked.listBooks.mockResolvedValue({ books: Array.from({ length: PAGE_SIZE }, (_, i) => book(100 + i)), total: PAGE_SIZE * 3 })
		store.setSharedFilter('incoming')
		await vi.waitFor(() => expect(store.books).toHaveLength(PAGE_SIZE))
		await store.loadMore()
		expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({ shared: 'incoming', offset: PAGE_SIZE }))
	})

	it('does not send the shared filter outside the shared view', () => {
		const store = useLibraryStore()
		store.showView('all')
		expect(mocked.listBooks.mock.lastCall?.[0]?.shared).toBeUndefined()
		expect(mocked.listBooks.mock.lastCall?.[0]?.folder).toBeUndefined()
	})

	it('shows only folders at the top level and the books of an open folder', async () => {
		const store = useLibraryStore()
		store.showView('folders')
		await vi.waitFor(() => expect(store.loaded).toBe(true))
		expect(store.foldersRoot).toBe(true)
		expect(mocked.listBooks).not.toHaveBeenCalled()

		store.openFolder('/Books/Comics')
		expect(store.foldersRoot).toBe(false)
		expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({ folder: '/Books/Comics', folderRecursive: undefined, hideFinished: undefined }))
		expect(store.urlQuery).toEqual({ view: 'folders', folder: '/Books/Comics' })

		store.setFolderRecursive(true)
		expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({ folder: '/Books/Comics', folderRecursive: 1 }))
		expect(store.urlQuery).toEqual({ view: 'folders', folder: '/Books/Comics', recursive: '1' })

		store.openFolder(null)
		expect(store.foldersRoot).toBe(true)
	})

	it('round-trips view, shared filter and folder through the URL', () => {
		const q = stateToQuery(
			{ include: [], exclude: [], match: 'all', search: '', status: null },
			'title',
			'asc',
			{ view: 'folders', folder: '/Books/Saga', folderRecursive: true },
		)
		expect(q).toEqual({ view: 'folders', folder: '/Books/Saga', recursive: '1' })
		const state = queryToState(q)
		expect(state.view).toBe('folders')
		expect(state.folder).toBe('/Books/Saga')
		expect(state.folderRecursive).toBe(true)

		expect(queryToState({ view: 'shared', shared: 'incoming' })).toMatchObject({ view: 'shared', shared: 'incoming', folder: null })
		// unknown values fall back to the defaults; a folder only counts in the folder view
		expect(queryToState({ view: 'bogus', shared: 'x', folder: '/a' })).toMatchObject({ view: 'all', shared: 'any', folder: null })
		// old links with only a series keep working
		expect(queryToState({ volumes: 'Dune' })).toMatchObject({ view: 'series', drillSeries: 'Dune' })
		// default view and filter are not written to the URL
		expect(stateToQuery({ include: [], exclude: [], match: 'all', search: '', status: null }, 'title', 'asc', { view: 'shared', shared: 'any' })).toEqual({ view: 'shared' })
		expect(stateToQuery({ include: [], exclude: [], match: 'all', search: '', status: null }, 'title', 'asc', { view: 'all' })).toEqual({})
	})

	it('leaves the special views when a shelf or a status is shown', async () => {
		const store = useLibraryStore()
		store.showView('shared')
		store.viewShelf({ id: 3, type: 'manual', query: null })
		expect(store.view).toBe('all')
		store.showView('series')
		store.showStatus('unread')
		expect(store.view).toBe('all')
		expect(store.filters.status).toBe('unread')
		expect(mocked.listBooks).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'unread', shared: undefined }))
	})

	it('updates the share counter of a series card', async () => {
		mocked.listSeries.mockResolvedValue([{ name: 'Dune', count: 3, readCount: 0, coverFileIds: [1], firstFileId: 1, lastAddedAt: 0 }])
		const store = useLibraryStore()
		store.showView('series')
		await vi.waitFor(() => expect(store.seriesList).toHaveLength(1))
		store.setSeriesSharedWith('Dune', 2)
		expect(store.seriesList[0]?.sharedWith).toBe(2)
	})
})
