/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Equivalent of foliate-js view.js `makeBook`, but format driven (no sniffing by file name)
 * and with CBR support. foliate-js modules are imported lazily.
 */
import type { ReaderFormat, ReaderLayout, ReaderOptions } from './types.ts'
import { makeRarLoader } from './comic-rar.ts'

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export type FoliateBook = any

interface ZipLoader {
	entries: { filename: string }[]
	loadText: (name: string) => Promise<string> | null
	loadBlob: (name: string, type?: string) => Promise<Blob> | null
	getSize: (name: string) => number
}

/**
 * @param file
 */
async function makeZipLoader(file: Blob): Promise<ZipLoader> {
	const zip = await import('../vendor/foliate-js/vendor/zip.js')
	const { configure, ZipReader, BlobReader, TextWriter, BlobWriter } = zip
	configure({ useWebWorkers: false })
	const reader = new ZipReader(new BlobReader(file))
	const entries = await reader.getEntries() as { filename: string, uncompressedSize?: number, getData: (w: unknown) => Promise<unknown> }[]
	const map = new Map(entries.map((e) => [e.filename, e]))
	return {
		entries,
		loadText: (name) => map.has(name) ? map.get(name)!.getData(new TextWriter()) as Promise<string> : null,
		loadBlob: (name, type) => map.has(name) ? map.get(name)!.getData(new BlobWriter(type)) as Promise<Blob> : null,
		getSize: (name) => map.get(name)?.uncompressedSize ?? 0,
	}
}

export type ReaderErrorCode = 'drm' | 'corrupt' | 'unsupported'

export class ReaderError extends Error {
	public readonly code: ReaderErrorCode

	constructor(code: ReaderErrorCode, message: string, cause?: unknown) {
		super(message, { cause })
		this.name = 'ReaderError'
		this.code = code
	}
}

const FONT_OBFUSCATION = ['http://www.idpf.org/2008/embedding', 'http://ns.adobe.com/pdf/enc#RC']

/**
 * @param loader
 */
async function epubHasDrm(loader: ZipLoader): Promise<boolean> {
	if (await loader.loadText('META-INF/rights.xml')) {
		return true
	}
	const enc = await loader.loadText('META-INF/encryption.xml')
	if (!enc) {
		return false
	}
	const algos = [...enc.matchAll(/Algorithm\s*=\s*["']([^"']+)["']/g)].map((m) => m[1])
	return algos.some((a) => !FONT_OBFUSCATION.includes(a))
}

/**
 * PalmDOC header encryption type (offset 12 of record 0) is non-zero for DRM'd MOBI/AZW.
 *
 * @param file
 */
async function mobiHasDrm(file: Blob): Promise<boolean> {
	const head = new DataView(await file.slice(0, 96).arrayBuffer())
	if (head.byteLength < 82) {
		return false
	}
	const rec0 = head.getUint32(78)
	const rec = new DataView(await file.slice(rec0, rec0 + 16).arrayBuffer())
	return rec.byteLength >= 14 && rec.getUint16(12) !== 0
}

export interface OpenedBook {
	book: FoliateBook
	isComic: boolean
	close: () => void
}

/**
 * @param file
 * @param format
 * @param opts
 * @param layout
 */
export async function openBook(file: Blob, format: ReaderFormat, opts: ReaderOptions, layout: ReaderLayout): Promise<OpenedBook> {
	try {
		return await openBookUnchecked(file, format, opts, layout)
	} catch (e) {
		if (e instanceof ReaderError) {
			throw e
		}
		throw new ReaderError('corrupt', e instanceof Error ? e.message : String(e), e)
	}
}

/**
 * @param file
 * @param format
 * @param opts
 * @param layout
 */
async function openBookUnchecked(file: Blob, format: ReaderFormat, opts: ReaderOptions, layout: ReaderLayout): Promise<OpenedBook> {
	const name = (file as File).name ?? `book.${format}`
	switch (format) {
		case 'epub': {
			const loader = await makeZipLoader(file)
			if (await epubHasDrm(loader)) {
				throw new ReaderError('drm', 'DRM protected EPUB')
			}
			const { EPUB } = await import('../vendor/foliate-js/epub.js')
			return { book: await new EPUB(loader).init(), isComic: false, close: () => {} }
		}
		case 'cbz':
		case 'cbr': {
			const loader = format === 'cbz' ? await makeZipLoader(file) : await makeRarLoader(file, opts)
			const { makeComicBook } = await import('../vendor/foliate-js/comic-book.js')
			const book = makeComicBook(loader, { name })
			if (layout.comicSpread !== 'double') {
				book.rendition.spread = 'none'
			}
			book.dir = layout.comicRtl ? 'rtl' : 'ltr'
			return {
				book,
				isComic: true,
				close: () => {
					book.destroy?.()
					;(loader as { close?: () => void }).close?.()
				},
			}
		}
		case 'fbz': {
			const loader = await makeZipLoader(file)
			const { makeFB2 } = await import('../vendor/foliate-js/fb2.js')
			const entry = loader.entries.find((e) => e.filename.endsWith('.fb2')) ?? loader.entries[0]
			const blob = await loader.loadBlob(entry.filename)
			return { book: await makeFB2(blob), isComic: false, close: () => {} }
		}
		case 'fb2': {
			const { makeFB2 } = await import('../vendor/foliate-js/fb2.js')
			return { book: await makeFB2(file), isComic: false, close: () => {} }
		}
		case 'mobi':
		case 'azw3': {
			const { isMOBI, MOBI } = await import('../vendor/foliate-js/mobi.js')
			if (!await isMOBI(file)) {
				throw new ReaderError('corrupt', 'Unsupported or corrupt MOBI file')
			}
			if (await mobiHasDrm(file)) {
				throw new ReaderError('drm', 'DRM protected MOBI file')
			}
			const fflate = await import('../vendor/foliate-js/vendor/fflate.js')
			return { book: await new MOBI({ unzlib: fflate.unzlibSync }).open(file), isComic: false, close: () => {} }
		}
		default:
			throw new ReaderError('unsupported', `Unsupported format: ${format as string}`)
	}
}
