/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { shareState } from './utils.ts'

describe('shareState', () => {
	it('tells incoming from outgoing shares', () => {
		expect(shareState({ shared: true })).toBe('incoming')
		expect(shareState({ shared: false, sharedOut: true })).toBe('outgoing')
		expect(shareState({ shared: false, sharedOut: false })).toBeNull()
	})

	it('treats missing fields of older servers as not shared', () => {
		expect(shareState({})).toBeNull()
		expect(shareState({ shared: false })).toBeNull()
	})

	it('shows an incoming book as incoming even when flagged as outgoing', () => {
		expect(shareState({ shared: true, sharedOut: true })).toBe('incoming')
	})
})
