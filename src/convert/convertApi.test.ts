/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ConvertError, getCapabilities, getOptimizeEstimate, optimizeBooks } from './convertApi.ts'

vi.mock('@nextcloud/axios', () => ({ default: { request: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (path: string) => `/ocs/v2.php${path}` }))

const request = vi.mocked(axios.request)

/**
 * @param data
 */
function ocs(data: unknown): { data: { ocs: { meta: object, data: unknown } } } {
	return { data: { ocs: { meta: {}, data } } }
}

describe('optimize API', () => {
	beforeEach(() => {
		request.mockReset()
	})

	it('requests the estimate with the options as query parameters', async () => {
		const estimate = { pages: 52, oversizedPages: 37, currentBytes: 180, estimatedBytes: 65, exact: false }
		request.mockResolvedValue(ocs(estimate) as never)
		const res = await getOptimizeEstimate(12, { maxHeight: 1920, pngToJpeg: true })
		expect(res).toEqual(estimate)
		expect(request).toHaveBeenCalledWith(expect.objectContaining({
			method: 'get',
			url: '/ocs/v2.php/apps/ebookreader/api/v1/books/12/convert/estimate?maxHeight=1920&pngToJpeg=1',
		}))
	})

	it('turns estimate errors into ConvertError with the server message and status', async () => {
		request.mockRejectedValue({ response: { status: 415, data: { ocs: { meta: {}, data: { message: 'The server cannot read this format' } } } } })
		await expect(getOptimizeEstimate(1, { maxHeight: 2560, pngToJpeg: false })).rejects.toMatchObject({
			name: 'ConvertError',
			status: 415,
			message: 'The server cannot read this format',
		})
		expect(ConvertError).toBeDefined()
	})

	it('starts the bulk optimization with the options and the delete flag', async () => {
		request.mockResolvedValue(ocs({ tasks: [{ fileId: 1, taskId: 10 }], skipped: [] }) as never)
		const res = await optimizeBooks([1, 2], { maxHeight: 2560, pngToJpeg: false }, true)
		expect(res.tasks).toEqual([{ fileId: 1, taskId: 10 }])
		expect(request).toHaveBeenCalledWith(expect.objectContaining({
			method: 'post',
			url: '/ocs/v2.php/apps/ebookreader/api/v1/convert/optimize',
			data: { fileIds: [1, 2], maxHeight: 2560, pngToJpeg: false, deleteOriginal: true },
		}))
	})

	it('reads the optimize capability', async () => {
		request.mockResolvedValue(ocs({ tools: {}, server: { read: [], write: [] }, optimize: { available: true, maxHeights: [2560, 1920] } }) as never)
		expect((await getCapabilities()).optimize?.available).toBe(true)
	})
})
