import type { OptimizeOptions } from '../convert/optimize.ts'
/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type {
	AgeRating,
	AppDataPatch,
	Book,
	BookList,
	BookQuery,
	BulkAppDataRequest,
	BulkAppDataResult,
	BulkMetadataRequest,
	BulkMetadataResult,
	BulkTagResult,
	Facets,
	FilterTerm,
	FilterType,
	MatchMode,
	MetadataOverrideField,
	ReadStatus,
	SeriesEntry,
	SeriesQuery,
	SharedFilter,
	SmartQuery,
	SortKey,
	Task,
	UpNext,
} from '../types.ts'

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { isCompletionTerm, normalizeAgeTerm } from '../components/library/bookFlags.ts'
import { orderSelection } from '../components/library/bulkEdit.ts'
import { EMBED_SYNC_MAX_BYTES } from '../components/library/metadataStorage.ts'
import { emptyMissing, isMissingField } from '../components/library/missing.ts'
import { optimizeBooks } from '../convert/convertApi.ts'
import { splitOptimizable } from '../convert/optimize.ts'
import * as api from '../services/api.ts'
import { pollTask } from '../services/tasks.ts'
import { useShelvesStore } from './shelves.ts'

export const PAGE_SIZE = 50
export const SEARCH_DEBOUNCE_MS = 300

export type TermState = 'include' | 'exclude' | null

export interface Filters {
	include: FilterTerm[]
	exclude: FilterTerm[]
	match: MatchMode
	search: string
	status: ReadStatus | null
}

const FILTER_TYPES: FilterType[] = ['genre', 'tag', 'author', 'series', 'format', 'shelf', 'missing', 'completion', 'age']
const SORT_KEYS: SortKey[] = ['title', 'author', 'series', 'rating', 'added', 'read', 'shelf']
const HIDE_FINISHED_KEY = 'ebookreader.hideFinished'
const STATUSES: ReadStatus[] = ['unread', 'reading', 'finished']
const VIEWS: LibraryView[] = ['all', 'series', 'shared', 'folders']
const SHARED_FILTERS: SharedFilter[] = ['any', 'incoming', 'outgoing']

/** What the main area shows: books (with filters), all series, shared books, or the folder structure */
export type LibraryView = 'all' | 'series' | 'shared' | 'folders'

/** View settings that belong to the shared and folder views (see LibraryState) */
export interface ViewState {
	view: LibraryView
	/** which shared books the shared view lists */
	shared: SharedFilter
	/** folder shown in the folder view (path relative to the home); null = top level */
	folder: string | null
	/** folder view: also list the books of subfolders */
	folderRecursive: boolean
}

/**
 *
 */
function emptyFilters(): Filters {
	return { include: [], exclude: [], match: 'all', search: '', status: null }
}

/**
 * @param sort
 */
function defaultOrder(sort: SortKey): 'asc' | 'desc' {
	return ['added', 'read', 'rating'].includes(sort) ? 'desc' : 'asc'
}

/**
 * @param a
 * @param b
 */
export function sameTerm(a: FilterTerm, b: FilterTerm): boolean {
	return a.type === b.type && a.name === b.name
}

/**
 * @param term
 */
export function termToString(term: FilterTerm): string {
	return `${term.type}:${term.name}`
}

/**
 * @param raw
 */
export function parseTerm(raw: string): FilterTerm | null {
	const i = raw.indexOf(':')
	if (i < 1) {
		return null
	}
	const type = raw.slice(0, i) as FilterType
	let name = raw.slice(i + 1)
	if (!FILTER_TYPES.includes(type) || name === '') {
		return null
	}
	if (type === 'missing' && !isMissingField(name)) {
		return null
	}
	if (type === 'completion' && !isCompletionTerm(name)) {
		return null
	}
	if (type === 'age') {
		const normalized = normalizeAgeTerm(name)
		if (normalized === null) {
			return null
		}
		name = normalized
	}
	return { type, name }
}

/**
 * Serialises filter and sort state into URL query parameters; defaults are omitted.
 *
 * @param f
 * @param sort
 * @param order
 * @param extra
 * @param extra.smartShelf
 * @param extra.drillSeries
 * @param extra.view
 * @param extra.shared
 * @param extra.folder
 * @param extra.folderRecursive
 */
