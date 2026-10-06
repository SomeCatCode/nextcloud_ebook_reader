/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { appPageTitle } from './pageTitle.ts'

describe('appPageTitle', () => {
	it('adds the localized navigation name of the app', () => {
		const apps = [{ id: 'files', name: 'Dateien' }, { id: 'ebookreader', name: 'E-Books' }]
		expect(appPageTitle('E-book library', apps)).toBe('E-book library - E-Books')
	})

	it('never produces an object in the title', () => {
		expect(appPageTitle('E-book library', [{ id: 'ebookreader', name: { value: 'E-Books' } }])).toBe('E-book library')
		expect(appPageTitle('E-book library', null)).toBe('E-book library')
		expect(appPageTitle('E-book library', [])).toBe('E-book library')
	})
})
