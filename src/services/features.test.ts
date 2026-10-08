/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, describe, expect, it } from 'vitest'
import { hasFeature, serverFeatures } from './features.ts'

const win = window as unknown as { OC?: unknown }

/**
 * A capabilities call that fails.
 */
function throwing(): never {
	throw new Error('capabilities not available')
}

describe('server features', () => {
	afterEach(() => {
		delete win.OC
	})

	it('assumes every feature when the capabilities can not be read', () => {
		expect(serverFeatures()).toBeNull()
		expect(hasFeature('folders')).toBe(true)
		win.OC = { getCapabilities: throwing }
		expect(hasFeature('shared-filter')).toBe(true)
	})

	it('reads the feature list of the server', () => {
		win.OC = { getCapabilities: () => ({ ebookreader: { features: ['folders', 'series-shares', 3] } }) }
		expect(serverFeatures()).toEqual(['folders', 'series-shares'])
		expect(hasFeature('folders')).toBe(true)
		expect(hasFeature('sidecar-meta')).toBe(false)
	})

	it('treats a server without the list (before 0.10) as having none of the new features', () => {
		win.OC = { getCapabilities: () => ({ ebookreader: { version: '0.9.0' } }) }
		expect(hasFeature('folders')).toBe(false)
		expect(hasFeature('shared-filter')).toBe(false)
	})
})
