/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { checkEntryCount, collectLimited, MAX_ENTRIES } from './archive-limits.ts'

/**
 * @param chunks
 */
function writer(chunks: number[]): (w: WritableStream<Uint8Array>) => Promise<void> {
	return async (writable) => {
		const w = writable.getWriter()
		for (const n of chunks) {
			await w.write(new Uint8Array(n))
		}
		await w.close()
	}
}

describe('collectLimited', () => {
	it('collects an entry within the limit', async () => {
		const blob = await collectLimited(writer([10, 20]), 'a.png', 'image/png', 100)
		expect(blob.size).toBe(30)
		expect(blob.type).toBe('image/png')
	})

	it('fails as soon as the real output is larger than the limit', async () => {
		await expect(collectLimited(writer([60, 60, 60]), 'bomb.xhtml', '', 100)).rejects.toThrow('too large: bomb.xhtml')
	})
})

describe('checkEntryCount', () => {
	it('refuses archives with too many entries', () => {
		expect(() => checkEntryCount(MAX_ENTRIES)).not.toThrow()
		expect(() => checkEntryCount(MAX_ENTRIES + 1)).toThrow()
	})
})
