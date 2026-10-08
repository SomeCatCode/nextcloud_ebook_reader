/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { FolderEntry } from '../types.ts'

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import * as api from '../services/api.ts'
import { folderChildren, folderCrumbs } from '../services/folders.ts'

export const useFoldersStore = defineStore('folders', () => {
	const folders = ref<FolderEntry[]>([])
	const loaded = ref(false)
	const loading = ref(false)
	/** the folder list could not be loaded (e.g. a server without the folder view) */
	const failed = ref(false)

	const topLevel = computed(() => folderChildren(folders.value, null))

	/**
	 * Loads the flat folder list; the list stays as it is on errors.
	 */
	async function load(): Promise<void> {
		loading.value = true
		try {
			folders.value = (await api.listFolders()) ?? []
			failed.value = false
			loaded.value = true
		} catch {
			failed.value = true
		} finally {
			loading.value = false
		}
	}

	/**
	 * @param path
	 */
	function byPath(path: string): FolderEntry | undefined {
		return folders.value.find((f) => f.path === path)
	}

	/**
	 * Subfolders of a folder; null = top level.
	 *
	 * @param parent
	 */
	function childrenOf(parent: string | null): FolderEntry[] {
		return folderChildren(folders.value, parent)
	}

	/**
	 * @param path
	 */
	function crumbsOf(path: string | null): FolderEntry[] {
		return folderCrumbs(folders.value, path)
	}

	/**
	 * Updates the share count of a folder after (un)sharing.
	 *
	 * @param path
	 * @param sharedWith
	 */
	function setSharedWith(path: string, sharedWith: number): void {
		folders.value = folders.value.map((f) => (f.path === path ? { ...f, sharedWith } : f))
	}

	return { folders, loaded, loading, failed, topLevel, load, byPath, childrenOf, crumbsOf, setSharedWith }
})
