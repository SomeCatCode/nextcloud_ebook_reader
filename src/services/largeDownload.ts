/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Book } from '../types.ts'

/** Files above this size are only downloaded completely into the browser after a confirmation. */
export const LARGE_DOWNLOAD_BYTES = 50 * 1024 * 1024

/** The user declined the download of a large file. */
export class DownloadDeclinedError extends Error {
	constructor() {
		super('Download declined')
		this.name = 'DownloadDeclinedError'
	}
}

/** Asks the user; resolves true to continue. `sizeBytes` is the file size. */
export type ConfirmLargeDownload = (sizeBytes: number) => Promise<boolean>

/**
 * @param sizeBytes
 */
export function isLargeDownload(sizeBytes: number | null | undefined): boolean {
	return (sizeBytes ?? 0) > LARGE_DOWNLOAD_BYTES
}

/**
 * Size in MB for the dialog text, one decimal place.
 *
 * @param sizeBytes
 */
export function formatMegabytes(sizeBytes: number): string {
	return (sizeBytes / 1048576).toFixed(1)
}

/**
 * Decides before a client fallback downloads a whole file: a confirmation is only asked for large
 * files; declining throws DownloadDeclinedError. Without a `confirm` callback nothing is asked.
 *
 * @param book
 * @param confirm
 */
export async function ensureDownloadConfirmed(book: Pick<Book, 'size'>, confirm?: ConfirmLargeDownload): Promise<void> {
	if (!confirm || !isLargeDownload(book.size)) {
		return
	}
	if (!await confirm(book.size)) {
		throw new DownloadDeclinedError()
	}
}
