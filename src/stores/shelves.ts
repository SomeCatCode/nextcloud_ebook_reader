/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Shelf, ShelfBooksResult, SmartQuery } from '../types.ts'

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import * as api from '../services/api.ts'

export const useShelvesStore = defineStore('shelves', () => {
	const shelves = ref<Shelf[]>([])
	const loaded = ref(false)

	/** own shelves (shelves shared with the user are read-only and listed in `incoming`) */
	const own = computed(() => shelves.value.filter((s) => !s.readOnly))
	const incoming = computed(() => shelves.value.filter((s) => s.readOnly === true))
	const manual = computed(() => own.value.filter((s) => s.type === 'manual'))
	const smart = computed(() => own.value.filter((s) => s.type === 'smart'))

	/**
	 * @param id
	 */
	function byId(id: number): Shelf | undefined {
		return shelves.value.find((s) => s.id === id)
	}

	/**
	 * Loads shelves and counts; the list stays as it is on errors.
	 */
	async function load(): Promise<void> {
		try {
			shelves.value = await api.listShelves()
			loaded.value = true
		} catch {
			// navigation keeps the old list
		}
	}

	/**
	 * @param name
	 * @param type
	 * @param query only for smart shelves
	 */
	async function create(name: string, type: 'manual' | 'smart', query?: SmartQuery): Promise<Shelf> {
		const shelf = await api.createShelf({ name, type, query: type === 'smart' ? query : undefined })
		shelves.value = [...shelves.value, shelf]
		return shelf
	}

	/**
	 * @param id
	 * @param name
	 */
	async function rename(id: number, name: string): Promise<Shelf> {
		const shelf = await api.patchShelf(id, { name })
		replace(shelf)
		return shelf
	}

	/**
	 * Replaces the saved query of a smart shelf.
	 *
	 * @param id
	 * @param query
	 */
	async function updateQuery(id: number, query: SmartQuery): Promise<Shelf> {
		const shelf = await api.patchShelf(id, { query })
		replace(shelf)
		return shelf
	}

	/**
	 * @param id
	 */
	async function remove(id: number): Promise<void> {
		await api.deleteShelf(id)
		shelves.value = shelves.value.filter((s) => s.id !== id)
	}

	/**
	 * Moves a shelf one position up or down and stores the new order (optimistic, rolled back on failure).
	 *
	 * @param id
	 * @param delta -1 up, +1 down
	 */
	async function move(id: number, delta: -1 | 1): Promise<void> {
		// only own shelves have an order; shared ones always follow them
		const list = [...own.value]
		const from = list.findIndex((s) => s.id === id)
		const to = from + delta
		if (from < 0 || to < 0 || to >= list.length) {
			return
		}
		const snapshot = shelves.value
		const [item] = list.splice(from, 1)
		list.splice(to, 0, item!)
		const ordered = list.map((s, i) => ({ ...s, sortOrder: i }))
		shelves.value = [...ordered, ...incoming.value]
		try {
			await Promise.all(ordered.filter((s, i) => s.sortOrder !== snapshot[i]?.sortOrder || s.id !== snapshot[i]?.id)
				.map((s) => api.patchShelf(s.id, { sortOrder: s.sortOrder })))
		} catch (e) {
			shelves.value = snapshot
			throw e
		}
	}

	/**
	 * Adds books to several manual shelves; counts are refreshed afterwards.
	 *
	 * @param shelfIds
	 * @param fileIds
	 */
	async function addBooks(shelfIds: number[], fileIds: number[]): Promise<ShelfBooksResult> {
		const total: ShelfBooksResult = { added: 0, skipped: 0 }
		for (const id of shelfIds) {
			const res = await api.addToShelf(id, fileIds)
			total.added += res.added
			total.skipped += res.skipped
		}
		await load()
		return total
	}

	/**
	 * @param shelfId
	 * @param fileIds
	 */
	async function removeBooks(shelfId: number, fileIds: number[]): Promise<number> {
		const res = await api.removeFromShelf(shelfId, fileIds)
		await load()
		return res.removed
	}

	/**
	 * @param shelf
	 */
	function replace(shelf: Shelf): void {
		shelves.value = shelves.value.map((s) => (s.id === shelf.id ? shelf : s))
	}

	return { shelves, loaded, own, incoming, manual, smart, byId, load, create, rename, updateQuery, remove, move, addBooks, removeBooks }
})
