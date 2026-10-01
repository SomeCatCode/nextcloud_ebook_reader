/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { defaultRootPath, getClient } from '@nextcloud/files/dav'
import { getUploader } from '@nextcloud/upload'

export const ALLOWED_EXTENSIONS = ['epub', 'mobi', 'azw3', 'fb2', 'fbz', 'cbz', 'cbr', 'cb7', 'cbt'] as const

/** Books above this size are indexed by the server with a delay */
export const LARGE_FILE_BYTES = 20 * 1024 * 1024

/**
 * Lower-case extension without the dot ("" if there is none).
 *
 * @param name
 */
export function extensionOf(name: string): string {
	const i = name.lastIndexOf('.')
	return i > 0 && i < name.length - 1 ? name.slice(i + 1).toLowerCase() : ''
}

/**
 * @param name
 */
export function isAllowedName(name: string): boolean {
	return (ALLOWED_EXTENSIONS as readonly string[]).includes(extensionOf(name))
}

/**
 * Splits dropped/selected files into uploadable e-books and rejected ones (wrong extension).
 *
 * @param files
 */
export function partitionFiles<T extends { name: string }>(files: T[]): { accepted: T[], rejected: T[] } {
	const accepted: T[] = []
	const rejected: T[] = []
	for (const f of files) {
		(isAllowedName(f.name) ? accepted : rejected).push(f)
	}
	return { accepted, rejected }
}

/**
 * Returns a name that is not in `taken` (case-insensitive): "Book.epub" becomes "Book (2).epub", "Book (3).epub", ...
 * The result is added to `taken`.
 *
 * @param name
 * @param taken lower-cased names that already exist
 */
export function uniqueName(name: string, taken: Set<string>): string {
	let candidate = name
	if (taken.has(candidate.toLowerCase())) {
		const dot = name.lastIndexOf('.')
		const stem = dot > 0 ? name.slice(0, dot) : name
		const ext = dot > 0 ? name.slice(dot) : ''
		let i = 2
		do {
			candidate = `${stem} (${i})${ext}`
			i++
		} while (taken.has(candidate.toLowerCase()))
	}
	taken.add(candidate.toLowerCase())
	return candidate
}

/**
 * Target names for a batch: existing files in the folder are never overwritten, files of the batch do not clash either.
 *
 * @param names file names in the order of the batch
 * @param existing names already in the target folder
 */
export function assignNames(names: string[], existing: string[]): string[] {
	const taken = new Set(existing.map((n) => n.toLowerCase()))
	return names.map((n) => uniqueName(n, taken))
}

/**
 * @param folder user-relative folder path
 */
function davPath(folder: string): string {
	return defaultRootPath + '/' + folder.replace(/^\/+|\/+$/g, '')
}

/**
 * Names of the entries in a folder (empty if it does not exist).
 *
 * @param folder
 */
export async function listFolderNames(folder: string): Promise<string[]> {
	try {
		const items = await getClient().getDirectoryContents(davPath(folder)) as { basename: string }[]
		return items.map((i) => i.basename)
	} catch (e) {
		if ((e as { status?: number }).status === 404) {
			return []
		}
		throw e
	}
}

/**
 * Creates the folder (and parents) if needed.
 *
 * @param folder
 */
export async function ensureFolder(folder: string): Promise<void> {
	const client = getClient()
	const path = davPath(folder)
	if (!await client.exists(path)) {
		await client.createDirectory(path, { recursive: true })
	}
}

export interface RunningUpload {
	/** 0..1 */
	progress: () => number
	cancel: () => void
	done: Promise<void>
}

/**
 * Uploads one file (chunked by the Nextcloud uploader where needed).
 *
 * @param folder
 * @param name
 * @param file
 */
export function uploadFile(folder: string, name: string, file: File): RunningUpload {
	const uploader = getUploader()
	const dest = '/' + folder.replace(/^\/+|\/+$/g, '') + '/' + name
	const promise = uploader.upload(dest, file)
	return {
		progress: () => {
			const u = uploader.queue.find((q) => q.file === file)
			return u && u.size > 0 ? Math.min(1, u.uploaded / u.size) : 0
		},
		cancel: () => {
			promise.cancel()
			uploader.queue.find((q) => q.file === file)?.cancel()
		},
		done: promise.then(() => undefined),
	}
}
