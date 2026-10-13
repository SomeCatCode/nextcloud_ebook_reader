/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { loadState } from '@nextcloud/initial-state'
import { getLanguage, isRTL } from '@nextcloud/l10n'

/**
 * Whether a UI language is written right to left. Variants such as Dari ("fa_AF") are resolved through their base language.
 *
 * @param language language code as used by Nextcloud ("ar", "fa_AF", "fa-AF", …)
 */
export function isRtlLanguage(language: string): boolean {
	const code = language.replace(/_/g, '-')
	return isRTL(code) || isRTL(code.split('-')[0])
}

/**
 * Nextcloud only sets dir="rtl" for the languages it knows itself (ar, fa, …). The app also ships Dari ("fa_AF"):
 * the server resolves it for the app only (the page keeps the language of core, and so its direction), so the
 * server tells the frontend the app language's direction and it is corrected here.
 */
export function applyDocumentDirection(): void {
	if (typeof document === 'undefined') {
		return
	}
	let rtl: boolean
	try {
		rtl = loadState<{ rtl: boolean }>('ebookreader', 'l10n').rtl
	} catch {
		rtl = isRtlLanguage(getLanguage())
	}
	const root = document.documentElement
	if (rtl && root.dir !== 'rtl') {
		root.dir = 'rtl'
	}
}
