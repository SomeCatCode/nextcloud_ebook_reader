/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { RemoteZipSource } from './types.ts'

export interface RemoteZipLoader {
	entries: { filename: string }[]
	loadText: (name: string) => Promise<string> | null
	loadBlob: (name: string, type?: string) => Promise<Blob> | null
	getSize: (name: string) => number
}

/** Upper bound of the decoded entries kept in memory per opened book. */
export const REMOTE_ZIP_CACHE_BYTES = 50 * 1024 * 1024

/**
 * @param blob
 */
function blobText(blob: Blob): Promise<string> {
	if (typeof blob.text === 'function') {
		return blob.text()
	}
	return new Promise((resolve, reject) => {
		const reader = new FileReader()
		reader.onload = () => resolve(String(reader.result))
		reader.onerror = () => reject(reader.error)
		reader.readAsText(blob)
	})
}

/**
 * Loader with the interface foliate expects from a zip, backed by single entries fetched from the
 * server. Entries are cached in an LRU (by blob size); concurrent requests share one fetch. The
 * content still flows through foliate and hardenBook like with a local file.
 *
 * @param source
 * @param maxBytes
 */
export function makeRemoteZipLoader(source: RemoteZipSource, maxBytes = REMOTE_ZIP_CACHE_BYTES): RemoteZipLoader {
	const sizes = new Map(source.entries.map((e) => [e.name, e.size]))
	const cache = new Map<string, Blob>() // insertion order = recency
	const inflight = new Map<string, Promise<Blob>>()
	let cached = 0

	const remember = (name: string, blob: Blob): void => {
		if (blob.size > maxBytes) {
			return
		}
		cache.set(name, blob)
		cached += blob.size
		for (const [key, value] of cache) {
			if (cached <= maxBytes || key === name) {
				break
			}
			cache.delete(key)
			cached -= value.size
		}
	}

	const fetchEntry = (name: string): Promise<Blob> => {
		const hit = cache.get(name)
		if (hit) {
			cache.delete(name)
			cache.set(name, hit)
			return Promise.resolve(hit)
		}
		let p = inflight.get(name)
		if (!p) {
			p = source.loadEntry(name).then((blob) => {
				remember(name, blob)
				return blob
			}).finally(() => inflight.delete(name))
			inflight.set(name, p)
		}
		return p
	}

	return {
		entries: source.entries.map((e) => ({ filename: e.name, uncompressedSize: e.size })),
		loadText: (name) => sizes.has(name) ? fetchEntry(name).then(blobText) : null,
		loadBlob: (name, type) => sizes.has(name)
			? fetchEntry(name).then((b) => type && b.type !== type ? new Blob([b], { type }) : b)
			: null,
		getSize: (name) => sizes.get(name) ?? 0,
	}
}
