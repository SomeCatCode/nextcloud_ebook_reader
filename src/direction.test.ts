/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { isRtlLanguage } from './direction.ts'

describe('isRtlLanguage', () => {
	it('detects right-to-left languages', () => {
		expect(isRtlLanguage('ar')).toBe(true)
		expect(isRtlLanguage('fa')).toBe(true)
		expect(isRtlLanguage('fa_IR')).toBe(true)
		expect(isRtlLanguage('fa-IR')).toBe(true)
	})

	it('keeps the other languages left to right', () => {
		for (const l of ['en', 'de', 'de_DE', 'fr', 'tr', 'ja', 'es']) {
			expect(isRtlLanguage(l)).toBe(false)
		}
	})
})
