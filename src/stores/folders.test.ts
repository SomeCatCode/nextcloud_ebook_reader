/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { FolderEntry } from '../types.ts'

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as api from '../services/api.ts'
import { useFoldersStore } from './folders.ts'

vi.mock('../services/api.ts', () => ({ listFolders: vi.fn() }))

const mocked = vi.mocked(api)

/**
 * @param path
 * @param parent
 */
function folder(path: string, parent: string | null): FolderEntry {
	return { path, name: path.split('/').pop() ?? '', parent, bookCount: 1, totalCount: 2, sharedWith: 0, shared: false }
}

describe('folders store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.resetAllMocks()
	})

	it('loads the flat list and answers children and breadcrumbs', async () => {
		mocked.listFolders.mockResolvedValue([folder('/Books', null), folder('/Books/A', '/Books')])
		const store = useFoldersStore()
		await store.load()
		expect(store.loaded).toBe(true)
		expect(store.topLevel.map((f) => f.path)).toEqual(['/Books'])
		expect(store.childrenOf('/Books').map((f) => f.name)).toEqual(['A'])
		expect(store.crumbsOf('/Books/A').map((f) => f.name)).toEqual(['Books', 'A'])
		expect(store.byPath('/Books/A')?.parent).toBe('/Books')
	})

	it('keeps the list and flags the failure when loading fails (server without folders)', async () => {
		mocked.listFolders.mockResolvedValueOnce([folder('/Books', null)]).mockRejectedValueOnce(new Error('404'))
		const store = useFoldersStore()
		await store.load()
		await store.load()
		expect(store.failed).toBe(true)
		expect(store.folders).toHaveLength(1)
		expect(store.loading).toBe(false)
	})

	it('updates the share counter of one folder', async () => {
		mocked.listFolders.mockResolvedValue([folder('/Books', null), folder('/Other', null)])
		const store = useFoldersStore()
		await store.load()
		store.setSharedWith('/Books', 3)
		expect(store.byPath('/Books')?.sharedWith).toBe(3)
		expect(store.byPath('/Other')?.sharedWith).toBe(0)
	})
})
