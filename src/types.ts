/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export type BookFormat = 'epub' | 'mobi' | 'azw3' | 'fb2' | 'fbz' | 'cbz' | 'cbr' | 'cb7' | 'cbt'
export type ReadStatus = 'unread' | 'reading' | 'finished'
/** Whether a book (or comic) is still being continued or finished; null = unknown */
export type Completion = 'ongoing' | 'completed'
/** Age rating "from N years" */
export type AgeRating = 0 | 6 | 12 | 16 | 18

/** Readium-compatible locator. */
export interface Locator {
	href: string
	type?: string
	title?: string
	locations?: {
		progression?: number
		totalProgression?: number
		position?: number
		cfi?: string
	}
}

export interface Progress {
	fileId: number
	locator: Locator
	percentage: number
	device: string | null
	/** ms since epoch */
	clientUpdatedAt: number
	/** ms since epoch (server) */
	updatedAt: number
}

export interface Book {
	fileId: number
	format: BookFormat
	path: string
	size: number
	title: string | null
	authors: string[]
	series: string | null
	seriesIndex: number | null
	/** sanitised HTML */
	description: string | null
	language: string | null
	publisher: string | null
	isbn: string | null
	publishedAt: string | null
	genres: string[]
	tags: string[]
	rating: number | null
	readStatus: ReadStatus
	hasCover: boolean
	coverEtag: string | null
	mtime: number
	addedAt: number
	updatedAt: number
	editable: boolean
	/** false for view-only shares with download disabled: content can not be read in the app */
	downloadable: boolean
	/** Metadata fields edited in the app only (they survive re-indexing of the file) */
	overrides: MetadataOverrideField[]
	/** A hidden sidecar file ".<book>.opf" next to the book holds (part of) the metadata */
	hasSidecar: boolean
	/** app data; null = unknown */
	completion: Completion | null
	/** effective age rating (manual value, else the one from the file); null = none */
	ageRating: AgeRating | null
	/** the age rating was set in the app and survives re-indexing */
	ageRatingManual: boolean
	progress: Progress | null
}

export type MetadataOverrideField = 'title' | 'authors' | 'series' | 'seriesIndex' | 'description' | 'language' | 'publisher' | 'isbn' | 'publishedAt'

export type SortKey = 'title' | 'author' | 'series' | 'rating' | 'added' | 'read' | 'shelf'

/**
 * `completion:ongoing|completed|unknown`; `age:0|6|12|16|18` (exactly), `age:none` (no rating), `age:<=N` (rated N or lower)
 */
export type FilterType = 'genre' | 'tag' | 'author' | 'series' | 'format' | 'shelf' | 'missing' | 'completion' | 'age'

export interface FilterTerm {
	type: FilterType
	name: string
}

export type MatchMode = 'all' | 'any'

export interface BookQuery {
	search?: string
	include?: FilterTerm[]
	exclude?: FilterTerm[]
	match?: MatchMode
	status?: ReadStatus
	sort?: SortKey
	order?: 'asc' | 'desc'
	/** 0: only books without a series, 1: only books with one */
	inSeries?: 0 | 1
	/** 1: leave out finished books (ignored when status is set) */
	hideFinished?: 1
	limit?: number
	offset?: number
}

export interface BookList {
	books: Book[]
	total: number
}

export interface FacetEntry {
	name: string
	count: number
}

export interface Facets {
	genres: FacetEntry[]
	tags: FacetEntry[]
	authors: FacetEntry[]
	series: FacetEntry[]
	formats: FacetEntry[]
	/** number of books lacking each maintainable field */
	missing: MissingCounts
	/** names ongoing, completed, unknown (fixed order, zeros included) */
	completion?: FacetEntry[]
	/** names "0", "6", "12", "16", "18", "none" (fixed order, zeros included) */
	ageRatings?: FacetEntry[]
}

export type MissingField = 'genre' | 'tag' | 'author' | 'series' | 'description' | 'cover' | 'language'

export type MissingCounts = Record<MissingField, number>

export interface SyncResult {
	books: Book[]
	/** file ids */
	deleted: number[]
	progress: Progress[]
	annotations?: Annotation[]
	cursor: string
	hasMore?: boolean
}

export interface AppDataPatch {
	rating?: number | null
	readStatus?: ReadStatus
	/** null clears (unknown) */
	completion?: Completion | null
	/** manual value; null = explicitly no rating */
	ageRating?: AgeRating | null
	/** use the age rating from the file again (not together with ageRating) */
	resetAgeRating?: true
}

