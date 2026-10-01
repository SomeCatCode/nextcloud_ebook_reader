/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { ReaderSource } from '../../packages/reader-core/index.ts'
import type { Book } from '../types.ts'
import type { ConfirmLargeDownload } from './largeDownload.ts'

import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { archiveEntries, comicPageUrl, fetchBookBlob, getComicPages, itemUrl } from './api.ts'
import { ensureDownloadConfirmed } from './largeDownload.ts'

const SERVER_PAGE_FORMATS: string[] = ['cbz', 'cbt', 'cbr', 'cb7']
/** ZIP formats read entry by entry from the server (no complete download) */
const REMOTE_ZIP_FORMATS: string[] = ['epub', 'fbz']
/** Formats for which a complete download in the browser is a fallback that needs a confirmation when large */
const CONFIRM_FALLBACK_FORMATS: string[] = ['epub', 'fbz', 'cbz', 'cbr', 'cb7', 'cbt']
/** Formats whose cover cannot always be extracted on the server */
const CLIENT_COVER_FORMATS: string[] = ['cbr', 'cb7', 'cbt']
const COVER_MAX_WIDTH = 600
const COVER_JPEG_QUALITY = 0.85

/**
 * Width in device pixels a comic page is requested with: enough for the viewport (a page is
 * roughly 2:3, so the height also bounds the useful width); the server rounds up to a bucket.
 */
function comicPageWidth(): number {
	const dpr = Math.min(window.devicePixelRatio || 1, 2)
	return Math.min(window.innerWidth, window.innerHeight * 0.75) * dpr
}

/**
 * Comics come page by page from the server when it can read them (CBZ, CBT, and CBR/CB7 with an
 * archive tool installed; scaled and cached); everything else, and comics when the page list is not
 * available, is downloaded as a whole via WebDAV and unpacked in the browser.
 *
 * EPUB and FBZ are read entry by entry (archive entry list + /item), falling back to the whole file.
 * A complete download of a file over 50 MB asks `confirmLarge` first (DownloadDeclinedError if declined).
 *
 * @param b
 * @param signal
 * @param confirmLarge
 */
export async function loadBookSource(b: Book, signal: AbortSignal, confirmLarge?: ConfirmLargeDownload): Promise<ReaderSource> {
	const name = b.path.split('/').pop() ?? `book.${b.format}`
	if (SERVER_PAGE_FORMATS.includes(b.format)) {
		try {
			const { etag, pages } = await getComicPages(b.fileId, signal)
			if (pages.length > 0) {
				const width = comicPageWidth()
				return {
					kind: 'remote-comic',
					name,
					pages,
					loadPage: async (index) => {
						const res = await fetch(comicPageUrl(b.fileId, index, width, etag), { credentials: 'same-origin' })
						if (!res.ok) {
							throw new Error(`Could not load page ${index + 1} (${res.status})`)
						}
						return await res.blob()
					},
				}
			}
		} catch (e) {
			if (signal.aborted) {
				throw e
			}
			// Server-side pages not available (e.g. unreadable archive): fall back to the whole file
		}
	}
	if (REMOTE_ZIP_FORMATS.includes(b.format)) {
		try {
			const { etag, entries } = await archiveEntries(b.fileId, signal)
			if (entries.length > 0) {
				return {
					kind: 'remote-zip',
					name,
					entries,
					loadEntry: async (entry) => {
						const res = await fetch(itemUrl(b.fileId, entry, etag), { credentials: 'same-origin' })
						if (!res.ok) {
							throw new Error(`Could not load ${entry} (${res.status})`)
						}
						return await res.blob()
					},
				}
			}
		} catch (e) {
			if (signal.aborted) {
				throw e
			}
			// entry list not available: fall back to the whole file
		}
	}
	if (CONFIRM_FALLBACK_FORMATS.includes(b.format)) {
		await ensureDownloadConfirmed(b, confirmLarge)
	}
	const blob = await fetchBookBlob(b, signal)
	return new File([blob], name, { type: blob.type })
}

/**
 * Whether the browser should generate the cover: a CBR/CB7/CBT that was opened locally (the server
 * could not read it) and still has no cover.
 *
 * @param b
 * @param source what the reader was opened with
 */
export function needsClientCover(b: Pick<Book, 'format' | 'hasCover'>, source: ReaderSource): boolean {
	return !b.hasCover && CLIENT_COVER_FORMATS.includes(b.format) && source instanceof Blob
}

/**
 * Size a cover image is scaled to: at most `maxWidth` wide, aspect ratio kept, never enlarged.
 *
 * @param width
 * @param height
 * @param maxWidth
 */
export function coverSize(width: number, height: number, maxWidth = COVER_MAX_WIDTH): { width: number, height: number } {
	if (width <= maxWidth) {
		return { width, height }
	}
	return { width: maxWidth, height: Math.max(1, Math.round(height * maxWidth / width)) }
}

/**
 * Renders the first page to a JPEG of at most 600 px width.
 *
 * @param page
 */
async function renderCover(page: Blob): Promise<Blob | null> {
	const bitmap = await createImageBitmap(page)
	try {
		const { width, height } = coverSize(bitmap.width, bitmap.height)
		const canvas = document.createElement('canvas')
		canvas.width = width
		canvas.height = height
		const ctx = canvas.getContext('2d')
		if (!ctx) {
			return null
		}
		ctx.fillStyle = '#fff'
		ctx.fillRect(0, 0, width, height)
		ctx.drawImage(bitmap, 0, 0, width, height)
		return await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', COVER_JPEG_QUALITY))
	} finally {
		bitmap.close()
	}
}

/**
 * Fire-and-forget: renders the first page (600 px, JPEG) and posts it as the cover of the book.
 * Errors are ignored, the next opening tries again.
 *
 * @param fileId
 * @param getFirstPage resolves the first page image, e.g. ReaderHandle.getCover
 */
export function uploadClientCover(fileId: number, getFirstPage: () => Promise<Blob | null>): void {
	void (async () => {
		try {
			const page = await getFirstPage()
			if (!page) {
				return
			}
			const jpeg = await renderCover(page)
			if (!jpeg) {
				return
			}
			await fetch(generateUrl('/apps/ebookreader/cover/{fileId}', { fileId }), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'image/jpeg', requesttoken: getRequestToken() ?? '' },
				body: jpeg,
			})
		} catch {
			// ignore: the cover is optional
		}
	})()
}
