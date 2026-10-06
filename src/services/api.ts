import type {
	Annotation,
	AnnotationCreate,
	AnnotationPatch,
	AppDataPatch,
	ArchiveEntries,
	Book,
	BookList,
	BookQuery,
	BulkAppDataRequest,
	BulkAppDataResult,
	BulkMetadataRequest,
	BulkMetadataResult,
	BulkTagRequest,
	BulkTagResult,
	EditRequest,
	EmbedResult,
	Facets,
	FilterTerm,
	MetadataOverrideField,
	MetadataPatch,
	OrganizePreview,
	OrganizeRequest,
	OrganizeResult,
	Progress,
	ProgressBatchItem,
	ProgressBatchResult,
	ProgressPut,
	RecentResult,
	RenameRequest,
	SaveResult,
	ScanResult,
	SeriesEntry,
	SeriesQuery,
	Settings,
	Shelf,
	ShelfBooksResult,
	ShelfType,
	SmartQuery,
	Structure,
	SyncResult,
	Task,
	TaskStarted,
} from '../types.ts'

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { defaultRemoteURL, defaultRootPath } from '@nextcloud/files/dav'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

const BASE = '/apps/ebookreader/api/v1'

/**
 * Thrown on HTTP 409. `current` is the server-side state sent with the conflict
 * (for progress: the current Progress; for structure saves: the error payload).
 */
export class ConflictError<T = unknown> extends Error {
	public readonly current: T

	constructor(current: T, message = 'Conflict') {
		super(message)
		this.name = 'ConflictError'
		this.current = current
	}
}

/** Error carrying the HTTP status for non-409 failures. */
export class ApiError extends Error {
	public readonly status: number

	constructor(status: number, message: string) {
		super(message)
		this.name = 'ApiError'
		this.status = status
	}
}

interface OcsEnvelope<T> {
	ocs: { meta: { status: string, statuscode: number, message?: string }, data: T }
}

/**
 *
 * @param path
 */
function ocsUrl(path: string): string {
	return generateOcsUrl(BASE + path)
}

/**
 *
 * @param params
 */
function cleanParams(params?: object): Record<string, unknown> | undefined {
	if (!params) {
		return undefined
	}
	return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''))
}

type Method = 'get' | 'put' | 'post' | 'patch' | 'delete'

interface RequestOptions {
	params?: object
	body?: unknown
	/** Upload progress 0..1 (only meaningful for larger request bodies) */
	onUploadProgress?: (fraction: number) => void
}

/**
 *
 * @param method
 * @param path
 * @param options
 */
async function request<T>(method: Method, path: string, options: RequestOptions = {}): Promise<T> {
	try {
		const res = await axios.request<OcsEnvelope<T>>({
			method,
			url: ocsUrl(path),
			params: cleanParams(options.params),
			data: options.body,
			headers: { 'OCS-APIRequest': 'true' },
			onUploadProgress: options.onUploadProgress
				? (ev) => options.onUploadProgress?.(ev.total ? ev.loaded / ev.total : 0)
				: undefined,
		})
		return res.data.ocs.data
	} catch (e: unknown) {
		const err = e as { response?: { status: number, data?: { ocs?: { data?: { current?: unknown }, meta?: { message?: string } } } }, message?: string }
		const status = err.response?.status
		if (status === 409) {
			const data = err.response?.data?.ocs?.data
			throw new ConflictError(data?.current ?? data)
		}
		if (status !== undefined) {
			throw new ApiError(status, err.response?.data?.ocs?.meta?.message ?? err.message ?? 'Request failed')
		}
		throw e
	}
}

// ---- Books -----------------------------------------------------------

/**
 *
 * @param query
 */
export function listBooks(query: BookQuery = {}): Promise<BookList> {
	return request<BookList>('get', '/books', { params: queryParams(query) })
}

/**
 * @param query
 */
function queryParams(query: BookQuery | SeriesQuery): Record<string, unknown> {
	const { include, exclude, ...rest } = query
	const termToParam = (t: FilterTerm): string => `${t.type}:${t.name}`
	return {
		...rest,
		// axios serialises arrays as include[]=a&include[]=b
		include: include?.length ? include.map(termToParam) : undefined,
		exclude: exclude?.length ? exclude.map(termToParam) : undefined,
	}
}

/**
 * Series cards for the current filters (same filter parameters as the book list).
 *
 * @param query
 */
export async function listSeries(query: SeriesQuery = {}): Promise<SeriesEntry[]> {
	const res = await request<{ series: SeriesEntry[] }>('get', '/series', { params: queryParams(query) })
	return res.series
}

