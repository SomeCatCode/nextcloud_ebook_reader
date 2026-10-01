/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Settings } from '../types.ts'

import { loadState } from '@nextcloud/initial-state'
import { defineStore } from 'pinia'
import { ref } from 'vue'
import * as api from '../services/api.ts'

/**
 *
 */
function fallback(): Settings {
	return {
		libraryFolders: ['/Books'],
		reader: {
			theme: 'auto',
			fontSize: 100,
			fontFamily: '',
			lineHeight: 1.5,
			margin: 3,
			layout: 'paginated',
			flow: 'paginated',
			maxColumns: 2,
		},
		filenamePattern: '{author} - {title}',
		genreList: null,
		metadataWriteMode: 'background',
	}
}

export const useSettingsStore = defineStore('settings', () => {
	const settings = ref<Settings>(loadState<Settings>('ebookreader', 'settings', fallback()))
	const saving = ref(false)

	/**
	 * Persists (partial) settings and adopts the server's normalised answer.
	 *
	 * @param patch
	 */
	async function save(patch: Partial<Settings>): Promise<Settings> {
		saving.value = true
		try {
			settings.value = await api.putSettings(patch)
			return settings.value
		} finally {
			saving.value = false
		}
	}

	/**
	 * Reloads settings from the server (e.g. to get the resolved default genre list).
	 */
	async function refresh(): Promise<void> {
		settings.value = await api.getSettings()
	}

	return { settings, saving, save, refresh }
})
