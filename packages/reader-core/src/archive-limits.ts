/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Limits for archives that are unpacked in the browser (EPUB, CBZ, and CBR/CB7/CBT without a server tool). A crafted
 * archive (decompression bomb, millions of entries) must not fill the memory of the tab.
 */

/** Larger entries are refused; real pages and chapters are far smaller */
export const MAX_ENTRY_SIZE = 256 * 1024 * 1024
/** Archives with more entries are refused */
export const MAX_ENTRIES = 100_000

/**
 * @param name entry name for the error message
 */
export function tooLarge(name: string): Error {
	return new Error(`Archive entry too large: ${name}`)
}

/**
 * @param count number of entries
 */
export function checkEntryCount(count: number): void {
	if (count > MAX_ENTRIES) {
		throw new Error(`Archive has too many entries (${count})`)
	}
}

/**
 * Collects a stream into a Blob, but fails as soon as more than `max` bytes arrive. The declared size of an entry can
 * not be trusted, so the real output is counted.
 *
 * @param write starts writing into the given stream and resolves when done
 * @param name entry name for the error message
 * @param type MIME type of the Blob
 * @param max limit in bytes
 */
export async function collectLimited(write: (writable: WritableStream<Uint8Array>) => Promise<unknown>, name: string, type = '', max = MAX_ENTRY_SIZE): Promise<Blob> {
	let size = 0
	const stream = new TransformStream<Uint8Array, Uint8Array>({
		transform(chunk, controller) {
			size += chunk.length
			if (size > max) {
				controller.error(tooLarge(name))
				return
			}
			controller.enqueue(chunk)
		},
	})
	const blob = new Response(stream.readable, type ? { headers: { 'Content-Type': type } } : undefined).blob()
	const [result] = await Promise.all([blob, write(stream.writable)])
	return result
}
