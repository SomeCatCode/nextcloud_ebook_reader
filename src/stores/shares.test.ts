/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Share, Shelf } from '../types.ts'

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as api from '../services/api.ts'
import { matchesTarget, useSharesStore } from './shares.ts'
import { useShelvesStore } from './shelves.ts'

vi.mock('../services/api.ts', () => ({
	listShares: vi.fn(),
	shareBook: vi.fn(),
	unshareBook: vi.fn(),
	shareShelf: vi.fn(),
	unshareShelf: vi.fn(),
	listShelves: vi.fn(),
	patchShelf: vi.fn(),
}))

const mocked = vi.mocked(api)

/**
 * @param extra
 */
function share(extra: Partial<Share> = {}): Share {
	return { type: 'book', fileId: 1, shelfId: null, name: 'B', owner: 'alice', ownerDisplayName: 'Alice', recipient: 'bob', recipientDisplayName: 'Bob', createdAt: 0, bookCount: 1, ...extra }
}

/**
 * @param id
 * @param extra
 */
function shelf(id: number, extra: Partial<Shelf> = {}): Shelf {
	return { id, name: `Shelf ${id}`, type: 'manual', query: null, count: 0, coverFileIds: [], sortOrder: id, createdAt: 0, updatedAt: 0, ...extra }
}

describe('shares store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.resetAllMocks()
		mocked.listShelves.mockResolvedValue([])
	})

	it('loads the overview and finds the recipients of a target', async () => {
		mocked.listShares.mockResolvedValue({
			outgoing: [share(), share({ recipient: 'carol' }), share({ type: 'shelf', fileId: null, shelfId: 1 })],
			incoming: [share({ owner: 'dave' })],
		})
		const store = useSharesStore()
		await store.load()
		expect(store.loaded).toBe(true)
		expect(store.hasAny).toBe(true)
		expect(store.recipientsOf({ type: 'book', id: 1 }).map((s) => s.recipient)).toEqual(['bob', 'carol'])
		// a shelf id never matches a book with the same number
		expect(store.recipientsOf({ type: 'shelf', id: 1 })).toHaveLength(1)
		expect(matchesTarget(share({ type: 'shelf', fileId: null, shelfId: 1 }), { type: 'book', id: 1 })).toBe(false)
	})

	it('keeps the lists and resets loading when loading fails', async () => {
		mocked.listShares.mockResolvedValueOnce({ outgoing: [share()], incoming: [] }).mockRejectedValueOnce(new Error('x'))
		const store = useSharesStore()
		await store.load()
		await expect(store.load()).rejects.toThrow()
		expect(store.outgoing).toHaveLength(1)
		expect(store.loading).toBe(false)
	})

	it('shares a book and replaces an existing entry for the same user', async () => {
		mocked.shareBook.mockResolvedValue({ share: share({ createdAt: 5 }), skipped: 0 })
		const store = useSharesStore()
		await store.share({ type: 'book', id: 1 }, 'bob')
		await store.share({ type: 'book', id: 1 }, 'bob')
		expect(mocked.shareBook).toHaveBeenCalledWith(1, 'bob')
		expect(store.outgoing).toHaveLength(1)
		expect(mocked.listShelves).not.toHaveBeenCalled()
	})

	it('shares a shelf and refreshes the shelf list (share counter)', async () => {
		mocked.shareShelf.mockResolvedValue({ share: share({ type: 'shelf', fileId: null, shelfId: 3 }), skipped: 2 })
		const store = useSharesStore()
		const res = await store.share({ type: 'shelf', id: 3 }, 'bob')
		expect(res.skipped).toBe(2)
		expect(mocked.shareShelf).toHaveBeenCalledWith(3, 'bob')
		expect(mocked.listShelves).toHaveBeenCalled()
	})

	it('unshares as owner', async () => {
		mocked.listShares.mockResolvedValue({ outgoing: [share(), share({ recipient: 'carol' })], incoming: [] })
		const store = useSharesStore()
		await store.load()
		await store.unshare({ type: 'book', id: 1 }, 'bob')
		expect(mocked.unshareBook).toHaveBeenCalledWith(1, { shareWith: 'bob' })
		expect(store.outgoing.map((s) => s.recipient)).toEqual(['carol'])

		await store.unshare({ type: 'shelf', id: 7 }, 'carol')
		expect(mocked.unshareShelf).toHaveBeenCalledWith(7, 'carol')
	})

	it('leaves incoming shares as recipient', async () => {
		const book = share({ owner: 'alice', recipient: 'me' })
		const sh = share({ type: 'shelf', fileId: null, shelfId: 4, owner: 'alice', recipient: 'me' })
		mocked.listShares.mockResolvedValue({ outgoing: [], incoming: [book, sh] })
		const store = useSharesStore()
		await store.load()
		await store.leave(book)
		expect(mocked.unshareBook).toHaveBeenCalledWith(1, { sharedBy: 'alice' })
		// a copy of the entry (e.g. built from the shelf list) removes it as well
		await store.leave({ ...sh, owner: '' })
		expect(mocked.unshareShelf).toHaveBeenCalledWith(4)
		expect(store.incoming).toEqual([])
	})
})

describe('shelves store with shared shelves', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.resetAllMocks()
	})

	it('separates own and incoming shelves; manual/smart only list own ones', async () => {
		mocked.listShelves.mockResolvedValue([
			shelf(1),
			shelf(2, { type: 'smart' }),
			shelf(9, { readOnly: true, owner: 'alice' }),
			shelf(10, { type: 'smart', readOnly: true, owner: 'alice' }),
		])
		const store = useShelvesStore()
		await store.load()
		expect(store.own.map((s) => s.id)).toEqual([1, 2])
		expect(store.incoming.map((s) => s.id)).toEqual([9, 10])
		expect(store.manual.map((s) => s.id)).toEqual([1])
		expect(store.smart.map((s) => s.id)).toEqual([2])
	})

	it('moves only own shelves and never patches shared ones', async () => {
		mocked.listShelves.mockResolvedValue([shelf(1, { sortOrder: 0 }), shelf(2, { sortOrder: 1 }), shelf(9, { readOnly: true, sortOrder: 0 })])
		mocked.patchShelf.mockResolvedValue(shelf(1))
		const store = useShelvesStore()
		await store.load()
		await store.move(2, 1) // already last of the own shelves
		expect(mocked.patchShelf).not.toHaveBeenCalled()
		await store.move(2, -1)
		expect(store.shelves.map((s) => s.id)).toEqual([2, 1, 9])
		expect(mocked.patchShelf.mock.calls.map((c) => c[0]).sort()).toEqual([1, 2])
		await store.move(9, -1)
		expect(mocked.patchShelf).not.toHaveBeenCalledWith(9, expect.anything())
	})
})
