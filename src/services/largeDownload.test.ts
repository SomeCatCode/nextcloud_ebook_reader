/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it, vi } from 'vitest'
import { DownloadDeclinedError, ensureDownloadConfirmed, formatMegabytes, isLargeDownload, LARGE_DOWNLOAD_BYTES } from './largeDownload.ts'

describe('large download confirmation', () => {
	it('is only needed above 50 MB', () => {
		expect(isLargeDownload(LARGE_DOWNLOAD_BYTES)).toBe(false)
		expect(isLargeDownload(LARGE_DOWNLOAD_BYTES + 1)).toBe(true)
		expect(isLargeDownload(undefined)).toBe(false)
	})

	it('does not ask for small files', async () => {
		const confirm = vi.fn(async () => true)
		await ensureDownloadConfirmed({ size: 1000 }, confirm)
		expect(confirm).not.toHaveBeenCalled()
	})

	it('continues when confirmed and throws when declined', async () => {
		const size = 300 * 1048576
		const yes = vi.fn(async () => true)
		await ensureDownloadConfirmed({ size }, yes)
		expect(yes).toHaveBeenCalledWith(size)
		await expect(ensureDownloadConfirmed({ size }, async () => false)).rejects.toBeInstanceOf(DownloadDeclinedError)
	})

	it('formats megabytes', () => {
		expect(formatMegabytes(300 * 1048576)).toBe('300.0')
	})
})
