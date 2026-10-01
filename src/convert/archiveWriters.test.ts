/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { crc32, writeSevenZip, writeTar } from './archiveWriters.ts'

// These writers were also checked against GNU tar, bsdtar and 7-Zip 23 by hand (listing, content, `7z t`).

const files = [
	{ name: '0001.jpg', data: new Uint8Array(3000).map((_, i) => i % 251) },
	{ name: '0002.jpg', data: new Uint8Array([1, 2, 3, 4, 5]) },
	{ name: 'ComicInfo.xml', data: new TextEncoder().encode('<ComicInfo/>') },
]

const text = (bytes: Uint8Array): string => new TextDecoder().decode(bytes).replace(/\0[\s\S]*$/, '')

/**
 * @param haystack
 * @param needle
 */
function containsBytes(haystack: Uint8Array, needle: number[]): boolean {
	for (let i = 0; i + needle.length <= haystack.length; i++) {
		if (needle.every((b, j) => haystack[i + j] === b)) {
			return true
		}
	}
	return false
}

/**
 * Minimal tar reader for the assertions.
 *
 * @param tar
 */
function readTar(tar: Uint8Array): { name: string, data: Uint8Array }[] {
	const out: { name: string, data: Uint8Array }[] = []
	let pos = 0
	let paxName: string | null = null
	while (pos + 512 <= tar.length && tar.subarray(pos, pos + 512).some((b) => b !== 0)) {
		const header = tar.subarray(pos, pos + 512)
		const size = parseInt(text(header.subarray(124, 136)), 8)
		const type = String.fromCharCode(header[156])
		// checksum: sum of all bytes with the checksum field counted as spaces
		let sum = 0
		header.forEach((b, i) => {
			sum += i >= 148 && i < 156 ? 0x20 : b
		})
		expect(parseInt(text(header.subarray(148, 154)), 8)).toBe(sum)
		const data = tar.subarray(pos + 512, pos + 512 + size)
		if (type === 'x') {
			paxName = /path=(.*)\n/.exec(text(data))?.[1] ?? null
		} else {
			out.push({ name: paxName ?? text(header.subarray(0, 100)), data })
			paxName = null
		}
		pos += 512 + Math.ceil(size / 512) * 512
	}
	return out
}

describe('archive writers', () => {
	it('computes the CRC-32 of the standard check string', () => {
		expect(crc32(new TextEncoder().encode('123456789'))).toBe(0xCBF43926)
	})

	it('writes a tar that reads back with the same names, order and content', () => {
		const longName = `${'long-'.repeat(30)}.jpg`
		const tar = writeTar([...files, { name: longName, data: new Uint8Array([9]) }])
		expect(tar.length % 512).toBe(0)
		expect(text(tar.subarray(257, 262))).toBe('ustar')
		expect(tar.subarray(tar.length - 1024).every((b) => b === 0)).toBe(true)
		const read = readTar(tar)
		expect(read.map((f) => f.name)).toEqual(['0001.jpg', '0002.jpg', 'ComicInfo.xml', longName])
		expect([...read[0].data]).toEqual([...files[0].data])
		expect([...read[2].data]).toEqual([...files[2].data])
	})

	it('writes a 7z with a valid signature header, CRCs and stored data', () => {
		const sz = writeSevenZip(files)
		const view = new DataView(sz.buffer, sz.byteOffset, sz.byteLength)
		expect([...sz.subarray(0, 6)]).toEqual([0x37, 0x7A, 0xBC, 0xAF, 0x27, 0x1C])
		expect(view.getUint32(8, true)).toBe(crc32(sz.subarray(12, 32)))
		const offset = Number(view.getBigUint64(12, true))
		const size = Number(view.getBigUint64(20, true))
		const header = sz.subarray(32 + offset, 32 + offset + size)
		expect(view.getUint32(28, true)).toBe(crc32(header))
		expect(32 + offset + size).toBe(sz.length)
		// the packed streams are the files, back to back
		expect(offset).toBe(files.reduce((n, f) => n + f.data.length, 0))
		expect([...sz.subarray(32, 32 + files[0].data.length)]).toEqual([...files[0].data])
		// header: Header, MainStreamsInfo ... and the CRC of every file
		expect(header[0]).toBe(0x01)
		expect(header[1]).toBe(0x04)
		for (const f of files) {
			const crc = crc32(f.data)
			expect(containsBytes(header, [crc & 0xFF, (crc >>> 8) & 0xFF, (crc >>> 16) & 0xFF, (crc >>> 24) & 0xFF])).toBe(true)
		}
	})

	it('skips empty files in a 7z and refuses an empty archive', () => {
		expect(() => writeSevenZip([{ name: 'a', data: new Uint8Array() }])).toThrow()
		expect(writeSevenZip([...files, { name: 'empty', data: new Uint8Array() }]).length).toBe(writeSevenZip(files).length)
	})
})
