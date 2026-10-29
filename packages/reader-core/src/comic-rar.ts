/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * CBR, CB7 and CBT support through libarchive.js (wasm worker; it reads RAR, 7z and tar alike).
 * Builds a foliate-compatible loader ({ entries, loadBlob, getSize }) that is fed to comic-book.js
 * makeComicBook().
 */
import type { ReaderOptions } from './types.ts'

import { checkEntryCount, MAX_ENTRY_SIZE, tooLarge } from './archive-limits.ts'

interface CompressedFile {
	name: string
	size: number
	extract(): Promise<File>
}

export interface ComicLoader {
	entries: { filename: string }[]
	loadBlob: (name: string) => Promise<Blob> | null
	loadText: (name: string) => Promise<string> | null
	getSize: (name: string) => number
	close: () => void
}

/**
 * @param file
 * @param opts
 * @param extension file extension used as the archive's name (cbr, cb7 or cbt)
 */
export async function makeArchiveLoader(file: Blob, opts: ReaderOptions, extension = 'cbr'): Promise<ComicLoader> {
	if (!opts.loadLibarchive) {
		throw new Error('Archive support is not configured (loadLibarchive missing)')
	}
	const { workerSource, wasmUrl } = await opts.loadLibarchive()
	const absWasm = new URL(wasmUrl, globalThis.location?.href ?? 'http://localhost/').href
	// The worker bundle resolves "libarchive.wasm" relative to import.meta.url. We run it from a
	// blob: URL, so point it at the (hashed) asset URL instead.
	const patched = workerSource
		.replace(/new URL\("libarchive\.wasm",import\.meta\.url\)\.href/g, JSON.stringify(absWasm))
		.replace(/import\.meta\.url/g, JSON.stringify(absWasm))
	const workerBlobUrl = URL.createObjectURL(new Blob([patched], { type: 'text/javascript' }))
	// the main module next to our worker build: its default worker URL then points at our worker, not at the npm one
	const { Archive } = await import('../../libarchive/dist/libarchive.js')
	Archive.init({ workerUrl: workerBlobUrl })
	const archive = await Archive.open(file instanceof File ? file : new File([file], `book.${extension}`))
	const files = await archive.getFilesArray() as unknown as { file: CompressedFile, path: string }[]
	try {
		checkEntryCount(files.length)
	} catch (e) {
		void archive.close()
		URL.revokeObjectURL(workerBlobUrl)
		throw e
	}
	const map = new Map<string, CompressedFile>()
	for (const { file: f, path } of files) {
		map.set(path + f.name, f)
	}
	// libarchive unpacks an entry as a whole inside the worker: refuse entries whose declared size is too large
	// (RAR and 7z stop at the declared size) and check the result as well
	const extract = (name: string): Promise<File> | null => {
		const entry = map.get(name)
		if (!entry) {
			return null
		}
		if (entry.size > MAX_ENTRY_SIZE) {
			return Promise.reject(tooLarge(name))
		}
		return entry.extract().then((f) => {
			if (f.size > MAX_ENTRY_SIZE) {
				throw tooLarge(name)
			}
			return f
		})
	}
	return {
		entries: [...map.keys()].map((filename) => ({ filename })),
		loadBlob: extract,
		loadText: (name) => extract(name)?.then((x) => x.text()) ?? null,
		getSize: (name) => map.get(name)?.size ?? 0,
		close: () => {
			void archive.close()
			URL.revokeObjectURL(workerBlobUrl)
		},
	}
}

/**
 * @param file
 * @param opts
 */
export function makeRarLoader(file: Blob, opts: ReaderOptions): Promise<ComicLoader> {
	return makeArchiveLoader(file, opts, 'cbr')
}
