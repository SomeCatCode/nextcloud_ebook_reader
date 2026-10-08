/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Share, ShareCreated, ShareType } from '../types.ts'

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import * as api from '../services/api.ts'
import { useFoldersStore } from './folders.ts'
import { useLibraryStore } from './library.ts'
import { useShelvesStore } from './shelves.ts'

/** What is shared: a book (file id), a shelf (shelf id), a series (its name) or a folder (its path). */
export interface ShareTarget {
	type: ShareType
	id: number | string
	name: string
}

/**
 * @param share
 * @param target
 */
export function matchesTarget(share: Share, target: Pick<ShareTarget, 'type' | 'id'>): boolean {
	return share.type === target.type && shareTargetId(share) === target.id
}

/**
 * The id a share refers to: file id, shelf id, series name or folder path.
 *
 * @param share
 */
export function shareTargetId(share: Share): number | string | null {
	switch (share.type) {
		case 'book': return share.fileId
		case 'shelf': return share.shelfId
		case 'series': return share.series ?? null
		default: return share.path ?? null
	}
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
			outgoing.value = res.outgoing ?? []
			incoming.value = res.incoming ?? []
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
		const res = await callShare(target, userId)
		outgoing.value = [...outgoing.value.filter((s) => !(matchesTarget(s, target) && s.recipient === res.share.recipient)), res.share]
		refreshAfterChange(target)
		return res
	}

	/**
	 * Owner stops sharing with a user.
	 *
	 * @param target
	 * @param userId
	 */
	async function unshare(target: Pick<ShareTarget, 'type' | 'id'>, userId: string): Promise<void> {
		switch (target.type) {
			case 'book':
				await api.unshareBook(Number(target.id), { shareWith: userId })
				break
			case 'shelf':
				await api.unshareShelf(Number(target.id), userId)
				break
			case 'series':
				await api.unshareSeries(String(target.id), userId)
				break
			default:
				await api.unshareFolder(String(target.id), userId)
		}
		outgoing.value = outgoing.value.filter((s) => !(matchesTarget(s, target) && s.recipient === userId))
		refreshAfterChange(target)
	}

	/**
	 * @param target
	 * @param userId
	 */
	function callShare(target: Pick<ShareTarget, 'type' | 'id'>, userId: string): Promise<ShareCreated> {
		switch (target.type) {
			case 'book': return api.shareBook(Number(target.id), userId)
			case 'shelf': return api.shareShelf(Number(target.id), userId)
			case 'series': return api.shareSeries(String(target.id), userId)
			default: return api.shareFolder(String(target.id), userId)
		}
	}

	/**
	 * Navigation counters of shelves, series and folders change when shares change.
	 *
	 * @param target
	 */
	function refreshAfterChange(target: Pick<ShareTarget, 'type' | 'id'>): void {
		const recipients = recipientsOf(target).length
		if (target.type === 'shelf') {
			void useShelvesStore().load()
		} else if (target.type === 'series') {
			useLibraryStore().setSeriesSharedWith(String(target.id), recipients)
		} else if (target.type === 'folder') {
			useFoldersStore().setSharedWith(String(target.id), recipients)
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
		// series and folder shares are removed by the owner (or in the Nextcloud sharing settings of the folder)
		incoming.value = incoming.value.filter((s) => !(s.type === share.type
			&& shareTargetId(s) === shareTargetId(share) && (share.type === 'shelf' || s.owner === share.owner)))
	}

	return { outgoing, incoming, loaded, loading, hasAny, load, recipientsOf, share, unshare, leave }
})
