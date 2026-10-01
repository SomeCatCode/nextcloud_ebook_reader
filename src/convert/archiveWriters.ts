/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Writers for CBT (tar) and CB7 (7z, stored without compression) in the browser.
 *
 * libarchive.js can write archives, but it sizes its output buffer at "file size + 128 bytes" per
 * file, which is less than the headers of an uncompressed tar/zip/7z need, so writing already
 * fails for small test archives (see docs/DEVIATIONS.md, V2-C). Comic pages are compressed images, so
 * compression would not help anyway, and both container formats are simple enough to write here.
 */

export interface ArchiveFile {
	name: string
	data: Uint8Array
}

let crcTable: Uint32Array | null = null

/**
 * @param data
 */
export function crc32(data: Uint8Array): number {
	if (!crcTable) {
		crcTable = new Uint32Array(256)
		for (let n = 0; n < 256; n++) {
			let c = n
			for (let k = 0; k < 8; k++) {
				c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1
			}
			crcTable[n] = c >>> 0
		}
	}
	let crc = 0xFFFFFFFF
	for (let i = 0; i < data.length; i++) {
		crc = crcTable[(crc ^ data[i]) & 0xFF] ^ (crc >>> 8)
	}
	return (crc ^ 0xFFFFFFFF) >>> 0
}

/**
 * @param parts
 */
function concat(parts: Uint8Array[]): Uint8Array {
	const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0))
	let offset = 0
	for (const p of parts) {
		out.set(p, offset)
		offset += p.length
	}
	return out
}

// ---- tar ----------------------------------------------------------------------------------------

const encoder = new TextEncoder()

/**
 * @param value
 * @param length
 */
function octal(value: number, length: number): Uint8Array {
	const out = new Uint8Array(length)
	const text = value.toString(8).padStart(length - 1, '0')
	out.set(encoder.encode(text), 0)
	return out
}

/**
 * @param name
 * @param size
 * @param type
 */
function tarHeader(name: string, size: number, type: string): Uint8Array {
	const h = new Uint8Array(512)
	const nameBytes = encoder.encode(name)
	h.set(nameBytes.subarray(0, 100), 0)
	h.set(octal(0o644, 8), 100)
	h.set(octal(0, 8), 108)
	h.set(octal(0, 8), 116)
	h.set(octal(size, 12), 124)
	h.set(octal(Math.floor(Date.now() / 1000), 12), 136)
	h.fill(0x20, 148, 156)
	h[156] = type.charCodeAt(0)
	h.set(encoder.encode('ustar\u000000'), 257)
	let sum = 0
	for (const b of h) {
		sum += b
	}
	h.set(encoder.encode(sum.toString(8).padStart(6, '0')), 148)
	h[154] = 0
	h[155] = 0x20
	return h
}

/**
 * @param n
 */
function pad512(n: number): Uint8Array {
	return new Uint8Array((512 - (n % 512)) % 512)
}

/**
 * Plain ustar archive (names longer than 100 bytes use a pax header).
 *
 * @param files
 */
export function writeTar(files: ArchiveFile[]): Uint8Array {
	const parts: Uint8Array[] = []
	for (const f of files) {
		const nameBytes = encoder.encode(f.name)
		if (nameBytes.length > 100) {
			const record = `path=${f.name}\n`
			let len = encoder.encode(record).length + 2
			while (encoder.encode(`${len} ${record}`).length !== len) {
				len = encoder.encode(`${len} ${record}`).length
			}
			const pax = encoder.encode(`${len} ${record}`)
			parts.push(tarHeader(`PaxHeader/${crc32(nameBytes).toString(16)}`, pax.length, 'x'), pax, pad512(pax.length))
		}
		parts.push(tarHeader(f.name, f.data.length, '0'), f.data, pad512(f.data.length))
	}
	parts.push(new Uint8Array(1024))
	return concat(parts)
}

// ---- 7z (copy method, one solid folder) -----------------------------------------------------------

/**
 * 7z variable length number.
 *
 * @param value
 */
function sevenNumber(value: number): number[] {
	let v = BigInt(value)
	let first = 0
	let mask = 0x80
	let i = 0
	for (; i < 8; i++) {
		if (v < (1n << BigInt(7 * (i + 1)))) {
			first |= Number(v >> BigInt(8 * i))
			break
		}
		first |= mask
		mask >>= 1
	}
	const out = [first]
	for (let n = i; n > 0; n--) {
		out.push(Number(v & 0xFFn))
		v >>= 8n
	}
	return out
}

/**
 * @param value
 */
function u32(value: number): number[] {
	return [value & 0xFF, (value >>> 8) & 0xFF, (value >>> 16) & 0xFF, (value >>> 24) & 0xFF]
}

/**
 * @param value
 */
function u64(value: number): number[] {
	const lo = value % 0x100000000
	const hi = Math.floor(value / 0x100000000)
	return [...u32(lo), ...u32(hi)]
}

/**
 * 7z archive that stores the files without compression (all of them in one folder with the Copy
 * coder). Empty files are not supported and are skipped.
 *
 * @param input
 */
export function writeSevenZip(input: ArchiveFile[]): Uint8Array {
	const files = input.filter((f) => f.data.length > 0)
	if (files.length === 0) {
		throw new Error('Nothing to archive')
	}
	const packed = concat(files.map((f) => f.data))
	const header: number[] = [0x01, 0x04]
	// PackInfo
	header.push(0x06, ...sevenNumber(0), ...sevenNumber(1), 0x09, ...sevenNumber(packed.length), 0x00)
	// UnpackInfo: one folder with one Copy coder
	header.push(0x07, 0x0B, ...sevenNumber(1), 0x00, ...sevenNumber(1), 0x01, 0x00, 0x0C, ...sevenNumber(packed.length), 0x00)
	// SubStreamsInfo: sizes and CRCs of the files
	header.push(0x08, 0x0D, ...sevenNumber(files.length))
	if (files.length > 1) {
		header.push(0x09)
		for (const f of files.slice(0, -1)) {
			header.push(...sevenNumber(f.data.length))
		}
	}
	header.push(0x0A, 0x01)
	for (const f of files) {
		header.push(...u32(crc32(f.data)))
	}
	header.push(0x00)
	header.push(0x00) // end of StreamsInfo
	// FilesInfo: names only
	const names: number[] = [0x00]
	for (const f of files) {
		for (const unit of f.name) {
			const code = unit.codePointAt(0) ?? 0x3F
			if (code > 0xFFFF) {
				const c = code - 0x10000
				const hi = 0xD800 + (c >> 10)
				const lo = 0xDC00 + (c & 0x3FF)
				names.push(hi & 0xFF, hi >> 8, lo & 0xFF, lo >> 8)
			} else {
				names.push(code & 0xFF, code >> 8)
			}
		}
		names.push(0, 0)
	}
	header.push(0x05, ...sevenNumber(files.length), 0x11, ...sevenNumber(names.length), ...names, 0x00)
	header.push(0x00) // end of Header
	const headerBytes = Uint8Array.from(header)

	const start = Uint8Array.from([...u64(packed.length), ...u64(headerBytes.length), ...u32(crc32(headerBytes))])
	const signature = Uint8Array.from([0x37, 0x7A, 0xBC, 0xAF, 0x27, 0x1C, 0x00, 0x04, ...u32(crc32(start)), ...start])
	return concat([signature, packed, headerBytes])
}
