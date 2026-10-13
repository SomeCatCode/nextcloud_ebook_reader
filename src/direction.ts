/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { loadState } from '@nextcloud/initial-state'
import { getLanguage, isRTL } from '@nextcloud/l10n'

/**
 * Whether a UI language is written right to left. Variants such as "fa_IR" are resolved through their base language.
 *
 * @param language language code as used by Nextcloud ("ar", "fa_IR", "fa-IR", …)
 */
export function isRtlLanguage(language: string): boolean {
	const code = language.replace(/_/g, '-')
	return isRTL(code) || isRTL(code.split('-')[0])
}

/**
 * Nextcloud sets dir="rtl" itself for the languages it knows (ar, fa, …) from the language of the page. The app's
 * translation language can differ from that one, so the server tells the frontend the direction of the app's
 * language and it is applied here.
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
