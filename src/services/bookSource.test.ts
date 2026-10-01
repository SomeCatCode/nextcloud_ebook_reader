/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it, vi } from 'vitest'
import * as api from './api.ts'
import { coverSize, loadBookSource, needsClientCover } from './bookSource.ts'
import { DownloadDeclinedError } from './largeDownload.ts'

vi.mock('./api.ts', () => ({
	archiveEntries: vi.fn(),
	comicPageUrl: vi.fn(),
	fetchBookBlob: vi.fn(),
	getComicPages: vi.fn(),
	itemUrl: (id: number, entry: string, v: string) => `/item/${id}?id=${entry}&v=${v}`,
}))
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

describe('loadBookSource for EPUB', () => {
	const epub = { fileId: 7, path: 'Books/a.epub', format: 'epub', size: 1000 } as never
	const signal = new AbortController().signal

	it('reads entries from the server when the entry list is available', async () => {
		vi.mocked(api.archiveEntries).mockResolvedValue({ etag: 'e1', entries: [{ name: 'a.xhtml', size: 3 }] })
		const fetchMock = vi.fn(async () => new Response('abc'))
		vi.stubGlobal('fetch', fetchMock)
		const src = await loadBookSource(epub, signal)
		expect(src).toMatchObject({ kind: 'remote-zip', name: 'a.epub' })
		const blob = await (src as { loadEntry: (n: string) => Promise<Blob> }).loadEntry('a.xhtml')
		expect(await blob.text()).toBe('abc')
		expect(fetchMock).toHaveBeenCalledWith('/item/7?id=a.xhtml&v=e1', { credentials: 'same-origin' })
		expect(api.fetchBookBlob).not.toHaveBeenCalled()
	})

	it('falls back to the full download if the entry list fails', async () => {
		vi.mocked(api.archiveEntries).mockRejectedValue(new Error('500'))
		vi.mocked(api.fetchBookBlob).mockResolvedValue(new Blob(['x']))
		const src = await loadBookSource(epub, signal)
		expect(src).toBeInstanceOf(File)
	})

	it('asks before a large fallback download and stops if declined', async () => {
		vi.mocked(api.archiveEntries).mockRejectedValue(new Error('500'))
		vi.mocked(api.fetchBookBlob).mockClear()
		const big = { fileId: 7, path: 'Books/a.epub', format: 'epub', size: 300 * 1048576 } as never
		await expect(loadBookSource(big, signal, async () => false)).rejects.toBeInstanceOf(DownloadDeclinedError)
		expect(api.fetchBookBlob).not.toHaveBeenCalled()
	})
})
