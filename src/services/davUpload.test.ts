/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { CHUNK_SIZE, davUpload } from './davUpload.ts'

vi.mock('@nextcloud/axios', () => ({ default: { put: vi.fn(), request: vi.fn() } }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'alice' }) }))
vi.mock('@nextcloud/files/dav', () => ({ defaultRemoteURL: 'https://cloud/remote.php/dav' }))
vi.mock('./api.ts', () => ({ davUrlForPath: (p: string) => 'https://cloud/remote.php/dav/files/alice' + p }))

const put = vi.mocked(axios.put)
const request = vi.mocked(axios.request)

describe('davUpload', () => {
	beforeEach(() => {
		vi.resetAllMocks()
	})

	it('uploads small files with a single PUT', async () => {
		put.mockResolvedValue({ headers: { 'oc-fileid': '42' } })
		const id = await davUpload('/Books/a.cbz', new Blob(['abc']), { overwrite: false, contentType: 'application/comicbook+zip' })
		expect(id).toBe(42)
		expect(put).toHaveBeenCalledTimes(1)
		expect(put.mock.calls[0][0]).toBe('https://cloud/remote.php/dav/files/alice/Books/a.cbz')
		expect(put.mock.calls[0][2]?.headers).toMatchObject({ 'If-None-Match': '*', 'Content-Type': 'application/comicbook+zip' })
		expect(request).not.toHaveBeenCalled()
	})

	it('uses chunked upload v2 for large files', async () => {
		put.mockResolvedValue({ headers: {} })
		request.mockImplementation(async (cfg) => ({ headers: cfg.method === 'MOVE' ? { 'oc-fileid': '7' } : {} }) as never)
		const blob = new Blob([new Uint8Array(CHUNK_SIZE * 2 + 5)])
		const progress: number[] = []
		const id = await davUpload('/Books/big.cbz', blob, { overwrite: false, onProgress: (l) => progress.push(l) })
		expect(id).toBe(7)
		const methods = request.mock.calls.map((c) => c[0].method)
		expect(methods).toEqual(['MKCOL', 'MOVE'])
		expect(request.mock.calls[0][0].url).toMatch(/^https:\/\/cloud\/remote\.php\/dav\/uploads\/alice\/ebookreader-/)
		expect(request.mock.calls[1][0].headers).toMatchObject({
			Destination: 'https://cloud/remote.php/dav/files/alice/Books/big.cbz',
			'OC-Total-Length': String(blob.size),
			Overwrite: 'F',
		})
		expect(put).toHaveBeenCalledTimes(3)
		expect(put.mock.calls.map((c) => String(c[0]).split('/').pop())).toEqual(['00001', '00002', '00003'])
		expect(progress.at(-1)).toBe(blob.size)
	})

	it('retries a failed chunk and cleans up after a final failure', async () => {
		request.mockResolvedValue({ headers: {} } as never)
		put.mockRejectedValueOnce({ response: { status: 503 } }).mockResolvedValueOnce({ headers: {} })
			.mockRejectedValue({ response: { status: 400 } })
		vi.useFakeTimers()
		const p = davUpload('/Books/big.cbz', new Blob([new Uint8Array(CHUNK_SIZE + 1)]))
		const expectation = expect(p).rejects.toEqual({ response: { status: 400 } })
		await vi.runAllTimersAsync()
		await expectation
		vi.useRealTimers()
		expect(put).toHaveBeenCalledTimes(3)
		expect(request.mock.calls.map((c) => c[0].method)).toEqual(['MKCOL', 'DELETE'])
	})
})