// ---- Shelves ------------------------------------------------------------

/**
 *
 */
export async function listShelves(): Promise<Shelf[]> {
	return (await request<{ shelves: Shelf[] }>('get', '/shelves')).shelves
}

/**
 * @param body
 * @param body.name
 * @param body.type
 * @param body.query
 */
export function createShelf(body: { name: string, type: ShelfType, query?: SmartQuery }): Promise<Shelf> {
	return request<Shelf>('post', '/shelves', { body })
}

/**
 * @param id
 * @param body
 * @param body.name
 * @param body.query
 * @param body.sortOrder
 */
export function patchShelf(id: number, body: { name?: string, query?: SmartQuery, sortOrder?: number }): Promise<Shelf> {
	return request<Shelf>('patch', `/shelves/${id}`, { body })
}

/**
 * @param id
 */
export async function deleteShelf(id: number): Promise<void> {
	await request<unknown>('delete', `/shelves/${id}`)
}

/**
 * @param id
 * @param fileIds
 */
export function addToShelf(id: number, fileIds: number[]): Promise<ShelfBooksResult> {
	return request<ShelfBooksResult>('post', `/shelves/${id}/books`, { body: { fileIds } })
}

/**
 * @param id
 * @param fileIds
 */
export function removeFromShelf(id: number, fileIds: number[]): Promise<{ removed: number }> {
	return request<{ removed: number }>('delete', `/shelves/${id}/books`, { body: { fileIds } })
}

/**
 * @param id
 * @param fileIds
 */
export async function reorderShelf(id: number, fileIds: number[]): Promise<void> {
	await request<unknown>('put', `/shelves/${id}/books/order`, { body: { fileIds } })
}

/**
 *
 * @param fileId
 */
export function getBook(fileId: number): Promise<Book> {
	return request<Book>('get', `/books/${fileId}`)
}

export interface DeleteBooksResult {
	deleted: number[]
	failed: { fileId: number, error: 'not_found' | 'forbidden' | 'failed' }[]
}

/**
 * Deletes books: the files go to the Nextcloud trash bin (if enabled). Max. 100 per call.
 *
 * @param fileIds
 */
export function deleteBooks(fileIds: number[]): Promise<DeleteBooksResult> {
	return request<DeleteBooksResult>('post', '/books/delete', { body: { fileIds } })
}

/**
 *
 * @param fileId
 * @param patch
 */
export function patchAppData(fileId: number, patch: AppDataPatch): Promise<Book> {
	return request<Book>('patch', `/books/${fileId}/app-data`, { body: patch })
}

/**
 * Completion status / age rating of several books (max. 500).
 *
 * @param req
 */
export function bulkAppData(req: BulkAppDataRequest): Promise<BulkAppDataResult> {
	return request<BulkAppDataResult>('patch', '/books/app-data', { body: req })
}

/**
 *
 * @param fileId
 * @param patch
 */
export function patchMetadata(fileId: number, patch: MetadataPatch): Promise<SaveResult> {
	return request<SaveResult>('patch', `/books/${fileId}/metadata`, { body: patch })
}

/**
 * Drops the "edited in app" marker of one field (all when omitted) and takes the value from the file again.
 *
 * @param fileId
 * @param field
 */
export function resetOverrides(fileId: number, field?: MetadataOverrideField): Promise<Book> {
	return request<Book>('delete', `/books/${fileId}/overrides`, { params: field ? { field } : undefined })
}

/**
 *
 * @param req
 */
export function bulkTags(req: BulkTagRequest): Promise<BulkTagResult> {
	return request<BulkTagResult>('post', '/books/bulk-tags', { body: req })
}

/**
 *
 */
export function getFacets(): Promise<Facets> {
	return request<Facets>('get', '/facets')
}

/**
 *
 * @param cursor
 */
export function sync(cursor = ''): Promise<SyncResult> {
	return request<SyncResult>('get', '/sync', { params: { cursor } })
}

// ---- Progress --------------------------------------------------------

/**
 * Returns null if no progress exists (404).
 *
 * @param fileId
 */
export async function getProgress(fileId: number): Promise<Progress | null> {
	try {
		return await request<Progress>('get', `/progress/${fileId}`)
	} catch (e) {
		if (e instanceof ApiError && e.status === 404) {
			return null
		}
		throw e
	}
}

/**
 * Throws ConflictError (`current` = server Progress) on 409.
 *
 * @param fileId
 * @param body
 */
