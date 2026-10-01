/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Shelf } from '../types.ts'

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as api from '../services/api.ts'
import { useShelvesStore } from './shelves.ts'

vi.mock('../services/api.ts', () => ({
	listShelves: vi.fn(),
	createShelf: vi.fn(),
	patchShelf: vi.fn(),
	deleteShelf: vi.fn(),
	addToShelf: vi.fn(),
	removeFromShelf: vi.fn(),
}))

const mocked = vi.mocked(api)

/**
 * @param id
 * @param extra
 */
function shelf(id: number, extra: Partial<Shelf> = {}): Shelf {
	return { id, name: `Shelf ${id}`, type: 'manual', query: null, count: 0, coverFileIds: [], sortOrder: id, createdAt: 0, updatedAt: 0, ...extra }
}

describe('shelves store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.resetAllMocks()
	})

	it('loads and splits manual and smart shelves', async () => {
		mocked.listShelves.mockResolvedValue([shelf(1), shelf(2, { type: 'smart' })])
		const store = useShelvesStore()
		await store.load()
		expect(store.manual.map((s) => s.id)).toEqual([1])
		expect(store.smart.map((s) => s.id)).toEqual([2])
		expect(store.loaded).toBe(true)
	})

	it('keeps the list when loading fails', async () => {
		mocked.listShelves.mockResolvedValueOnce([shelf(1)]).mockRejectedValueOnce(new Error('x'))
		const store = useShelvesStore()
		await store.load()
		await store.load()
		expect(store.shelves).toHaveLength(1)
	})

	it('creates, renames, updates the query and deletes', async () => {
		const store = useShelvesStore()
		mocked.createShelf.mockResolvedValue(shelf(3, { type: 'smart' }))
		const query = { include: ['tag:a'], exclude: [], match: 'all' as const, search: '', status: null, sort: 'title', order: 'asc' as const }
		await store.create('S', 'smart', query)
		expect(mocked.createShelf).toHaveBeenCalledWith({ name: 'S', type: 'smart', query })
		await store.create('M', 'manual', query)
		expect(mocked.createShelf).toHaveBeenLastCalledWith({ name: 'M', type: 'manual', query: undefined })

		mocked.patchShelf.mockResolvedValue(shelf(3, { name: 'New', type: 'smart' }))
		await store.rename(3, 'New')
		expect(store.byId(3)?.name).toBe('New')
		await store.updateQuery(3, query)
		expect(mocked.patchShelf).toHaveBeenLastCalledWith(3, { query })

		await store.remove(3)
		expect(store.byId(3)).toBeUndefined()
	})

	it('moves a shelf and rolls back on failure', async () => {
		mocked.listShelves.mockResolvedValue([shelf(1, { sortOrder: 0 }), shelf(2, { sortOrder: 1 }), shelf(3, { sortOrder: 2 })])
		mocked.patchShelf.mockResolvedValue(shelf(1))
		const store = useShelvesStore()
		await store.load()
		await store.move(3, -1)
		expect(store.shelves.map((s) => s.id)).toEqual([1, 3, 2])
		expect(mocked.patchShelf).toHaveBeenCalledWith(3, { sortOrder: 1 })
		expect(mocked.patchShelf).toHaveBeenCalledWith(2, { sortOrder: 2 })

		mocked.patchShelf.mockRejectedValue(new Error('x'))
		await expect(store.move(1, 1)).rejects.toThrow()
		expect(store.shelves.map((s) => s.id)).toEqual([1, 3, 2])

		// moving the first shelf up is a no-op
		const calls = mocked.patchShelf.mock.calls.length
		await store.move(1, -1)
		expect(mocked.patchShelf.mock.calls.length).toBe(calls)
	})

	it('adds books to several shelves and sums the results', async () => {
		mocked.addToShelf.mockResolvedValueOnce({ added: 2, skipped: 1 }).mockResolvedValueOnce({ added: 3, skipped: 0 })
		mocked.listShelves.mockResolvedValue([])
		const store = useShelvesStore()
		expect(await store.addBooks([1, 2], [10, 11, 12])).toEqual({ added: 5, skipped: 1 })
		expect(mocked.addToShelf).toHaveBeenCalledWith(2, [10, 11, 12])
		expect(mocked.listShelves).toHaveBeenCalledTimes(1)
	})

	it('removes books from a shelf', async () => {
		mocked.removeFromShelf.mockResolvedValue({ removed: 2 })
		mocked.listShelves.mockResolvedValue([])
		const store = useShelvesStore()
		expect(await store.removeBooks(1, [5, 6])).toBe(2)
		expect(mocked.removeFromShelf).toHaveBeenCalledWith(1, [5, 6])
	})
})
