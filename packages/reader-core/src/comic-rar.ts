/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * CBR support through libarchive.js (wasm worker). Builds a foliate-compatible loader
 * ({ entries, loadBlob, getSize }) that is fed to comic-book.js makeComicBook().
 */
import type { ReaderOptions } from './types.ts'

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
 */
export async function makeRarLoader(file: Blob, opts: ReaderOptions): Promise<ComicLoader> {
	if (!opts.loadLibarchive) {
		throw new Error('CBR support is not configured (loadLibarchive missing)')
	}
	const { workerSource, wasmUrl } = await opts.loadLibarchive()
	const absWasm = new URL(wasmUrl, globalThis.location?.href ?? 'http://localhost/').href
	// The worker bundle resolves "libarchive.wasm" relative to import.meta.url. We run it from a
	// blob: URL, so point it at the (hashed) asset URL instead.
	const patched = workerSource
		.replace(/new URL\("libarchive\.wasm",import\.meta\.url\)\.href/g, JSON.stringify(absWasm))
		.replace(/import\.meta\.url/g, JSON.stringify(absWasm))
	const workerBlobUrl = URL.createObjectURL(new Blob([patched], { type: 'text/javascript' }))
	const { Archive } = await import('libarchive.js')
	Archive.init({ workerUrl: workerBlobUrl })
	const archive = await Archive.open(file instanceof File ? file : new File([file], 'book.cbr'))
	const files = await archive.getFilesArray() as unknown as { file: CompressedFile, path: string }[]
	const map = new Map<string, CompressedFile>()
	for (const { file: f, path } of files) {
		map.set(path + f.name, f)
	}
	return {
		entries: [...map.keys()].map((filename) => ({ filename })),
		loadBlob: (name) => map.get(name)?.extract() ?? null,
		loadText: (name) => map.get(name)?.extract().then((x) => x.text()) ?? null,
		getSize: (name) => map.get(name)?.size ?? 0,
		close: () => {
			void archive.close()
			URL.revokeObjectURL(workerBlobUrl)
		},
	}
}
