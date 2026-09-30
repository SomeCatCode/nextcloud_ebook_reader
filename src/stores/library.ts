/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type {
	Book,
	BookFormat,
	BookQuery,
	BulkTagResult,
	Facets,
	ReadStatus,
	SortKey,
} from '../types.ts'

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import * as api from '../services/api.ts'

export const PAGE_SIZE = 50
export const SEARCH_DEBOUNCE_MS = 300

export type FilterKey = 'format' | 'genre' | 'tag' | 'author' | 'series' | 'status'

export interface Filters {
	search: string
	format: BookFormat | null
	genre: string | null
	tag: string | null
	author: string | null
	series: string | null
	status: ReadStatus | null
}

/**
 *
 */
function emptyFilters(): Filters {
	return {
		search: '',
		format: null,
		genre: null,
		tag: null,
		author: null,
		series: null,
		status: null,
	}
}

const emptyFacets = (): Facets => ({ genres: [], tags: [], authors: [], series: [], formats: [] })

export const useLibraryStore = defineStore('library', () => {
	const books = ref<Book[]>([])
	const total = ref(0)
	const loading = ref(false)
	const loadingMore = ref(false)
	const loaded = ref(false)
	const error = ref<string | null>(null)

	const filters = ref<Filters>(emptyFilters())
	const sort = ref<SortKey>('title')
	const order = ref<'asc' | 'desc'>('asc')

	const facets = ref<Facets>(emptyFacets())
	const recent = ref<Book[]>([])

	const selectMode = ref(false)
	const selection = ref<Set<number>>(new Set())
	const activeFileId = ref<number | null>(null)

	let requestId = 0
	let searchTimer: ReturnType<typeof setTimeout> | null = null

	const hasMore = computed(() => books.value.length < total.value)
	const hasFilters = computed(() => Object.values(filters.value).some((v) => v !== null && v !== ''))
	const selectedIds = computed(() => [...selection.value])
	const activeBook = computed(() => books.value.find((b) => b.fileId === activeFileId.value)
		?? recent.value.find((b) => b.fileId === activeFileId.value)
		?? null)

	/**
	 * Builds the API query from the current filter state.
	 *
	 * @param offset
	 */
	function buildQuery(offset: number): BookQuery {
		const f = filters.value
		return {
			search: f.search.trim() || undefined,
			format: f.format ?? undefined,
			genre: f.genre ?? undefined,
			tag: f.tag ?? undefined,
			author: f.author ?? undefined,
			series: f.series ?? undefined,
			status: f.status ?? undefined,
			sort: sort.value,
			order: order.value,
			limit: PAGE_SIZE,
			offset,
		}
	}

	/**
	 * (Re)loads the first page.
	 */
	async function reload(): Promise<void> {
		const id = ++requestId
		loading.value = true
		loadingMore.value = false
		error.value = null
		try {
			const res = await api.listBooks(buildQuery(0))
			if (id !== requestId) {
				return
			}
			books.value = res.books
			total.value = res.total
			loaded.value = true
		} catch (e) {
			if (id === requestId) {
				error.value = e instanceof Error ? e.message : String(e)
			}
		} finally {
			if (id === requestId) {
				loading.value = false
			}
		}
	}

	/**
	 * Loads the next page (infinite scroll).
	 */
	async function loadMore(): Promise<void> {
		if (loading.value || loadingMore.value || !hasMore.value) {
			return
		}
		const id = requestId
		loadingMore.value = true
		try {
			const res = await api.listBooks(buildQuery(books.value.length))
			if (id !== requestId) {
				return
			}
			const known = new Set(books.value.map((b) => b.fileId))
			books.value = [...books.value, ...res.books.filter((b) => !known.has(b.fileId))]
			total.value = res.total
		} catch (e) {
			if (id === requestId) {
				error.value = e instanceof Error ? e.message : String(e)
			}
		} finally {
			if (id === requestId) {
				loadingMore.value = false
			}
		}
	}

	/**
	 *
	 */
	async function loadFacets(): Promise<void> {
		try {
			facets.value = await api.getFacets()
		} catch {
			// navigation lists stay as they are
		}
	}

	/**
	 *
	 */
	async function loadRecent(): Promise<void> {
		try {
			recent.value = (await api.recentBooks(10)).books
		} catch {
			recent.value = []
		}
	}

	/**
	 * Initial load of everything the library view needs.
	 */
	async function init(): Promise<void> {
		await Promise.all([reload(), loadFacets(), loadRecent()])
	}

	/**
	 * Sets a search string; the reload is debounced.
	 *
	 * @param text
	 */
	function setSearch(text: string): void {
		filters.value.search = text
		if (searchTimer !== null) {
			clearTimeout(searchTimer)
		}
		searchTimer = setTimeout(() => {
			searchTimer = null
			void reload()
		}, SEARCH_DEBOUNCE_MS)
	}

	/**
	 * Sets (or clears with null) one facet filter and reloads.
	 *
	 * @param key
	 * @param value
	 */
	function setFilter<K extends FilterKey>(key: K, value: Filters[K]): void {
		filters.value[key] = value
		clearSelection()
		void reload()
	}

	/**
	 * Toggles a facet filter: clicking the active value clears it.
	 *
	 * @param key
	 * @param value
	 */
	function toggleFilter<K extends FilterKey>(key: K, value: NonNullable<Filters[K]>): void {
		setFilter(key, (filters.value[key] === value ? null : value) as Filters[K])
	}

	/**
	 * Clears every filter including the search.
	 */
	function resetFilters(): void {
		if (searchTimer !== null) {
			clearTimeout(searchTimer)
			searchTimer = null
		}
		filters.value = emptyFilters()
		clearSelection()
		void reload()
	}

	/**
	 *
	 * @param key
	 * @param newOrder
	 */
	function setSort(key: SortKey, newOrder?: 'asc' | 'desc'): void {
		sort.value = key
		order.value = newOrder ?? (['added', 'read', 'rating'].includes(key) ? 'desc' : 'asc')
		void reload()
	}

	/**
	 *
	 */
	function toggleOrder(): void {
		order.value = order.value === 'asc' ? 'desc' : 'asc'
		void reload()
	}

	// ---- selection ----------------------------------------------------

	/**
	 *
	 * @param fileId
	 */
	function toggleSelected(fileId: number): void {
		const next = new Set(selection.value)
		if (next.has(fileId)) {
			next.delete(fileId)
		} else {
			next.add(fileId)
		}
		selection.value = next
	}

	/**
	 *
	 */
	function selectAllLoaded(): void {
		selection.value = new Set(books.value.map((b) => b.fileId))
	}

	/**
	 *
	 */
	function clearSelection(): void {
		selection.value = new Set()
	}

	/**
	 *
	 * @param on
	 */
	function setSelectMode(on: boolean): void {
		selectMode.value = on
		if (!on) {
			clearSelection()
		}
	}

	// ---- book updates -------------------------------------------------

	/**
	 * Replaces a book in all lists with a fresh server copy.
	 *
	 * @param book
	 */
	function applyBook(book: Book): void {
		books.value = books.value.map((b) => (b.fileId === book.fileId ? book : b))
		recent.value = recent.value.map((b) => (b.fileId === book.fileId ? book : b))
	}

	/**
	 * Optimistic update of rating and/or read status; rolls back and rethrows on failure.
	 *
	 * @param fileId
	 * @param patch
	 * @param patch.rating
	 * @param patch.readStatus
	 */
	async function updateAppData(fileId: number, patch: { rating?: number | null, readStatus?: ReadStatus }): Promise<void> {
		const previous = [...books.value, ...recent.value].find((b) => b.fileId === fileId)
		if (!previous) {
			return
		}
		const snapshot = { ...previous }
		applyBook({ ...previous, ...patch })
		try {
			const fresh = await api.patchAppData(fileId, patch)
			applyBook(fresh)
		} catch (e) {
			applyBook(snapshot)
			throw e
		}
	}

	/**
	 *
	 * @param fileId
	 * @param rating
	 */
	function setRating(fileId: number, rating: number | null): Promise<void> {
		return updateAppData(fileId, { rating })
	}

	/**
	 *
	 * @param fileId
	 * @param readStatus
	 */
	function setReadStatus(fileId: number, readStatus: ReadStatus): Promise<void> {
		return updateAppData(fileId, { readStatus })
	}

	/**
	 * Applies genre/tag changes to the selected books, then refreshes list and facets.
	 *
	 * @param changes
	 * @param changes.addGenres
	 * @param changes.removeGenres
	 * @param changes.addTags
	 * @param changes.removeTags
	 */
	async function bulkTags(changes: { addGenres: string[], removeGenres: string[], addTags: string[], removeTags: string[] }): Promise<BulkTagResult> {
		const result = await api.bulkTags({ fileIds: selectedIds.value, ...changes })
		await Promise.all([reload(), loadFacets()])
		return result
	}

	/**
	 * Opens the details sidebar for a book (null closes it).
	 *
	 * @param fileId
	 */
	function setActive(fileId: number | null): void {
		activeFileId.value = fileId
	}

	return {
		books,
		total,
		loading,
		loadingMore,
		loaded,
		error,
		filters,
		sort,
		order,
		facets,
		recent,
		selectMode,
		selection,
		activeFileId,
		hasMore,
		hasFilters,
		selectedIds,
		activeBook,
		reload,
		loadMore,
		loadFacets,
		loadRecent,
		init,
		setSearch,
		setFilter,
		toggleFilter,
		resetFilters,
		setSort,
		toggleOrder,
		toggleSelected,
		selectAllLoaded,
		clearSelection,
		setSelectMode,
		applyBook,
		setRating,
		setReadStatus,
		bulkTags,
		setActive,
	}
})
