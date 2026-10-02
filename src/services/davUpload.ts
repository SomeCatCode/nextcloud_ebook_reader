/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { defaultRemoteURL } from '@nextcloud/files/dav'
import { davUrlForPath } from './api.ts'

/** Nextcloud chunking v2 needs chunks of 5 MiB..5 GiB (except the last one). */
export const CHUNK_SIZE = 10 * 1024 * 1024
const CHUNK_RETRIES = 3

export interface DavUploadOptions {
	contentType?: string
	/** false: fail with HTTP 412 if the target exists (default true = overwrite) */
	overwrite?: boolean
	signal?: AbortSignal
	onProgress?: (loaded: number, total: number) => void
}

/**
 * Uploads a blob to a path in the user's files. Large blobs go through Nextcloud's chunked upload
 * (v2), so a single long request cannot be cut off by a proxy or request timeout (a 200 MB PUT
 * otherwise often ends with "expected file size ... but read ..."). Returns the new file id (0 if
 * the server did not report one).
 *
 * @param path user-relative path, e.g. `/Books/x.cbz`
 * @param blob content
 * @param options see DavUploadOptions
 */
export async function davUpload(path: string, blob: Blob, options: DavUploadOptions = {}): Promise<number> {
	const { contentType, overwrite = true, signal, onProgress } = options
	const destination = davUrlForPath(path)
	if (blob.size <= CHUNK_SIZE) {
		const res = await axios.put(destination, blob, {
			headers: { ...(contentType ? { 'Content-Type': contentType } : {}), ...(overwrite ? {} : { 'If-None-Match': '*' }) },
			signal,
			onUploadProgress: (e) => onProgress?.(e.loaded, blob.size),
		})
		return fileIdOf(res.headers)
	}

	const uid = getCurrentUser()?.uid
	if (!uid) {
		throw new Error('Not logged in')
	}
	const folder = `${defaultRemoteURL}/uploads/${encodeURIComponent(uid)}/ebookreader-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
	const common = { Destination: destination, 'OC-Total-Length': String(blob.size) }
	await axios.request({ method: 'MKCOL', url: folder, headers: common, signal })
	try {
		let uploaded = 0
		const count = Math.ceil(blob.size / CHUNK_SIZE)
		for (let i = 0; i < count; i++) {
			const chunk = blob.slice(i * CHUNK_SIZE, Math.min(blob.size, (i + 1) * CHUNK_SIZE))
			await withRetries(() => axios.put(`${folder}/${String(i + 1).padStart(5, '0')}`, chunk, {
				headers: { ...common, 'Content-Type': 'application/octet-stream' },
				signal,
				onUploadProgress: (e) => onProgress?.(uploaded + e.loaded, blob.size),
			}), signal)
			uploaded += chunk.size
			onProgress?.(uploaded, blob.size)
		}
		const res = await axios.request({
			method: 'MOVE',
			url: `${folder}/.file`,
			headers: { ...common, Overwrite: overwrite ? 'T' : 'F' },
			signal,
		})
		return fileIdOf(res.headers)
	} catch (e) {
		// the assembled upload folder is cleaned up by the server's job as well; this frees it right away
		axios.request({ method: 'DELETE', url: folder }).catch(() => undefined)
		throw e
	}
}

/**
 * @param headers response headers
 */
function fileIdOf(headers: Record<string, unknown>): number {
	return parseInt(String(headers['oc-fileid'] ?? ''), 10) || 0
}

/**
 * Retries a request on network errors and 5xx (not on 4xx or abort).
 *
 * @param run the request
 * @param signal abort signal
 */
async function withRetries<T>(run: () => Promise<T>, signal?: AbortSignal): Promise<T> {
	for (let attempt = 1; ; attempt++) {
		try {
			return await run()
		} catch (e) {
			const status = (e as { response?: { status?: number } }).response?.status
			const retryable = status === undefined || status >= 500
			if (signal?.aborted || !retryable || attempt >= CHUNK_RETRIES) {
				throw e
			}
			await new Promise((resolve) => setTimeout(resolve, 1000 * attempt))
		}
	}
}
