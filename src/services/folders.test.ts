/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { FolderEntry } from '../types.ts'

import { describe, expect, it } from 'vitest'
import { filesAppUrl, folderChildren, folderCrumbs } from './folders.ts'

/**
 * @param path
 * @param parent
 * @param extra
 */
function folder(path: string, parent: string | null, extra: Partial<FolderEntry> = {}): FolderEntry {
	return { path, name: path.split('/').pop() ?? path, parent, bookCount: 1, totalCount: 1, sharedWith: 0, shared: false, ...extra }
}

const list = [
	folder('/Books', null, { totalCount: 5 }),
	folder('/Books/Comics', '/Books'),
	folder('/Books/Comics/Saga', '/Books/Comics'),
	folder('/Books/Novels', '/Books'),
	folder('/Shared', null, { shared: true }),
]

describe('folder helpers', () => {
	it('lists the direct subfolders and the top level', () => {
		expect(folderChildren(list, null).map((f) => f.path)).toEqual(['/Books', '/Shared'])
		expect(folderChildren(list, '/Books').map((f) => f.path)).toEqual(['/Books/Comics', '/Books/Novels'])
		expect(folderChildren(list, '/Books/Novels')).toEqual([])
	})

	it('builds the breadcrumb from the parent links', () => {
		expect(folderCrumbs(list, null)).toEqual([])
		expect(folderCrumbs(list, '/Books/Comics/Saga').map((f) => f.name)).toEqual(['Books', 'Comics', 'Saga'])
		expect(folderCrumbs(list, '/Books').map((f) => f.path)).toEqual(['/Books'])
	})

	it('still shows a folder that is not in the list and survives a cyclic parent chain', () => {
		expect(folderCrumbs(list, '/Elsewhere/Deep')).toEqual([expect.objectContaining({ path: '/Elsewhere/Deep', name: 'Deep' })])
		const cyclic = [folder('/a', '/b'), folder('/b', '/a')]
		expect(folderCrumbs(cyclic, '/a').length).toBeLessThanOrEqual(64)
	})

	it('builds the link into the Files app', () => {
		expect(filesAppUrl('/Books/Comics & More')).toBe('/apps/files/files?dir=%2FBooks%2FComics%20%26%20More')
		expect(filesAppUrl('')).toBe('/apps/files/files?dir=%2F')
	})
})