export function stateToQuery(f: Filters, sort: SortKey, order: 'asc' | 'desc', extra: { smartShelf?: number | null, drillSeries?: string | null } & Partial<ViewState> = {}): Record<string, string | string[]> {
	const q: Record<string, string | string[]> = {}
	if (extra.view && extra.view !== 'all') {
		q.view = extra.view
		if (extra.view === 'shared' && extra.shared && extra.shared !== 'any') {
			q.shared = extra.shared
		}
		if (extra.view === 'folders' && extra.folder) {
			q.folder = extra.folder
			if (extra.folderRecursive) {
				q.recursive = '1'
			}
		}
	}
	if (extra.smartShelf) {
		q.smart = String(extra.smartShelf)
	}
	if (extra.drillSeries) {
		q.volumes = extra.drillSeries
	}
	if (f.include.length) {
		q.include = f.include.map(termToString)
	}
	if (f.exclude.length) {
		q.exclude = f.exclude.map(termToString)
	}
	if (f.match === 'any') {
		q.match = 'any'
	}
	if (f.search.trim()) {
		q.q = f.search.trim()
	}
	if (f.status) {
		q.status = f.status
	}
	if (sort !== 'title') {
		q.sort = sort
	}
	if (order !== defaultOrder(sort)) {
		q.order = order
	}
	return q
}

/**
 * Parses URL query parameters (as given by vue-router) into filter and sort state.
 *
 * @param query
 */
export function queryToState(query: Record<string, unknown>): LibraryState {
	const list = (v: unknown): string[] => {
		const arr = Array.isArray(v) ? v : (v === undefined || v === null ? [] : [v])
		return arr.filter((x): x is string => typeof x === 'string')
	}
	const first = (v: unknown): string => list(v)[0] ?? ''
	const terms = (v: unknown): FilterTerm[] => list(v).map(parseTerm).filter((x): x is FilterTerm => x !== null)
	const filters = emptyFilters()
	filters.include = terms(query.include)
	filters.exclude = terms(query.exclude)
	filters.match = first(query.match) === 'any' ? 'any' : 'all'
	filters.search = first(query.q)
	const status = first(query.status) as ReadStatus
	filters.status = STATUSES.includes(status) ? status : null
	const sortRaw = first(query.sort) as SortKey
	const sort = SORT_KEYS.includes(sortRaw) ? sortRaw : 'title'
	const orderRaw = first(query.order)
	const order = orderRaw === 'asc' || orderRaw === 'desc' ? orderRaw : defaultOrder(sort)
	const smart = Number.parseInt(first(query.smart), 10)
	const drillSeries = first(query.volumes) || null
	const viewRaw = first(query.view) as LibraryView
	// an old link with only "volumes" opens the series view
	const view: LibraryView = VIEWS.includes(viewRaw) ? viewRaw : (drillSeries ? 'series' : 'all')
	const sharedRaw = first(query.shared) as SharedFilter
	const folder = first(query.folder) || null
	return {
		filters,
		sort,
		order,
		smartShelf: Number.isInteger(smart) && smart > 0 ? smart : null,
		drillSeries,
		view,
		shared: SHARED_FILTERS.includes(sharedRaw) ? sharedRaw : 'any',
		folder: view === 'folders' ? folder : null,
		folderRecursive: view === 'folders' && folder !== null && first(query.recursive) === '1',
	}
}

/**
 * Filter state as the saved query of a smart shelf.
 *
 * @param f
 * @param sort
 * @param order
 */
export function stateToSmartQuery(f: Filters, sort: SortKey, order: 'asc' | 'desc'): SmartQuery {
	return {
		include: f.include.map(termToString),
		exclude: f.exclude.map(termToString),
		match: f.match,
		search: f.search.trim(),
		status: f.status,
		sort,
		order,
	}
}

/**
 * Filter state from the saved query of a smart shelf (unknown parts are dropped).
 *
 * @param q
 */
export function smartQueryToState(q: SmartQuery): { filters: Filters, sort: SortKey, order: 'asc' | 'desc' } {
	const filters = emptyFilters()
	filters.include = (q.include ?? []).map(parseTerm).filter((x): x is FilterTerm => x !== null)
	filters.exclude = (q.exclude ?? []).map(parseTerm).filter((x): x is FilterTerm => x !== null)
	filters.match = q.match === 'any' ? 'any' : 'all'
	filters.search = q.search ?? ''
	filters.status = q.status && STATUSES.includes(q.status) ? q.status : null
	const sort = SORT_KEYS.includes(q.sort as SortKey) ? q.sort as SortKey : 'title'
	const order = q.order === 'asc' || q.order === 'desc' ? q.order : defaultOrder(sort)
	return { filters, sort, order }
}

