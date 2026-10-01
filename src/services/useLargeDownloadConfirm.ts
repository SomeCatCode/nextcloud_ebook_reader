/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Ref } from 'vue'

import { ref } from 'vue'

export interface LargeDownloadState {
	/** set while the dialog should be shown */
	pending: Ref<{ size: number } | null>
	/** pass to loadBookSource/ensureDownloadConfirmed */
	ask: (sizeBytes: number) => Promise<boolean>
	answer: (value: boolean) => void
}

/** State for LargeDownloadDialog.vue: `ask` opens the dialog and resolves with the user's choice. */
export function useLargeDownloadConfirm(): LargeDownloadState {
	const pending = ref<{ size: number } | null>(null)
	let resolver: ((v: boolean) => void) | null = null
	return {
		pending,
		ask: (size) => new Promise((resolve) => {
			resolver?.(false)
			resolver = resolve
			pending.value = { size }
		}),
		answer: (value) => {
			pending.value = null
			const r = resolver
			resolver = null
			r?.(value)
		},
	}
}
