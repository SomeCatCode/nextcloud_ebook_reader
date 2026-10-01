/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Conversion in the browser, for comics the server cannot read or write (no 7z/unrar installed):
 * download via WebDAV, unpack with libarchive.js, pack the target (fflate for ZIP/EPUB, own
 * writers for tar and 7z, see archiveWriters.ts), upload via WebDAV PUT, then POST /scan.
 */
import type { Book } from '../types.ts'
import type { EpubPage } from './epub.ts'
import type { ConvertFormat, ConvertStep } from './types.ts'

import axios from '@nextcloud/axios'
import { zipSync } from 'fflate'
import { makeArchiveLoader } from '../../packages/reader-core/src/comic-rar.ts'
import { loadLibarchive } from '../components/reader/libarchive.ts'
import { adoptConversion, davUrlForPath } from '../services/api.ts'
import { writeSevenZip, writeTar } from './archiveWriters.ts'
import { ConvertError } from './convertApi.ts'
import { buildComicInfo, buildEpub, comicInfoCoverIndex, comicInfoIsRtl } from './epub.ts'
import { pageEntries, pageName } from './pages.ts'
import { browserCanConvert, targetPath } from './targets.ts'

export interface ClientConvertOptions {
	deleteOriginal: boolean
	onStep?: (step: ConvertStep) => void
	/** progress inside a step (download: bytes, convert: pages, ...) */
	onProgress?: (step: ConvertStep, done: number, total: number) => void
	signal?: AbortSignal
}

export interface ClientConvertResult {
	fileId: number
	path: string
	/** false when the new book could not be confirmed in the library, the original was kept then */
	indexed: boolean
	originalDeleted: boolean
}

interface SourcePage {
	/** entry name in the source archive */
	source: string
	/** final entry name, e.g. 0001.jpg */
	name: string
	data: Uint8Array
}

/**
 * @param path
 */
async function davExists(path: string): Promise<boolean> {
	const res = await axios.head(davUrlForPath(path), { validateStatus: () => true })
	return res.status >= 200 && res.status < 300
}

/**
 * Downloads the file with progress.
 *
 * @param book
 * @param onProgress
 * @param signal
 */
async function download(book: Book, onProgress: (done: number, total: number) => void, signal?: AbortSignal): Promise<Blob> {
	const res = await fetch(davUrlForPath(book.path), { credentials: 'same-origin', signal })
	if (!res.ok) {
		throw new ConvertError(res.status, `Could not download the comic (${res.status})`)
	}
	const total = Number(res.headers.get('content-length')) || book.size
	if (!res.body) {
		return await res.blob()
	}
	const reader = res.body.getReader()
	const chunks: BlobPart[] = []
	let done = 0
	for (;;) {
		const { value, done: finished } = await reader.read()
		if (finished) {
			break
		}
		chunks.push(value as BlobPart)
		done += value.byteLength
		onProgress(done, total)
	}
	return new Blob(chunks)
}

/**
 * @param data
 */
async function imageSize(data: Uint8Array): Promise<{ width: number, height: number }> {
	try {
		const bitmap = await createImageBitmap(new Blob([data as BlobPart]))
		const size = { width: bitmap.width, height: bitmap.height }
		bitmap.close()
		return size
	} catch {
		return { width: 1000, height: 1500 }
	}
}

const MIME: Record<string, string> = {
	cbz: 'application/comicbook+zip',
	cb7: 'application/x-cb7',
	cbt: 'application/x-cbt',
	epub: 'application/epub+zip',
}

/**
 * Converts a comic in the browser and uploads the result next to the original.
 *
 * @param book
 * @param target
 * @param options
 */