/**
 * The filter term of a (manual) shelf.
 *
 * @param id
 */
export function shelfTerm(id: number): FilterTerm {
	return { type: 'shelf', name: String(id) }
}

export interface LibraryState extends ViewState {
	filters: Filters
	sort: SortKey
	order: 'asc' | 'desc'
	/** smart shelf whose query is shown */
	smartShelf: number | null
	/** series whose volumes are shown instead of the series cards */
	drillSeries: string | null
}

/**
 * "Hide finished books" view option; on unless the viewer turned it off.
 */
function readHideFinished(): boolean {
	try {
		return localStorage.getItem(HIDE_FINISHED_KEY) !== '0'
	} catch {
		return true
	}
}

/**
 * @param on
 */
function storeHideFinished(on: boolean): void {
	try {
		localStorage.setItem(HIDE_FINISHED_KEY, on ? '1' : '0')
	} catch {
		// ignore
	}
}

const emptyFacets = (): Facets => ({ genres: [], tags: [], authors: [], series: [], formats: [], missing: emptyMissing(), completion: [], ageRatings: [] })

/**
 * Progress the server stores for a read status set by hand (optimistic copy):
 * finished = 100 %, unread = 0 % (no stored progress stays none), reading keeps it.
 *
 * @param book
 * @param status
 */
export function progressForStatus(book: Book, status: ReadStatus): Book['progress'] {
	if (status === 'reading' || (status === 'unread' && !book.progress)) {
		return book.progress
	}
	const percentage = status === 'finished' ? 1 : 0
	const now = Date.now()
	return {
		fileId: book.fileId,
		locator: status === 'finished'
			? { href: '', locations: { totalProgression: 1 } }
			: { href: '', locations: { position: 1, totalProgression: 0 } },
		percentage,
		device: null,
		clientUpdatedAt: now,
		updatedAt: now,
	}
}

/**
 * Optimistic copy of a book after an app-data patch (see updateAppData).
 *
 * @param book
 * @param patch
 */
