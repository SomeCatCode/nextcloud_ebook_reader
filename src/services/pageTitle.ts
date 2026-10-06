/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { loadState } from '@nextcloud/initial-state'

type AppEntry = { id?: unknown, name?: unknown }

/** Navigation entries of the Nextcloud apps (initial state `core/apps`), empty if unavailable. */
function navigationApps(): unknown {
	try {
		return loadState<unknown>('core', 'apps', [])
	} catch {
		return []
	}
}

/**
 * Page title for the `pageTitle` prop of NcAppContent: "<page> - <app name>" (the library appends the
 * instance name). Workaround for the Nextcloud Vue library 9.13: with only `pageHeading`, NcAppContent
 * appends the app name that NcContent provides as a computed ref without unwrapping it, so the browser
 * tab read "… - [object Object]". Passing `pageTitle` skips that code path.
 *
 * @param page Title of the page, e.g. "E-book library"
 * @param apps Navigation entries (defaults to the initial state)
 * @param appId Id of this app
 */
export function appPageTitle(page: string, apps: unknown = navigationApps(), appId = 'ebookreader'): string {
	const entry = Array.isArray(apps) ? (apps as AppEntry[]).find((a) => a?.id === appId) : undefined
	// not called "appName": the Nextcloud Vite config defines a global `appName` and replaces that identifier
	const navName = typeof entry?.name === 'string' ? entry.name.trim() : ''
	return navName && navName !== page ? `${page} - ${navName}` : page
}
