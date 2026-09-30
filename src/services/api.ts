import type {
	AppDataPatch,
	Book,
	BookList,
	BookQuery,
	BulkTagRequest,
	BulkTagResult,
	EditRequest,
	Facets,
	MetadataPatch,
	Progress,
	ProgressBatchItem,
	ProgressBatchResult,
	ProgressPut,
	RenameRequest,
	SaveResult,
	ScanResult,
	Settings,
	Structure,
	SyncResult,
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
	return request<BookList>('get', '/books', { params: query })
}

/**
 *
 * @param fileId
 */
export function getBook(fileId: number): Promise<Book> {
	return request<Book>('get', `/books/${fileId}`)
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
 *
 * @param fileId
 * @param patch
 */
export function patchMetadata(fileId: number, patch: MetadataPatch): Promise<SaveResult> {
	return request<SaveResult>('patch', `/books/${fileId}/metadata`, { body: patch })
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
export function recentBooks(limit = 10): Promise<{ books: Book[] }> {
	return request<{ books: Book[] }>('get', '/progress/recent', { params: { limit } })
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
 *
 * @param fileId
 */
export function getStructure(fileId: number): Promise<Structure> {
	return request<Structure>('get', `/books/${fileId}/structure`)
}

/**
 * Throws ConflictError on etag conflict.
 *
 * @param fileId
 * @param req
 */
export function putStructure(fileId: number, req: EditRequest): Promise<SaveResult> {
	return request<SaveResult>('put', `/books/${fileId}/structure`, { body: req })
}

/**
 *
 * @param fileId
 * @param req
 */
export function renameBook(fileId: number, req: RenameRequest): Promise<Book> {
	return request<Book>('post', `/books/${fileId}/rename`, { body: req })
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
 */
export function itemUrl(fileId: number, itemId: string): string {
	return generateUrl('/apps/ebookreader/item/{fileId}', { fileId }) + '?' + new URLSearchParams({ id: itemId }).toString()
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
export async function fetchBookBlob(book: Pick<Book, 'path'>, signal?: AbortSignal): Promise<Blob> {
	const res = await fetch(davUrlForPath(book.path), { credentials: 'same-origin', signal })
	if (!res.ok) {
		throw new ApiError(res.status, `Could not load book (${res.status})`)
	}
	return await res.blob()
}
