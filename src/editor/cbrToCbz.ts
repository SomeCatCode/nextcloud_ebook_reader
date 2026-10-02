/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { zipSync } from 'fflate'
import { Archive } from 'libarchive.js'
import wasmUrl from 'libarchive.js/dist/libarchive.wasm?url'
import workerUrl from 'libarchive.js/dist/worker-bundle.js?url'
import { davUrlForPath, fetchBookBlob } from '../services/api.ts'
import { davUpload } from '../services/davUpload.ts'

const IMAGE_RE = /\.(jpe?g|png|gif|webp|avif|bmp)$/i

export class TargetExistsError extends Error {
	public readonly path: string

	constructor(path: string) {
		super(`Target already exists: ${path}`)
		this.name = 'TargetExistsError'
		this.path = path
	}
}

let initPromise: Promise<void> | null = null

/**
 * Initialises libarchive.js. The worker resolves libarchive.wasm relative to its own URL, which does
 * not hold for bundled (hashed) assets, so the worker source is patched to point at the wasm asset
 * and started from a blob URL. (Own minimal init, see docs/DEVIATIONS.md W6.)
 */
function initArchive(): Promise<void> {
	initPromise ??= (async () => {
		const src = await (await fetch(workerUrl)).text()
		const absWasm = new URL(wasmUrl, window.location.href).href
		const patched = src.replace(/new URL\("libarchive\.wasm",import\.meta\.url\)\.href/g, JSON.stringify(absWasm))
		const blobUrl = URL.createObjectURL(new Blob([patched], { type: 'text/javascript' }))
		Archive.init({ workerUrl: blobUrl })
	})()
	return initPromise
}

/**
 * @param a
 * @param b
 */
function naturalCompare(a: string, b: string): number {
	return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' })
}

export interface ConvertOptions {
	onProgress?: (done: number, total: number) => void
	signal?: AbortSignal
}

/**
 * Downloads the CBR via WebDAV, extracts all pages and packs them into a CBZ (images stored uncompressed).
 *
 * @param book
 * @param book.path user-relative path of the .cbr
 * @param options
 */
export async function convertCbrToCbz(book: { path: string }, options: ConvertOptions = {}): Promise<Blob> {
	await initArchive()
	const blob = await fetchBookBlob(book, options.signal)
	const reader = await Archive.open(new File([blob], 'book.cbr'))
	try {
		const entries = (await reader.getFilesArray())
			.filter((e) => IMAGE_RE.test(e.file.name) || e.file.name.toLowerCase() === 'comicinfo.xml')
			.map((e) => ({ ...e, full: String(e.path ?? '') + e.file.name }))
			.sort((a, b) => naturalCompare(a.full, b.full))
		const files: Record<string, [Uint8Array, { level: 0 | 6 }]> = {}
		let done = 0
		for (const e of entries) {
			options.signal?.throwIfAborted()
			const extracted: File = await e.file.extract()
			const isImage = IMAGE_RE.test(e.file.name)
			files[e.full] = [new Uint8Array(await extracted.arrayBuffer()), { level: isImage ? 0 : 6 }]
			options.onProgress?.(++done, entries.length)
		}
		if (done === 0) {
			throw new Error('No images found in archive')
		}
		const zipped = zipSync(files)
		return new Blob([zipped as BlobPart], { type: 'application/comicbook+zip' })
	} finally {
		await reader.close().catch(() => {})
	}
}

/**
 * @param path
 */
export function cbzPathFor(path: string): string {
	return path.replace(/\.cbr$/i, '') + '.cbz'
}

/**
 * @param path
 */
export async function davExists(path: string): Promise<boolean> {
	const res = await axios.head(davUrlForPath(path), { validateStatus: () => true })
	return res.status >= 200 && res.status < 300
}

/**
 * Uploads the CBZ next to the CBR. Throws TargetExistsError if the target exists (and `overwrite` is false).
 *
 * @param cbrPath
 * @param cbz
 * @param overwrite
 */
export async function uploadCbz(cbrPath: string, cbz: Blob, overwrite = false): Promise<string> {
	const target = cbzPathFor(cbrPath)
	if (!overwrite && await davExists(target)) {
		throw new TargetExistsError(target)
	}
	await davUpload(target, cbz, { contentType: 'application/comicbook+zip' })
	return target
}

/**
 * Moves the original to the trash (WebDAV DELETE on the files endpoint).
 *
 * @param path
 */
export async function deleteOriginal(path: string): Promise<void> {
	await axios.delete(davUrlForPath(path))
}