export function putProgress(fileId: number, body: ProgressPut): Promise<Progress> {
	return request<Progress>('put', `/progress/${fileId}`, { body })
}

/**
 *
 * @param items
 */
export function putProgressBatch(items: ProgressBatchItem[]): Promise<{ results: ProgressBatchResult[] }> {
	return request<{ results: ProgressBatchResult[] }>('post', '/progress/batch', { body: { items } })
}

/**
 *
 * @param limit
 */
export function recentBooks(limit = 10): Promise<RecentResult> {
	return request<RecentResult>('get', '/progress/recent', { params: { limit } })
}

/**
 * Next volume of the book's series (null for the last volume or without a series).
 *
 * @param fileId
 */
export async function nextVolume(fileId: number): Promise<Book | null> {
	return (await request<{ book: Book | null }>('get', `/books/${fileId}/next`)).book
}

/**
 * Fire-and-forget progress write that survives page unload.
 * Uses fetch keepalive (sendBeacon cannot set the required headers).
 *
 * @param fileId
 * @param body
 */
export function putProgressKeepalive(fileId: number, body: ProgressPut): void {
	try {
		const requesttoken = document.querySelector('head')?.getAttribute('data-requesttoken') ?? ''
		void fetch(ocsUrl(`/progress/${fileId}`), {
			method: 'PUT',
			keepalive: true,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'OCS-APIRequest': 'true',
				requesttoken,
			},
			body: JSON.stringify(body),
		}).catch(() => {})
	} catch {
		// ignore
	}
}

// ---- Settings / scan -------------------------------------------------

/**
 *
 */
export function getSettings(): Promise<Settings> {
	return request<Settings>('get', '/settings')
}

/**
 *
 * @param settings
 */
export function putSettings(settings: Partial<Settings>): Promise<Settings> {
	return request<Settings>('put', '/settings', { body: settings })
}

/**
 *
 */
export function scan(): Promise<ScanResult> {
	return request<ScanResult>('post', '/scan')
}

// ---- Editor ----------------------------------------------------------

/**
 * "metadata" answers from the library without reading the book file (items/toc empty, partial = true).
 *
 * @param fileId
 * @param parts
 */
export function getStructure(fileId: number, parts: 'all' | 'metadata' = 'all'): Promise<Structure> {
	return request<Structure>('get', `/books/${fileId}/structure`, parts === 'all' ? {} : { params: { parts } })
}

/**
 * Throws ConflictError on etag conflict.
 *
 * @param fileId
 * @param req
 * @param onUploadProgress upload progress 0..1
 */
export function putStructure(fileId: number, req: EditRequest, onUploadProgress?: (fraction: number) => void): Promise<SaveResult> {
	return request<SaveResult>('put', `/books/${fileId}/structure`, { body: req, onUploadProgress })
}

/**
 * Async variant: the server answers 202 `{taskId}` and does the work in the background (poll with getTask).
 * An older server answers 200 with the SaveResult (synchronous), which is returned as `{ sync: result }`.
 *
 * @param fileId
 * @param req
 * @param onUploadProgress upload progress 0..1
 */
export async function putStructureAsync(fileId: number, req: EditRequest, onUploadProgress?: (fraction: number) => void): Promise<{ taskId: number } | { sync: SaveResult }> {
	return await startTask<SaveResult>('put', `/books/${fileId}/structure`, req, onUploadProgress)
}

/**
 * Edits the metadata of several books. The server answers 200 with the result or, for large requests that write into
 * the files (or with `async`), 202 `{taskId}` (poll with getTask).
 *
 * @param req
 * @param async force a background task
 */
export async function bulkMetadata(req: BulkMetadataRequest, async: boolean): Promise<{ taskId: number } | { sync: BulkMetadataResult }> {
	return await startTask<BulkMetadataResult>('post', '/books/bulk-metadata', req, undefined, async)
}

/**
 * Writes the library metadata into the book file ("Write metadata into the book file"). With `async` the server
 * answers 202 `{taskId}` (poll with getTask), otherwise it writes right away.
 *
 * @param fileId
 * @param async use a background task (large files)
 */
export async function embedMetadata(fileId: number, async: boolean): Promise<{ taskId: number } | { sync: EmbedResult }> {
	if (async) {
		return await startTask<EmbedResult>('post', `/books/${fileId}/metadata/embed`, {})
	}
	return { sync: await request<EmbedResult>('post', `/books/${fileId}/metadata/embed`, { body: {} }) }
}

