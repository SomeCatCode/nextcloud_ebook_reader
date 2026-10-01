/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export type BookFormat = 'epub' | 'mobi' | 'azw3' | 'fb2' | 'fbz' | 'cbz' | 'cbr' | 'cb7' | 'cbt'
export type ReadStatus = 'unread' | 'reading' | 'finished'

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
	progress: Progress | null
}

export type SortKey = 'title' | 'author' | 'series' | 'rating' | 'added' | 'read'

export type FilterType = 'genre' | 'tag' | 'author' | 'series' | 'format'

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
}

export interface SyncResult {
	books: Book[]
	/** file ids */
	deleted: number[]
	progress: Progress[]
	cursor: string
}

export interface AppDataPatch {
	rating?: number | null
	readStatus?: ReadStatus
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
}

export interface SaveResult {
	book: Book
	warnings: string[]
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

export interface Settings {
	libraryFolders: string[]
	reader: ReaderSettings
	filenamePattern: string
	/** Server resolves null to the default list from resources/genres.json on GET */
	genreList: string[] | null
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
