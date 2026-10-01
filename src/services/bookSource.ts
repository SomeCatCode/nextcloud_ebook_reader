/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { ReaderSource } from '../../packages/reader-core/index.ts'
import type { Book } from '../types.ts'

import { comicPageUrl, fetchBookBlob, getComicPages } from './api.ts'

/**
 * Width in device pixels a comic page is requested with: enough for the viewport (a page is
 * roughly 2:3, so the height also bounds the useful width); the server rounds up to a bucket.
 */
function comicPageWidth(): number {
	const dpr = Math.min(window.devicePixelRatio || 1, 2)
	return Math.min(window.innerWidth, window.innerHeight * 0.75) * dpr
}

/**
 * CBZ comes page by page from the server (scaled, cached); everything else, and CBZ when the
 * page list is not available, is downloaded as a whole via WebDAV.
 *
 * @param b
 * @param signal
 */
export async function loadBookSource(b: Book, signal: AbortSignal): Promise<ReaderSource> {
	const name = b.path.split('/').pop() ?? `book.${b.format}`
	if (b.format === 'cbz') {
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
	const blob = await fetchBookBlob(b, signal)
	return new File([blob], name, { type: blob.type })
}