export function optimisticAppData(book: Book, patch: AppDataPatch): Book {
	const out: Book = { ...book }
	if ('rating' in patch) {
		out.rating = patch.rating ?? null
	}
	if (patch.readStatus) {
		out.readStatus = patch.readStatus
		out.progress = progressForStatus(book, patch.readStatus)
	}
	if ('completion' in patch) {
		out.completion = patch.completion ?? null
	}
	if ('ageRating' in patch) {
		out.ageRating = patch.ageRating ?? null
		out.ageRatingManual = true
	}
	return out
}

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

	/** what the main area shows (not a filter: the filters apply within the view) */
	const view = ref<LibraryView>('all')
	/** shared view: which shared books are listed */
	const sharedFilter = ref<SharedFilter>('any')
	/** folder view: the open folder (null = top level, where only folders are listed) */
	const folderPath = ref<string | null>(null)
	const folderRecursive = ref(false)
	/** view option, not a filter: not in the URL, not saved with smart shelves, ignored when a status filter is set */
	const hideFinished = ref(readHideFinished())
	/** series whose volumes are shown (back button returns to the cards) */
	const drillSeries = ref<string | null>(null)
	const seriesList = ref<SeriesEntry[]>([])
	/** smart shelf whose saved query is the base of the current filters */
	const smartShelfId = ref<number | null>(null)
	const recent = ref<Book[]>([])
	/** next volumes of series whose latest read volume is finished (shown with "continue reading") */
	const upNext = ref<UpNext[]>([])

	const selectMode = ref(false)
	const selection = ref<Set<number>>(new Set())
	const activeFileId = ref<number | null>(null)

	let requestId = 0
	let searchTimer: ReturnType<typeof setTimeout> | null = null

	const hasMore = computed(() => books.value.length < total.value)
	const hasFilters = computed(() => filters.value.include.length > 0
		|| filters.value.exclude.length > 0
		|| filters.value.status !== null
		|| filters.value.search !== '')
	/** the series view shows the series cards (a drilled-in series shows its volumes as books) */
	const seriesMode = computed(() => view.value === 'series' && drillSeries.value === null)
	/** the folder view without an open folder only lists folders, no books */
	const foldersRoot = computed(() => view.value === 'folders' && folderPath.value === null)
	const urlQuery = computed(() => stateToQuery(filters.value, sort.value, order.value, {
		smartShelf: smartShelfId.value,
		drillSeries: drillSeries.value,
		view: view.value,
		shared: sharedFilter.value,
		folder: folderPath.value,
		folderRecursive: folderRecursive.value,
	}))
	const smartShelf = computed(() => (smartShelfId.value === null
		? null
		: useShelvesStore().shelves.find((s) => s.id === smartShelfId.value && s.type === 'smart') ?? null))
	/** the filters differ from the saved query of the shown smart shelf */
	const smartShelfDirty = computed(() => {
		const shelf = smartShelf.value
		if (!shelf?.query) {
			return false
		}
		const saved = smartQueryToState(shelf.query)
		return JSON.stringify(stateToSmartQuery(saved.filters, saved.sort, saved.order))
			!== JSON.stringify(stateToSmartQuery(filters.value, sort.value, order.value))
	})
	/** nothing to show (no series cards and no books) */
	const isEmpty = computed(() => (seriesMode.value ? seriesList.value.length === 0 : books.value.length === 0))
	const selectedIds = computed(() => [...selection.value])
	/** selected ids in the order of the loaded list (selected books that are not loaded go last) */
	const orderedSelectedIds = computed(() => orderSelection(books.value.map((b) => b.fileId), selectedIds.value))
	const activeBook = computed(() => books.value.find((b) => b.fileId === activeFileId.value)
		?? recent.value.find((b) => b.fileId === activeFileId.value)
		?? upNext.value.find((u) => u.book.fileId === activeFileId.value)?.book
		?? null)

	/**
	 * Builds the API query from the current filter state.
	 *
	 * @param offset
	 */
	function buildQuery(offset: number): BookQuery {
		const f = filters.value
		const drill = drillSeries.value
		// the series term is ANDed with the filters, so "match any" is ignored while showing volumes
		const include = drill === null ? f.include : [...f.include, { type: 'series' as const, name: drill }]
		return {
			search: f.search.trim() || undefined,
			include: include.length ? include : undefined,
			exclude: f.exclude.length ? f.exclude : undefined,
			match: include.length > 1 && drill === null ? f.match : undefined,
			status: f.status ?? undefined,
			// a folder listing is complete: finished books are not hidden there
			hideFinished: f.status === null && hideFinished.value && view.value !== 'folders' ? 1 : undefined,
			sort: drill === null ? sort.value : 'series',
			order: drill === null ? order.value : 'asc',
			shared: view.value === 'shared' ? sharedFilter.value : undefined,
			folder: view.value === 'folders' ? folderPath.value ?? undefined : undefined,
			folderRecursive: view.value === 'folders' && folderPath.value !== null && folderRecursive.value ? 1 : undefined,
			limit: PAGE_SIZE,
			offset,
		}
	}

	/**
	 * Series cards use the current filters; they are sorted by name or by the date added.
	 */
	function buildSeriesQuery(): SeriesQuery {
		const f = filters.value
		const bySort = sort.value === 'added' || sort.value === 'title'
		return {
			search: f.search.trim() || undefined,
			include: f.include.length ? f.include : undefined,
			exclude: f.exclude.length ? f.exclude : undefined,
			match: f.include.length > 1 ? f.match : undefined,
			status: f.status ?? undefined,
			hideFinished: f.status === null && hideFinished.value ? 1 : undefined,
			sort: sort.value === 'added' ? 'added' : 'name',
			order: bySort ? order.value : 'asc',
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
			const [res, series] = await Promise.all([
				seriesMode.value || foldersRoot.value ? Promise.resolve<BookList>({ books: [], total: 0 }) : api.listBooks(buildQuery(0)),
				seriesMode.value ? api.listSeries(buildSeriesQuery()) : Promise.resolve<SeriesEntry[]>([]),
			])
			if (id !== requestId) {
				return
			}
			books.value = res.books
			total.value = res.total
			seriesList.value = series
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
			const res = await api.recentBooks(10)
			recent.value = res.books
			upNext.value = res.upNext ?? []
		} catch {
			recent.value = []
			upNext.value = []
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
	 * Current state of a term: included, excluded or off.
	 *
	 * @param term
	 */
	function termState(term: FilterTerm): TermState {
		if (filters.value.include.some((x) => sameTerm(x, term))) {
			return 'include'
		}
		if (filters.value.exclude.some((x) => sameTerm(x, term))) {
			return 'exclude'
		}
		return null
	}

	/**
	 * Sets a term to include, exclude or off (null) and reloads.
	 *
	 * @param term
	 * @param state
	 */
	function setTermState(term: FilterTerm, state: TermState): void {
		const f = filters.value
		f.include = f.include.filter((x) => !sameTerm(x, term))
		f.exclude = f.exclude.filter((x) => !sameTerm(x, term))
		if (state === 'include') {
			f.include = [...f.include, term]
		} else if (state === 'exclude') {
			f.exclude = [...f.exclude, term]
		}
		fixShelfSort()
		clearSelection()
		void reload()
	}

	/**
	 * "Shelf order" only makes sense while a shelf is included.
	 */
	function fixShelfSort(): void {
		if (sort.value === 'shelf' && !filters.value.include.some((x) => x.type === 'shelf')) {
			sort.value = 'title'
			order.value = 'asc'
		}
	}

	/**
	 * Tri-state toggle: off, include, exclude, off.
	 *
	 * @param term
	 */
	function cycleTerm(term: FilterTerm): void {
		const current = termState(term)
		setTermState(term, current === null ? 'include' : (current === 'include' ? 'exclude' : null))
	}

	/**
	 * "Only this": the term becomes the single include and excludes are dropped.
	 *
	 * @param term
	 */
	function onlyTerm(term: FilterTerm): void {
		filters.value.include = [term]
		filters.value.exclude = []
		fixShelfSort()
		clearSelection()
		void reload()
	}

	/**
	 * @param mode
	 */
	function setMatch(mode: MatchMode): void {
		filters.value.match = mode
		clearSelection()
		void reload()
	}

	/**
	 * @param status
	 */
	function setStatus(status: ReadStatus | null): void {
		filters.value.status = status
		clearSelection()
		void reload()
	}

	/**
	 * Replaces filter and sort state (e.g. from the URL) without reloading.
	 *
	 * @param state
	 * @param state.filters
	 * @param state.sort
	 * @param state.order
	 * @param state.smartShelf
	 * @param state.drillSeries
	 * @param state.view
	 * @param state.shared
	 * @param state.folder
	 * @param state.folderRecursive
	 */
	function applyState(state: { filters: Filters, sort: SortKey, order: 'asc' | 'desc', smartShelf?: number | null, drillSeries?: string | null } & Partial<ViewState>): void {
		filters.value = state.filters
		sort.value = state.sort
		order.value = state.order
		smartShelfId.value = state.smartShelf ?? null
		drillSeries.value = state.drillSeries ?? null
		view.value = state.view ?? 'all'
		sharedFilter.value = state.shared ?? 'any'
		folderPath.value = state.folder ?? null
		folderRecursive.value = state.folderRecursive ?? false
	}

	/**
	 * Shows or hides finished books (remembered in localStorage).
	 *
	 * @param on
	 */
	function setHideFinished(on: boolean): void {
		hideFinished.value = on
		storeHideFinished(on)
		clearSelection()
		void reload()
	}

	// ---- views --------------------------------------------------------

	/**
	 * Back to the plain book list: no filters, no shelf, no series, no shared or folder view (no reload).
	 *
	 * @param next
	 */
	function resetView(next: LibraryView): void {
		clearSearchTimer()
		view.value = next
		filters.value = emptyFilters()
		smartShelfId.value = null
		drillSeries.value = null
		sharedFilter.value = 'any'
		folderPath.value = null
		folderRecursive.value = false
		fixShelfSort()
		clearSelection()
	}

	/**
	 * Navigation: shows a view (all books, all series, shared books, folders) from scratch.
	 *
	 * @param next
	 */
	function showView(next: LibraryView): void {
		resetView(next)
		void reload()
	}

	/**
	 * Navigation: Unread / Continue reading / Finished show the books with that status.
	 *
	 * @param status
	 */
	function showStatus(status: ReadStatus | null): void {
		if (view.value !== 'all' || drillSeries.value !== null) {
			resetView('all')
		}
		setStatus(status)
	}

	/**
	 * Shared view: which shared books are listed.
	 *
	 * @param value
	 */
	function setSharedFilter(value: SharedFilter): void {
		sharedFilter.value = value
		clearSelection()
		void reload()
	}

	/**
	 * Folder view: opens a folder (null = top level).
	 *
	 * @param path
	 */
	function openFolder(path: string | null): void {
		view.value = 'folders'
		folderPath.value = path
		drillSeries.value = null
		clearSelection()
		void reload()
	}

	/**
	 * Folder view: also list the books of subfolders.
	 *
	 * @param on
	 */
	function setFolderRecursive(on: boolean): void {
		folderRecursive.value = on
		clearSelection()
		void reload()
	}

	/**
	 * Updates the share counter of a series card after (un)sharing.
	 *
	 * @param name
	 * @param sharedWith
	 */
	function setSeriesSharedWith(name: string, sharedWith: number): void {
		seriesList.value = seriesList.value.map((s) => (s.name === name ? { ...s, sharedWith } : s))
	}

	/**
	 * Series card clicked: shows the volumes of that series.
	 *
	 * @param name
	 */
	function openSeries(name: string): void {
		drillSeries.value = name
		clearSelection()
		void reload()
	}

	/**
	 * Back button of the volumes view.
	 */
	function closeSeries(): void {
		drillSeries.value = null
		clearSelection()
		void reload()
	}

	// ---- shelves ------------------------------------------------------

	/**
	 * Shows a shelf: a manual one as include filter in shelf order, a smart one by applying its saved query.
	 *
	 * @param shelf
	 * @param shelf.id
	 * @param shelf.type
	 * @param shelf.query
	 */
	function viewShelf(shelf: { id: number, type: 'manual' | 'smart', query: SmartQuery | null }): void {
		clearSearchTimer()
		drillSeries.value = null
		view.value = 'all'
		folderPath.value = null
		if (shelf.type === 'smart' && shelf.query) {
			const state = smartQueryToState(shelf.query)
			filters.value = state.filters
			sort.value = state.sort
			order.value = state.order
			smartShelfId.value = shelf.id
		} else {
			filters.value = { ...emptyFilters(), include: [shelfTerm(shelf.id)] }
			sort.value = 'shelf'
			order.value = 'asc'
			smartShelfId.value = null
		}
		clearSelection()
		void reload()
	}

	/**
	 * Id of the manual shelf that is shown (a shelf include term), if any.
	 */
	const activeManualShelfId = computed<number | null>(() => {
		const term = filters.value.include.find((x) => x.type === 'shelf' && x.name !== String(smartShelfId.value))
		const id = term ? Number.parseInt(term.name, 10) : Number.NaN
		return Number.isInteger(id) ? id : null
	})

	/**
	 * Current filters as the query of a smart shelf.
	 */
	function currentSmartQuery(): SmartQuery {
		return stateToSmartQuery(filters.value, sort.value, order.value)
	}

	/**
	 * Makes a freshly created smart shelf the base of the current filters (so that "Update shelf" works).
	 *
	 * @param id
	 */
	function adoptSmartShelf(id: number): void {
		smartShelfId.value = id
	}

	/**
	 *
	 */
	function clearSearchTimer(): void {
		if (searchTimer !== null) {
			clearTimeout(searchTimer)
			searchTimer = null
		}
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
		smartShelfId.value = null
		drillSeries.value = null
		fixShelfSort()
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
		order.value = newOrder ?? defaultOrder(key)
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
		upNext.value = upNext.value.map((u) => (u.book.fileId === book.fileId ? { ...u, book } : u))
	}

	/**
	 * A loaded copy of a book (library list, continue reading or up next).
	 *
	 * @param fileId
	 */
	function findLoaded(fileId: number): Book | undefined {
		return [...books.value, ...recent.value, ...upNext.value.map((u) => u.book)].find((b) => b.fileId === fileId)
	}

	/**
	 * A finished book is done: it leaves "continue reading" and, with "hide finished" on, the list.
	 * A book marked unread is back at 0 % and leaves "continue reading" as well.
	 *
	 * @param book
	 */
	function dropByStatus(book: Book): void {
		if (book.readStatus !== 'unread') {
			// "up next" only offers volumes that were not started yet
			upNext.value = upNext.value.filter((u) => u.book.fileId !== book.fileId)
		}
		if (book.readStatus === 'unread') {
			recent.value = recent.value.filter((b) => b.fileId !== book.fileId)
			return
		}
		if (book.readStatus !== 'finished') {
			return
		}
		recent.value = recent.value.filter((b) => b.fileId !== book.fileId)
		if (hideFinished.value && filters.value.status === null) {
			const before = books.value.length
			books.value = books.value.filter((b) => b.fileId !== book.fileId)
			total.value = Math.max(0, total.value - (before - books.value.length))
		}
	}

	/**
	 * Optimistic update of app data (rating, read status, completion, age rating); rolls back and rethrows on failure.
	 * The server couples the status with the progress (finished = 100 %, unread = 0 %); the
	 * optimistic copy shows that right away, the server copy (with its progress) replaces it.
	 * Resetting the age rating has no optimistic copy (only the server knows the value from the file).
	 *
	 * @param fileId
	 * @param patch
	 */
	async function updateAppData(fileId: number, patch: AppDataPatch): Promise<void> {
		const previous = findLoaded(fileId)
		if (!previous) {
			return
		}
		const snapshot = { ...previous }
		applyBook(optimisticAppData(previous, patch))
		try {
			const fresh = await api.patchAppData(fileId, patch)
			applyBook(fresh)
			dropByStatus(fresh)
			if (patch.readStatus === 'finished' && fresh.series) {
				// a finished volume brings its successor into "continue reading"
				void loadRecent()
			}
			if ('completion' in patch || 'ageRating' in patch || patch.resetAgeRating) {
				void loadFacets()
			}
		} catch (e) {
			applyBook(snapshot)
			throw e
		}
	}

	/**
	 * @param fileId
	 * @param completion null = unknown
	 */
	function setCompletion(fileId: number, completion: Book['completion']): Promise<void> {
		return updateAppData(fileId, { completion })
	}

	/**
	 * Sets the age rating by hand (null = explicitly none); it survives re-indexing.
	 *
	 * @param fileId
	 * @param ageRating
	 */
	function setAgeRating(fileId: number, ageRating: AgeRating | null): Promise<void> {
		return updateAppData(fileId, { ageRating })
	}

	/**
	 * Uses the age rating from the file again.
	 *
	 * @param fileId
	 */
	function resetAgeRating(fileId: number): Promise<void> {
		return updateAppData(fileId, { resetAgeRating: true })
	}

	/**
	 * Completion status / age rating of the selected books (one request), then refreshes list, facets and
	 * "continue reading". The selection stays.
	 *
	 * @param changes
	 * @param fileIds default: the selection
	 */
	async function bulkAppData(changes: Omit<BulkAppDataRequest, 'fileIds'>, fileIds: number[] = orderedSelectedIds.value): Promise<BulkAppDataResult> {
		const result = await api.bulkAppData({ ...changes, fileIds })
		await Promise.all([reload(), loadFacets(), loadRecent()])
		return result
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
	 * Saves a book's genres and tags (optimistic, rolls back and rethrows on failure).
	 * Returns the server warnings (e.g. stored in the app only) and whether the file is written by a background job.
	 *
	 * @param fileId
	 * @param value
	 * @param value.genres
	 * @param value.tags
	 */
	async function saveBookTags(fileId: number, value: { genres: string[], tags: string[] }): Promise<{ warnings: string[], writeQueued: boolean }> {
		const find = () => [...books.value, ...recent.value].find((b) => b.fileId === fileId)
		const previous = find()
		if (!previous) {
			return { warnings: [], writeQueued: false }
		}
		const snapshot = { genres: previous.genres, tags: previous.tags }
		applyBook({ ...previous, ...value })
		try {
			const res = await api.patchMetadata(fileId, value)
			applyBook(res.book)
			void loadFacets()
			return { warnings: res.warnings ?? [], writeQueued: res.writeQueued === true }
		} catch (e) {
			const current = find()
			if (current) {
				applyBook({ ...current, ...snapshot })
			}
			throw e
		}
	}

	/**
	 * Writes the library metadata of a book into the book file (the sidecar target keeps it out of the file otherwise).
	 * Large files run as a server task. Returns whether the file had to be changed.
	 *
	 * @param fileId
	 */
	async function embedMetadata(fileId: number): Promise<{ written: boolean, warnings: string[] }> {
		const current = [...books.value, ...recent.value].find((b) => b.fileId === fileId)
		const started = await api.embedMetadata(fileId, (current?.size ?? 0) > EMBED_SYNC_MAX_BYTES)
		let result: { book?: Book, written?: boolean, warnings?: string[] }
		if ('taskId' in started) {
			const task = await pollTask(started.taskId)
			result = task.result ?? {}
		} else {
			result = started.sync
		}
		if (result.book) {
			applyBook(result.book)
		}
		return { written: result.written === true, warnings: result.warnings ?? [] }
	}

	/**
	 * Resets "edited in app" overrides of a book (one field or all): the values are read from the file again.
	 *
	 * @param fileId
	 * @param field
	 */
	async function resetOverrides(fileId: number, field?: MetadataOverrideField): Promise<void> {
		const book = await api.resetOverrides(fileId, field)
		applyBook(book)
		void loadFacets()
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
	 * Edits metadata of several books (see BulkMetadataRequest), then refreshes list and facets. The selection stays.
	 * Large requests run as a server task; `onProgress` receives its progress (0..1) and step text.
	 *
	 * @param req
	 * @param onProgress
	 */
	async function bulkMetadata(req: BulkMetadataRequest, onProgress?: (task: Task) => void): Promise<BulkMetadataResult> {
		const started = await api.bulkMetadata(req, false)
		let result: BulkMetadataResult
		if ('taskId' in started) {
			const task = await pollTask(started.taskId, { onUpdate: onProgress })
			result = {
				updated: task.result?.updated ?? 0,
				unchanged: task.result?.unchanged ?? 0,
				failed: task.result?.failed ?? [],
				writeQueued: task.result?.writeQueued === true,
			}
		} else {
			result = started.sync
		}
		await Promise.all([reload(), loadFacets()])
		return result
	}

	/**
	 * Optimizes the images of the selected comics (one server task per book, the tasks run one after another).
	 * Selected books that are not comics are left out. The selection stays.
	 *
	 * @param options
	 * @param deleteOriginal
	 */
	async function optimizeSelected(options: OptimizeOptions, deleteOriginal: boolean): Promise<{ started: number, skipped: number }> {
		const { eligible } = splitOptimizable(books.value, selectedIds.value)
		if (eligible.length === 0) {
			return { started: 0, skipped: 0 }
		}
		const res = await optimizeBooks(eligible, options, deleteOriginal)
		return { started: res.tasks.length, skipped: res.skipped.length }
	}

	/**
	 * Opens the details sidebar for a book (null closes it).
	 *
	 * @param fileId
	 */
	function setActive(fileId: number | null): void {
		activeFileId.value = fileId
	}

	/**
	 * Removes deleted books from the loaded lists, the selection and the details sidebar,
	 * then refreshes the facet counts.
	 *
	 * @param fileIds
	 */
	function removeBooks(fileIds: number[]): void {
		const gone = new Set(fileIds)
		const before = books.value.length
		books.value = books.value.filter((b) => !gone.has(b.fileId))
		total.value = Math.max(0, total.value - (before - books.value.length))
		recent.value = recent.value.filter((b) => !gone.has(b.fileId))
		upNext.value = upNext.value.filter((u) => !gone.has(u.book.fileId) && !gone.has(u.previousFileId))
		const next = new Set(selection.value)
		gone.forEach((id) => next.delete(id))
		selection.value = next
		if (activeFileId.value !== null && gone.has(activeFileId.value)) {
			activeFileId.value = null
		}
		void loadFacets()
	}

	return {
		removeBooks,
		view,
		sharedFilter,
		folderPath,
		folderRecursive,
		foldersRoot,
		showView,
		showStatus,
		setSharedFilter,
		openFolder,
		setFolderRecursive,
		setSeriesSharedWith,
		hideFinished,
		setHideFinished,
		drillSeries,
		seriesList,
		seriesMode,
		isEmpty,
		smartShelfId,
		smartShelf,
		smartShelfDirty,
		activeManualShelfId,
		openSeries,
		closeSeries,
		viewShelf,
		currentSmartQuery,
		adoptSmartShelf,
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
		upNext,
		selectMode,
		selection,
		activeFileId,
		hasMore,
		hasFilters,
		urlQuery,
		selectedIds,
		activeBook,
		reload,
		loadMore,
		loadFacets,
		loadRecent,
		init,
		setSearch,
		termState,
		setTermState,
		cycleTerm,
		onlyTerm,
		setMatch,
		setStatus,
		applyState,
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
		setCompletion,
		setAgeRating,
		resetAgeRating,
		bulkAppData,
		saveBookTags,
		embedMetadata,
		resetOverrides,
		bulkTags,
		bulkMetadata,
		optimizeSelected,
		orderedSelectedIds,
		setActive,
	}
})
