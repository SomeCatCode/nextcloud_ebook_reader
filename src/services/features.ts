/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export type ServerFeature = 'shared-filter' | 'series-shares' | 'folder-shares' | 'folders' | 'sidecar-meta'

interface CapabilitiesWindow {
	OC?: { getCapabilities?: () => unknown }
}

/**
 * Feature list the server announces in `ebookreader.features` (server 0.10+). Servers without the list
 * (older versions) yield an empty list; null means the capabilities can not be read at all.
 */
export function serverFeatures(): string[] | null {
	try {
		const caps = (window as unknown as CapabilitiesWindow).OC?.getCapabilities?.() as { ebookreader?: { features?: unknown } } | undefined
		if (!caps || typeof caps !== 'object') {
			return null
		}
		const features = caps.ebookreader?.features
		return Array.isArray(features) ? features.filter((f): f is string => typeof f === 'string') : []
	} catch {
		return null
	}
}

/**
 * Whether the server offers a feature. When the capabilities can not be read the UI assumes it does
 * (the calls then fail gracefully instead of hiding working features).
 *
 * @param feature
 */
export function hasFeature(feature: ServerFeature): boolean {
	const features = serverFeatures()
	return features === null || features.includes(feature)
}
