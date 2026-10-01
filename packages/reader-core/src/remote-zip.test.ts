/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it, vi } from 'vitest'
import { makeRemoteZipLoader } from './remote-zip.ts'

/**
 * @param sizes
 */
function source(sizes: Record<string, number>) {
	const loadEntry = vi.fn(async (name: string) => new Blob([name === 'META-INF/container.xml' ? '<c/>' : 'x'.repeat(sizes[name])], { type: 'application/octet-stream' }))
	return {
		loadEntry,
		src: { kind: 'remote-zip' as const, name: 'b.epub', entries: Object.entries(sizes).map(([name, size]) => ({ name, size })), loadEntry },
	}
}

describe('makeRemoteZipLoader', () => {
	it('lists entries and sizes, null for unknown names', () => {
		const { src, loadEntry } = source({ 'a.txt': 5, 'b.txt': 7 })
		const loader = makeRemoteZipLoader(src)
		expect(loader.entries.map((e) => e.filename)).toEqual(['a.txt', 'b.txt'])
		expect(loader.getSize('b.txt')).toBe(7)
		expect(loader.getSize('zzz')).toBe(0)
		expect(loader.loadText('zzz')).toBeNull()
		expect(loader.loadBlob('zzz')).toBeNull()
		expect(loadEntry).not.toHaveBeenCalled()
	})

	it('loadText returns the text, loadBlob applies the type', async () => {
		const { src } = source({ 'META-INF/container.xml': 4, 'p.xhtml': 3 })
		const loader = makeRemoteZipLoader(src)
		expect(await loader.loadText('META-INF/container.xml')).toBe('<c/>')
		const blob = await loader.loadBlob('p.xhtml', 'application/xhtml+xml')
		expect(blob?.type).toBe('application/xhtml+xml')
		expect(blob?.size).toBe(3)
	})

	it('caches entries and shares in-flight requests', async () => {
		const { src, loadEntry } = source({ a: 10 })
		const loader = makeRemoteZipLoader(src)
		await Promise.all([loader.loadBlob('a'), loader.loadBlob('a')])
		await loader.loadText('a')
		expect(loadEntry).toHaveBeenCalledTimes(1)
	})

	it('evicts the least recently used entries above the limit', async () => {
		const { src, loadEntry } = source({ a: 10, b: 10, c: 10 })
		const loader = makeRemoteZipLoader(src, 25)
		await loader.loadBlob('a')
		await loader.loadBlob('b')
		await loader.loadBlob('a') // a is now the most recent
		await loader.loadBlob('c') // 30 > 25: evicts b
		expect(loadEntry).toHaveBeenCalledTimes(3)
		await loader.loadBlob('a')
		await loader.loadBlob('c')
		expect(loadEntry).toHaveBeenCalledTimes(3)
		await loader.loadBlob('b')
		expect(loadEntry).toHaveBeenCalledTimes(4)
	})

	it('does not cache entries larger than the limit', async () => {
		const { src, loadEntry } = source({ big: 30 })
		const loader = makeRemoteZipLoader(src, 25)
		await loader.loadBlob('big')
		await loader.loadBlob('big')
		expect(loadEntry).toHaveBeenCalledTimes(2)
	})

	it('does not cache failures', async () => {
		const { src, loadEntry } = source({ x: 1 })
		loadEntry.mockRejectedValueOnce(new Error('boom'))
		const loader = makeRemoteZipLoader(src)
		await expect(loader.loadBlob('x')).rejects.toThrow('boom')
		await expect(loader.loadBlob('x')).resolves.toBeInstanceOf(Blob)
	})
})