export async function convertInBrowser(book: Book, target: ConvertFormat, options: ClientConvertOptions): Promise<ClientConvertResult> {
	const { signal, onStep, onProgress } = options
	if (!browserCanConvert(book.format, target)) {
		throw new ConvertError(415, 'The browser cannot do this conversion')
	}
	const path = targetPath(book.path, target)
	if (await davExists(path)) {
		throw new ConvertError(409, 'A file with this name already exists')
	}

	onStep?.('download')
	const blob = await download(book, (done, total) => onProgress?.('download', done, total), signal)

	onStep?.('convert')
	const loader = await makeArchiveLoader(blob, { loadLibarchive }, book.format)
	let result: Blob
	let oldPages: string[]
	/** page names in the result (same order as oldPages), used to carry over the reading position */
	let newPages: string[]
	try {
		oldPages = pageEntries(loader.entries.map((e) => e.filename))
		if (oldPages.length === 0) {
			throw new ConvertError(422, 'The comic contains no pages')
		}
		const infoName = loader.entries.map((e) => e.filename).find((n) => (n.split('/').pop() ?? '').toLowerCase() === 'comicinfo.xml')
		const infoText = infoName ? await loader.loadText(infoName) : null
		const pages: SourcePage[] = []
		for (const [i, source] of oldPages.entries()) {
			signal?.throwIfAborted()
			const page = await loader.loadBlob(source)
			if (!page) {
				throw new ConvertError(422, `Page ${i + 1} cannot be read`)
			}
			pages.push({ source, name: pageName(i, oldPages.length, source), data: new Uint8Array(await page.arrayBuffer()) })
			onProgress?.('convert', i + 1, oldPages.length)
		}
		const meta = {
			title: book.title,
			authors: book.authors,
			series: book.series,
			seriesIndex: book.seriesIndex,
			description: book.description,
			language: book.language,
			publisher: book.publisher,
			publishedAt: book.publishedAt,
			genres: book.genres,
			tags: book.tags,
		}
		const comicInfo = infoText ?? buildComicInfo(meta)
		newPages = target === 'epub' ? [] : pages.map((p) => p.name)
		if (target === 'epub') {
			const epubPages: EpubPage[] = []
			for (const p of pages) {
				epubPages.push({ name: p.name, data: p.data, ...await imageSize(p.data) })
			}
			result = new Blob([buildEpub(epubPages, meta, comicInfoIsRtl(infoText), comicInfoCoverIndex(infoText)) as BlobPart], { type: MIME.epub })
		} else if (target === 'cbz') {
			const files: Record<string, [Uint8Array, { level: 0 | 6 }]> = {}
			for (const p of pages) {
				files[p.name] = [p.data, { level: 0 }]
			}
			if (comicInfo) {
				files['ComicInfo.xml'] = [new TextEncoder().encode(comicInfo), { level: 6 }]
			}
			result = new Blob([zipSync(files) as BlobPart], { type: MIME.cbz })
		} else {
			const files = pages.map((p) => ({ name: p.name, data: p.data }))
			if (comicInfo) {
				files.push({ name: 'ComicInfo.xml', data: new TextEncoder().encode(comicInfo) })
			}
			const packed = target === 'cb7' ? writeSevenZip(files) : writeTar(files)
			result = new Blob([packed as BlobPart], { type: MIME[target] })
		}
	} finally {
		loader.close()
	}

	onStep?.('upload')
	signal?.throwIfAborted()
	let fileId: number
	try {
		const res = await axios.put(davUrlForPath(path), result, {
			// never overwrite a file that appeared in the meantime
			headers: { 'Content-Type': MIME[target], 'If-None-Match': '*' },
			signal,
			onUploadProgress: (e) => onProgress?.('upload', e.loaded, e.total ?? result.size),
		})
		fileId = parseInt(String(res.headers['oc-fileid'] ?? ''), 10) || 0
	} catch (e) {
		if ((e as { response?: { status?: number } }).response?.status === 412) {
			throw new ConvertError(409, 'A file with this name already exists')
		}
		throw e
	}

	onStep?.('index')
	// The server indexes the uploaded file right away (also large files, which the file listener
	// would only queue), copies the sidecar, carries over rating/status/position and then deletes
	// the original if requested.
	const name = path.split('/').pop() ?? ''
	const adopted = await adoptConversion(book.fileId, { name, deleteOriginal: options.deleteOriginal, oldPages, newPages })
	return { fileId: adopted.fileId || fileId, path: adopted.path || path, indexed: true, originalDeleted: adopted.originalDeleted }
}
