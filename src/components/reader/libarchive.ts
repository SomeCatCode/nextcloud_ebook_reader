/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Lazy loader for the libarchive.js worker (CBR). Source and wasm are bundled by Vite as
 * assets; reader-core starts the worker from a blob: URL (needs `worker-src blob:` and
 * `'wasm-unsafe-eval'`, see lib/Listener/CspListener.php).
 */
export async function loadLibarchive(): Promise<{ workerSource: string, wasmUrl: string }> {
	const [worker, wasm] = await Promise.all([
		import('libarchive.js/dist/worker-bundle.js?raw'),
		import('libarchive.js/dist/libarchive.wasm?url'),
	])
	return { workerSource: worker.default, wasmUrl: wasm.default }
}
