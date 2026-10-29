/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { publicSharePath } from './viewer.ts'

describe('publicSharePath', () => {
	it('takes the path inside the share from the WebDAV URL', () => {
		expect(publicSharePath('https://cloud.example/public.php/dav/files/AbC123/Comics/Band%201.cbz', 'AbC123')).toBe('/Comics/Band 1.cbz')
	})

	it('returns the root for a shared file', () => {
		expect(publicSharePath('https://cloud.example/public.php/dav/files/AbC123', 'AbC123')).toBe('/')
	})

	it('returns null for other URLs and broken escapes', () => {
		expect(publicSharePath('https://cloud.example/remote.php/dav/files/u/b.epub', 'AbC123')).toBeNull()
		expect(publicSharePath('https://cloud.example/public.php/dav/files/AbC123/%E0%A4%A', 'AbC123')).toBeNull()
	})
})
