/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { FolderEntry } from '../types.ts'

/**
 * Direct subfolders of a folder (null = top level), in the order of the list.
 *
 * @param folders
 * @param parent
 */
export function folderChildren(folders: FolderEntry[], parent: string | null): FolderEntry[] {
	return folders.filter((f) => (f.parent ?? null) === parent)
}

/**
 * Chain of folders from the top level down to `path` (inclusive), for the breadcrumb.
 * A path that is not in the list yields an entry made up from the path itself.
 *
 * @param folders
 * @param path
 */
export function folderCrumbs(folders: FolderEntry[], path: string | null): FolderEntry[] {
	if (path === null) {
		return []
	}
	const byPath = new Map(folders.map((f) => [f.path, f]))
	const out: FolderEntry[] = []
	let current: string | null = path
	// the depth guard protects against a cyclic parent chain from a broken server answer
	for (let depth = 0; current !== null && depth < 64; depth++) {
		const entry: FolderEntry | undefined = byPath.get(current)
		if (!entry) {
			if (depth === 0) {
				out.unshift({ path: current, name: current.split('/').filter(Boolean).pop() ?? current, parent: null, bookCount: 0, totalCount: 0, sharedWith: 0, shared: false })
			}
			break
		}
		out.unshift(entry)
		current = entry.parent ?? null
	}
	return out
}

/**
 * Link target (relative to the Nextcloud root) that shows a folder in the Files app.
 *
 * @param path
 */
export function filesAppUrl(path: string): string {
	return '/apps/files/files?dir=' + encodeURIComponent(path || '/')
}