/** PATCH /books/app-data: the same flag fields for several books (max. 500) */
export interface BulkAppDataRequest {
	fileIds: number[]
	completion?: Completion | null
	ageRating?: AgeRating | null
	resetAgeRating?: true
}

export interface BulkAppDataResult {
	updated: number
	unchanged: number
	failed: { fileId: number, error: string }[]
}

/** Next volume of a series whose latest read volume is finished (GET /progress/recent) */
export interface UpNext {
	/** the finished volume */
	previousFileId: number
	book: Book
}

export interface RecentResult {
	books: Book[]
	upNext?: UpNext[]
}

export interface BookMetadata {
	title: string | null
	authors: string[]
	series: string | null
	seriesIndex: number | null
	description: string | null
	language: string | null
	publisher: string | null
	isbn: string | null
	publishedAt: string | null
	genres: string[]
	tags: string[]
}

export type MetadataPatch = Partial<BookMetadata>

export interface BulkTagRequest {
	fileIds: number[]
	addGenres?: string[]
	removeGenres?: string[]
	addTags?: string[]
	removeTags?: string[]
}

export interface BulkTagResult {
	updated: number
	failed: { fileId: number, error: string }[]
	/** True if at least one file will be updated by a background job */
	writeQueued?: boolean
}

export type BulkAuthorsMode = 'replace' | 'add' | 'remove'
export type BulkIndexMode = 'keep' | 'sequence' | 'sortTitle'

/** POST /books/bulk-metadata: only the provided sections change. */
export interface BulkMetadataRequest {
	/** in the order used for volume numbering */
	fileIds: number[]
	authors?: { mode: BulkAuthorsMode, values: string[] }
	series?: { mode: 'clear' } | { mode: 'set', name: string, index?: { mode: BulkIndexMode, start?: number, step?: number } }
	publisher?: { mode: 'clear' } | { mode: 'set', value: string }
	language?: { mode: 'clear' } | { mode: 'set', value: string }
	genres?: { add: string[], remove: string[] }
	tags?: { add: string[], remove: string[] }
}

export interface BulkMetadataResult {
	updated: number
	unchanged: number
	failed: { fileId: number, error: string }[]
	/** True if at least one file will be updated by a background job */
	writeQueued: boolean
}

/** Result of "Write metadata into the book file" */
export interface EmbedResult {
	book: Book
	warnings: string[]
	/** false if the file already held the metadata */
	written: boolean
}

export interface SaveResult {
	book: Book
	warnings: string[]
	/** Metadata patch only: the file is written by a background job shortly afterwards */
	writeQueued?: boolean
}

export interface ProgressPut {
	locator: Locator
	percentage: number
	device?: string | null
	clientUpdatedAt: number
}

export interface ProgressBatchItem extends ProgressPut {
	fileId: number
}

export interface ProgressBatchResult {
	fileId: number
	status: 'ok' | 'conflict'
	progress: Progress
}

export type ReaderTheme = 'auto' | 'light' | 'dark' | 'sepia'

export interface ReaderSettings {
	theme: ReaderTheme
	fontSize: number
	fontFamily: string
	lineHeight: number
	margin: number
	layout: string
	flow: string
	maxColumns: number
	[key: string]: unknown
}

/** When writes into the book file happen (only for the targets "file" and "both") */
export type MetadataWriteMode = 'background' | 'immediate'

/** Where metadata changes are stored: sidecar file (default), inside the book, both, or only in the library */
export type MetadataTarget = 'sidecar' | 'file' | 'both' | 'library'

export interface Settings {
	libraryFolders: string[]
	reader: ReaderSettings
	filenamePattern: string
	/** Server resolves null to the default list from resources/genres.json on GET */
	genreList: string[] | null
	/** When metadata edits are written into the book file (targets file/both): later in one background job (default) or right away */
	metadataWriteMode: MetadataWriteMode
	/** Where metadata changes are stored */
	metadataTarget: MetadataTarget
}

export interface StructureCapabilities {
	metadata: boolean
	cover: boolean
	content: boolean
	toc: boolean
	writesFile: boolean
}

export interface StructureItem {
	id: string
	label: string
	href: string
	kind: 'chapter' | 'page'
	linear: boolean
	size: number
}

export interface TocNode {
	id: string
	label: string
	itemId: string | null
	fragment: string | null
	children: TocNode[]
}

export interface Structure {
	fileId: number
	format: BookFormat
	etag: string
	editable: boolean
	capabilities: StructureCapabilities
	metadata: BookMetadata
	items: StructureItem[]
	toc: TocNode[]
	warnings: string[]
	/** True if only the metadata part was loaded (items and toc are empty) */
	partial: boolean
}