/**
 * Finishes a browser-side conversion on the server: indexes the uploaded file right away,
 * copies the sidecar, carries over rating/status/position and deletes the original if requested.
 *
 * @param fileId file id of the original
 * @param body uploaded file name (same folder) and page names for the position mapping
 * @param body.name
 * @param body.deleteOriginal
 * @param body.oldPages
 * @param body.newPages
 */
export function adoptConversion(fileId: number, body: { name: string, deleteOriginal: boolean, oldPages: string[], newPages: string[] }): Promise<{ book: Book, fileId: number, path: string, originalDeleted: boolean }> {
	return request('post', `/books/${fileId}/convert/adopt`, { body })
}

/**
 * Async conversion on the server. Returns `{ sync }` if an older server converted synchronously.
 *
 * @param fileId
 * @param body
 * @param body.target
 * @param body.deleteOriginal
 * @param body.optimize
 * @param body.optimize.maxHeight
 * @param body.optimize.pngToJpeg
 */
export async function convertAsync<T = unknown>(fileId: number, body: { target: string, deleteOriginal: boolean, optimize?: { maxHeight: number, pngToJpeg: boolean } }): Promise<{ taskId: number } | { sync: T }> {
	return await startTask<T>('post', `/books/${fileId}/convert`, body)
}

/**
 * @param method
 * @param path
 * @param body
 * @param onUploadProgress
 * @param forceAsync send `async=1` (otherwise the server decides)
 */
async function startTask<T>(method: 'put' | 'post', path: string, body: unknown, onUploadProgress?: (fraction: number) => void, forceAsync = true): Promise<{ taskId: number } | { sync: T }> {
	try {
		const res = await axios.request<OcsEnvelope<T | TaskStarted>>({
			method,
			url: ocsUrl(path),
			params: forceAsync ? { async: 1 } : undefined,
			data: body,
			headers: { 'OCS-APIRequest': 'true' },
			onUploadProgress: onUploadProgress ? (ev) => onUploadProgress(ev.total ? ev.loaded / ev.total : 0) : undefined,
		})
		const data = res.data.ocs.data
		if (res.status === 202 || (typeof (data as TaskStarted | null)?.taskId === 'number' && !('book' in (data as object)))) {
			return { taskId: (data as TaskStarted).taskId }
		}
		return { sync: data as T }
	} catch (e: unknown) {
		const err = e as { response?: { status: number, data?: { ocs?: { data?: { current?: unknown, message?: string }, meta?: { message?: string } } } }, message?: string }
		const status = err.response?.status
		if (status === 409) {
			const data = err.response?.data?.ocs?.data
			throw new ConflictError(data?.current ?? data)
		}
		if (status !== undefined) {
			throw new ApiError(status, err.response?.data?.ocs?.data?.message ?? err.response?.data?.ocs?.meta?.message ?? err.message ?? 'Request failed')
		}
		throw e
	}
}

/**
 * @param taskId
 */
export function getTask(taskId: number): Promise<Task> {
	return request<Task>('get', `/tasks/${taskId}`)
}

/** Running and queued tasks of the user. */
export async function listActiveTasks(): Promise<Task[]> {
	const data = await request<{ tasks: Task[] } | Task[]>('get', '/tasks', { params: { active: 1 } })
	return Array.isArray(data) ? data : (data?.tasks ?? [])
}

/**
 * Entry list of an EPUB/CBZ/FBZ for reading single entries (server side archive cache).
 *
 * @param fileId
 * @param signal
 */
export async function archiveEntries(fileId: number, signal?: AbortSignal): Promise<ArchiveEntries> {
	const res = await axios.get<ArchiveEntries>(generateUrl('/apps/ebookreader/archive/{fileId}/entries', { fileId }), { signal })
	return res.data
}

/**
 *
 * @param fileId
 * @param req
 */
export function renameBook(fileId: number, req: RenameRequest): Promise<Book> {
	return request<Book>('post', `/books/${fileId}/rename`, { body: req })
}

// ---- Organise --------------------------------------------------------

/**
 * @param req
 */
export function organizePreview(req: OrganizeRequest): Promise<OrganizePreview> {
	return request<OrganizePreview>('post', '/organize/preview', { body: req })
}

/**
 * @param req
 */
export function organizeApply(req: OrganizeRequest): Promise<OrganizeResult> {
	return request<OrganizeResult>('post', '/organize/apply', { body: req })
}

// ---- URL helpers (non-OCS) -------------------------------------------

/**
 * Cover image URL (normal controller, ETag cached). `etag` is only used for cache busting.
 *
 * @param fileId
 * @param size
 * @param etag
 */
