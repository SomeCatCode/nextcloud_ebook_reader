/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Lazy loader for the libarchive.js worker (CBR). Worker and wasm are our own build with a current
 * libarchive (packages/libarchive/README.md); the main thread API stays the npm package. Source and
 * wasm are bundled by Vite as assets; reader-core starts the worker from a blob: URL (needs `worker-src blob:` and
 * `'wasm-unsafe-eval'`, see lib/Listener/CspListener.php).
 */
export async function loadLibarchive(): Promise<{ workerSource: string, wasmUrl: string }> {
	const [worker, wasm] = await Promise.all([
		import('../../../packages/libarchive/dist/worker-bundle.js?raw'),
		import('../../../packages/libarchive/dist/libarchive.wasm?url'),
	])
	return { workerSource: worker.default, wasmUrl: wasm.default }
}