export type EditCover = { source: 'upload', data: string } | { source: 'item', itemId: string }

export interface EditRequest {
	etag: string
	saveAsCopy: boolean
	metadata?: MetadataPatch
	cover?: EditCover
	/** new item order without removed items */
	order?: string[]
	removed?: string[]
	toc?: TocNode[]
}

export interface RenameRequest {
	name?: string
	usePattern?: boolean
}

export interface ScanResult {
	/** E-books found in the library folders */
	found: number
	/** Indexed during the request */
	indexed: number
	/** Handed to background jobs because the time budget ran out */
	queued: number
}

export interface OrganizeRequest {
	fileIds: number[]
	pattern: string
	targetFolder?: string
}

export type OrganizeStatus = 'move' | 'unchanged' | 'conflict' | 'error' | 'moved' | 'failed'

export interface OrganizeItem {
	fileId: number
	from: string
	to: string
	status: OrganizeStatus
	message?: string
}

export interface OrganizePreview {
	items: OrganizeItem[]
}

export interface OrganizeResult {
	items: OrganizeItem[]
	moved: number
	failed: number
}

// ---- Async tasks (CONTRACTS-v3 section 1) -------------------------------

export type TaskType = 'edit' | 'convert' | 'embed' | 'bulk'
export type TaskStatus = 'queued' | 'running' | 'done' | 'failed'

/** Result of a finished task. `edit`: book + warnings, `convert`: book + fileId + path. On `failed`: `code` = 403/409/413/422. */
export interface TaskResult {
	book?: Book
	warnings?: string[]
	/** embed: false if the file already held the metadata */
	written?: boolean
	fileId?: number
	path?: string
	code?: number
	/** bulk: counters of the bulk metadata edit */
	updated?: number
	unchanged?: number
	failed?: { fileId: number, error: string }[]
	writeQueued?: boolean
}

export interface Task {
	id: number
	fileId: number
	type: TaskType
	status: TaskStatus
	/** 0..1 */
	progress: number
	/** short English description of the current step */
	step: string
	result: TaskResult | null
	error: string | null
	createdAt: number
	updatedAt: number
}

export interface TaskStarted {
	taskId: number
}

export interface ArchiveEntries {
	etag: string
	entries: { name: string, size: number }[]
}

// ---- Shelves, series (CONTRACTS-v4 sections 1 and 2) -----------------------

export type ShelfType = 'manual' | 'smart'

/** Saved library filter state of a smart shelf; terms are "type:name" strings. */
export interface SmartQuery {
	include: string[]
	exclude: string[]
	match: MatchMode
	search: string
	status: ReadStatus | null
	sort: string
	order: 'asc' | 'desc'
}

export interface Shelf {
	id: number
	name: string
	type: ShelfType
	query: SmartQuery | null
	count: number
	/** max 4 */
	coverFileIds: number[]
	sortOrder: number
	createdAt: number
	updatedAt: number
}

export interface ShelfBooksResult {
	added: number
	skipped: number
}

export interface SeriesEntry {
	name: string
	count: number
	readCount: number
	/** max 3, lowest series index first */
	coverFileIds: number[]
	firstFileId: number
	lastAddedAt: number
}

export type SeriesQuery = Omit<BookQuery, 'inSeries' | 'limit' | 'offset' | 'sort'> & { sort?: 'name' | 'added' }

export type AnnotationType = 'highlight' | 'note' | 'bookmark'
export type AnnotationColor = 'yellow' | 'green' | 'blue' | 'pink' | 'purple'

/** Highlight, note or bookmark of a book (`GET /books/{fileId}/annotations`). Times are ms since epoch. */
export interface Annotation {
	uuid: string
	fileId: number
	type: AnnotationType
	locator: Locator
	/** selected text excerpt, max 2000 characters */
	text: string | null
	/** max 10000 characters */
	note: string | null
	color: AnnotationColor | null
	createdAt: number
	updatedAt: number
	clientUpdatedAt: number
	deleted: boolean
}

export interface AnnotationCreate {
	uuid?: string
	type: AnnotationType
	locator: Locator
	text?: string | null
	note?: string | null
	color?: AnnotationColor | null
	clientUpdatedAt?: number
	createdAt?: number
}

/** Empty string for `note` / `color` clears the value on the server. */
export interface AnnotationPatch {
	locator?: Locator
	text?: string
	note?: string
	color?: AnnotationColor | ''
	clientUpdatedAt?: number
}