export function coverUrl(fileId: number, size: 'small' | 'large' = 'small', etag?: string | null): string {
	const params: Record<string, string> = { size }
	if (etag) {
		params.v = etag
	}
	return generateUrl('/apps/ebookreader/cover/{fileId}', { fileId }) + '?' + new URLSearchParams(params).toString()
}

/**
 * Uploads a cover image as raw body, e.g. a cover extracted client-side from a CBR.
 *
 * @param fileId
 * @param data
 */
export async function uploadCover(fileId: number, data: Blob): Promise<void> {
	await axios.post(generateUrl('/apps/ebookreader/cover/{fileId}', { fileId }), data, {
		headers: { 'Content-Type': data.type || 'application/octet-stream' },
	})
}

/**
 * Raw content of a zip entry (comic page thumbnails, epub items).
 *
 * @param fileId
 * @param itemId
 * @param etag
 */
export function itemUrl(fileId: number, itemId: string, etag?: string | null): string {
	// The etag versions the URL: after saving, pages are renumbered (0001.jpg, …) and the browser
	// must not show a cached image of the previous file version under the same entry name.
	const params: Record<string, string> = { id: itemId }
	if (etag) {
		params.v = etag
	}
	return generateUrl('/apps/ebookreader/item/{fileId}', { fileId }) + '?' + new URLSearchParams(params).toString()
}

/**
 * WebDAV URL of a file given its user-relative path (e.g. Book.path).
 *
 * @param path
 */
export function davUrlForPath(path: string): string {
	const encoded = ('/' + path.replace(/^\/+/, '')).split('/').map(encodeURIComponent).join('/')
	return defaultRemoteURL + defaultRootPath + encoded
}

/**
 * Downloads the book file via WebDAV as a Blob.
 *
 * @param book
 * @param signal
 */
export interface ComicPages {
	etag: string
	pages: { name: string, size: number }[]
}

/**
 * Page list of a CBZ served page by page by the server (ComicController).
 *
 * @param fileId
 * @param signal
 */
export async function getComicPages(fileId: number, signal?: AbortSignal): Promise<ComicPages> {
	const res = await axios.get<ComicPages>(generateUrl('/apps/ebookreader/comic/{fileId}/pages', { fileId }), { signal })
	return res.data
}

/**
 * URL of one comic page, scaled down on the server to about `width` device pixels.
 *
 * @param fileId
 * @param index 0-based page index from getComicPages()
 * @param width
 * @param etag file etag from getComicPages(), makes the URL cacheable per file version
 */
export function comicPageUrl(fileId: number, index: number, width: number, etag: string): string {
	return generateUrl('/apps/ebookreader/comic/{fileId}/page/{index}', { fileId, index })
		+ '?' + new URLSearchParams({ w: String(Math.round(width)), v: etag }).toString()
}

/**
 *
 * @param book
 * @param signal
 */
export async function fetchBookBlob(book: Pick<Book, 'path'>, signal?: AbortSignal): Promise<Blob> {
	const res = await fetch(davUrlForPath(book.path), { credentials: 'same-origin', signal })
	if (!res.ok) {
		throw new ApiError(res.status, `Could not load book (${res.status})`)
	}
	return await res.blob()
}

// ---- Annotations (highlights, notes, bookmarks) --------------------------

/**
 * Live annotations of a book.
 *
 * @param fileId
 */
export async function listAnnotations(fileId: number): Promise<Annotation[]> {
	return (await request<{ annotations: Annotation[] }>('get', `/books/${fileId}/annotations`)).annotations
}

/**
 * Creates the annotation or updates the one with the same uuid. Throws ConflictError (`current` = server version) on 409.
 *
 * @param fileId
 * @param body
 */
export function createAnnotation(fileId: number, body: AnnotationCreate): Promise<Annotation> {
	return request<Annotation>('post', `/books/${fileId}/annotations`, { body })
}

/**
 * Throws ConflictError (`current` = server version) on 409.
 *
 * @param uuid
 * @param body
 */
export function patchAnnotation(uuid: string, body: AnnotationPatch): Promise<Annotation> {
	return request<Annotation>('patch', `/annotations/${uuid}`, { body })
}

/**
 * Sets the tombstone on the server.
 *
 * @param uuid
 * @param clientUpdatedAt
 */
export function deleteAnnotation(uuid: string, clientUpdatedAt?: number): Promise<Annotation> {
	return request<Annotation>('delete', `/annotations/${uuid}`, { params: { clientUpdatedAt } })
}
