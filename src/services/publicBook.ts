/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Book of a public link share (reader without login, see PublicReaderController). There is no library row: the reader
 * gets name, format and size from the initial state, loads the file from the share and keeps position and view settings
 * in the browser only.
 */
import type { ReaderLocator } from '../../packages/reader-core/index.ts'
import type { Book, BookFormat } from '../types.ts'
import type { ConfirmLargeDownload } from './largeDownload.ts'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { ensureDownloadConfirmed } from './largeDownload.ts'

export interface PublicBook {
	token: string
	/** path inside a shared folder ('' for a shared file) */
	path: string
	name: string
	format: BookFormat
	size: number
	etag: string
	/** false for a share with "hide download" or downloads disabled */
	downloadable: boolean
}

const SETTINGS_KEY = 'ebookreader:public:settings'

/**
 * The public book of this page, or null on the normal (logged in) app pages.
 */
export function loadPublicBook(): PublicBook | null {
	try {
		return loadState<PublicBook | null>('ebookreader', 'public-book', null)
	} catch {
		return null
	}
}

/**
 * Library-like book object for the reader UI (no app data, nothing editable).
 *
 * @param pb
 */
export function publicBookAsBook(pb: PublicBook): Book {
	return {
		fileId: 0,
		format: pb.format,
		path: pb.name,
		size: pb.size,
		title: pb.name.replace(/\.[^.]+$/, ''),
		authors: [],
		series: null,
		seriesIndex: null,
		description: null,
		language: null,
		publisher: null,
		isbn: null,
		publishedAt: null,
		genres: [],
		tags: [],
		rating: null,
		readStatus: 'unread',
		hasCover: false,
		coverEtag: null,
		mtime: 0,
		addedAt: 0,
		updatedAt: 0,
		editable: false,
		downloadable: pb.downloadable,
		owner: '',
		shared: true,
		overrides: [],
		hasSidecar: false,
		completion: null,
		ageRating: null,
		ageRatingManual: false,
		progress: null,
	}
}

/**
 * @param pb
 */
export function publicFileUrl(pb: PublicBook): string {
	return generateUrl('/apps/ebookreader/s/{token}/file', { token: pb.token }) + '?path=' + encodeURIComponent(pb.path)
}

/**
 * Downloads the whole book from the share (a large file asks first).
 *
 * @param pb
 * @param signal
 * @param confirmLarge
 */
export async function loadPublicSource(pb: PublicBook, signal: AbortSignal, confirmLarge?: ConfirmLargeDownload): Promise<Blob> {
	await ensureDownloadConfirmed(pb, confirmLarge)
	const res = await fetch(publicFileUrl(pb), { credentials: 'same-origin', signal })
	if (!res.ok) {
		throw new Error(`Could not load the book (${res.status})`)
	}
	return await res.blob()
}

/**
 * @param pb
 */
function positionKey(pb: PublicBook): string {
	return `ebookreader:public:position:${pb.token}:${pb.path}:${pb.etag}`
}

/**
 * Last position in this browser (null when there is none or storage is blocked).
 *
 * @param pb
 */
export function loadPublicPosition(pb: PublicBook): ReaderLocator | null {
	try {
		const raw = localStorage.getItem(positionKey(pb))
		return raw ? JSON.parse(raw) as ReaderLocator : null
	} catch {
		return null
	}
}

/**
 * @param pb
 * @param locator
 */
export function savePublicPosition(pb: PublicBook, locator: ReaderLocator): void {
	try {
		localStorage.setItem(positionKey(pb), JSON.stringify(locator))
	} catch {
		// storage blocked or full: the position is not kept
	}
}

/**
 * View settings of the public reader in this browser.
 */
export function loadPublicSettings(): Record<string, unknown> | null {
	try {
		const raw = localStorage.getItem(SETTINGS_KEY)
		const parsed = raw ? JSON.parse(raw) as unknown : null
		return parsed && typeof parsed === 'object' ? parsed as Record<string, unknown> : null
	} catch {
		return null
	}
}

/**
 * @param settings
 */
export function savePublicSettings(settings: object): void {
	try {
		localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings))
	} catch {
		// storage blocked or full: the settings are not kept
	}
}
