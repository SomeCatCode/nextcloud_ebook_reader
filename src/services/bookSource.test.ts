/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it, vi } from 'vitest'
import { coverSize, needsClientCover } from './bookSource.ts'

vi.mock('./api.ts', () => ({ comicPageUrl: vi.fn(), fetchBookBlob: vi.fn(), getComicPages: vi.fn() }))
vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token' }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u: string) => u }))

describe('client cover fallback', () => {
	const file = new File(['x'], 'a.cbr')

	it('is needed for locally opened CBR, CB7 and CBT without cover', () => {
		for (const format of ['cbr', 'cb7', 'cbt'] as const) {
			expect(needsClientCover({ format, hasCover: false }, file)).toBe(true)
			expect(needsClientCover({ format, hasCover: true }, file)).toBe(false)
		}
	})

	it('is not needed for other formats or for pages served by the server', () => {
		expect(needsClientCover({ format: 'cbz', hasCover: false }, file)).toBe(false)
		expect(needsClientCover({ format: 'epub', hasCover: false }, file)).toBe(false)
		const remote = { kind: 'remote-comic' as const, name: 'a', pages: [], loadPage: vi.fn() }
		expect(needsClientCover({ format: 'cbt', hasCover: false }, remote)).toBe(false)
	})

	it('scales to at most 600 px width and never enlarges', () => {
		expect(coverSize(1200, 1800)).toEqual({ width: 600, height: 900 })
		expect(coverSize(300, 450)).toEqual({ width: 300, height: 450 })
		expect(coverSize(601, 10)).toEqual({ width: 600, height: 10 })
	})
})
