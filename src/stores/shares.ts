/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Share, ShareCreated, ShareType } from '../types.ts'

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import * as api from '../services/api.ts'
import { useShelvesStore } from './shelves.ts'

/** What is shared: a book (file id) or a shelf (shelf id). */
export interface ShareTarget {
	type: ShareType
	id: number
	name: string
}

/**
 * @param share
 * @param target
 */
export function matchesTarget(share: Share, target: Pick<ShareTarget, 'type' | 'id'>): boolean {
	return share.type === target.type && (target.type === 'book' ? share.fileId === target.id : share.shelfId === target.id)
}

export const useSharesStore = defineStore('shares', () => {
	const outgoing = ref<Share[]>([])
	const incoming = ref<Share[]>([])
	const loaded = ref(false)
	const loading = ref(false)

	const hasAny = computed(() => outgoing.value.length > 0 || incoming.value.length > 0)

	/**
	 * Loads the overview; the lists stay as they are on errors (the caller shows a message).
	 */
	async function load(): Promise<void> {
		loading.value = true
		try {
			const res = await api.listShares()
			outgoing.value = res.outgoing
			incoming.value = res.incoming
			loaded.value = true
		} finally {
			loading.value = false
		}
	}

	/**
	 * Users a book or shelf is shared with.
	 *
	 * @param target
	 */
	function recipientsOf(target: Pick<ShareTarget, 'type' | 'id'>): Share[] {
		return outgoing.value.filter((s) => matchesTarget(s, target))
	}

	/**
	 * Shares a book or a shelf with a user. Shelf counters in the navigation are refreshed for shelves.
	 *
	 * @param target
	 * @param userId
	 */
	async function share(target: Pick<ShareTarget, 'type' | 'id'>, userId: string): Promise<ShareCreated> {
		const res = target.type === 'book' ? await api.shareBook(target.id, userId) : await api.shareShelf(target.id, userId)
		outgoing.value = [...outgoing.value.filter((s) => !(matchesTarget(s, target) && s.recipient === res.share.recipient)), res.share]
		if (target.type === 'shelf') {
			void useShelvesStore().load()
		}
		return res
	}

	/**
	 * Owner stops sharing with a user.
	 *
	 * @param target
	 * @param userId
	 */
	async function unshare(target: Pick<ShareTarget, 'type' | 'id'>, userId: string): Promise<void> {
		if (target.type === 'book') {
			await api.unshareBook(target.id, { shareWith: userId })
		} else {
			await api.unshareShelf(target.id, userId)
		}
		outgoing.value = outgoing.value.filter((s) => !(matchesTarget(s, target) && s.recipient === userId))
		if (target.type === 'shelf') {
			void useShelvesStore().load()
		}
	}

	/**
	 * Recipient removes a book or shelf shared with them.
	 *
	 * @param share
	 */
	async function leave(share: Share): Promise<void> {
		if (share.type === 'book' && share.fileId !== null) {
			await api.unshareBook(share.fileId, { sharedBy: share.owner })
		} else if (share.type === 'shelf' && share.shelfId !== null) {
			await api.unshareShelf(share.shelfId)
			void useShelvesStore().load()
		}
		incoming.value = incoming.value.filter((s) => !(s.type === share.type
			&& (share.type === 'shelf' ? s.shelfId === share.shelfId : s.fileId === share.fileId && s.owner === share.owner)))
	}

	return { outgoing, incoming, loaded, loading, hasAny, load, recipientsOf, share, unshare, leave }
})
