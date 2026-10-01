/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import * as uploadApi from '../services/upload.ts'
import { useLibraryStore } from './library.ts'
import { useSettingsStore } from './settings.ts'

export type UploadItemStatus = 'uploading' | 'done' | 'failed' | 'cancelled'

export interface UploadItem {
	id: number
	/** name in the target folder (may differ from the file name to avoid overwriting) */
	name: string
	originalName: string
	size: number
	/** 0..1 */
	progress: number
	status: UploadItemStatus
	error: string | null
}

export interface UploadSummary {
	uploaded: number
	failed: number
	cancelled: number
	rejected: string[]
	renamed: number
	/** a successfully uploaded file is large: the server indexes it with a delay */
	large: boolean
}

const PROGRESS_POLL_MS = 250
const LARGE_FILE_RELOAD_MS = 5000

export const useUploadStore = defineStore('upload', () => {
	const items = ref<UploadItem[]>([])
	const running = ref(false)
	const folderOverride = ref<string | null>(null)
	const cancels = new Map<number, () => void>()
	let nextId = 1

	const settings = useSettingsStore()
	const folder = computed(() => folderOverride.value ?? settings.settings.libraryFolders[0] ?? '/Books')
	const totalSize = computed(() => items.value.reduce((s, i) => s + i.size, 0))
	const totalProgress = computed(() => {
		if (totalSize.value === 0) {
			return 0
		}
		return items.value.reduce((s, i) => s + i.size * i.progress, 0) / totalSize.value
	})

	/**
	 * @param path user-relative folder, null for the first library folder
	 */
	function setFolder(path: string | null): void {
		folderOverride.value = path
	}

	/**
	 * Uploads books into the target folder without overwriting existing files.
	 *
	 * @param files
	 */
	async function start(files: File[]): Promise<UploadSummary> {
		const { accepted, rejected } = uploadApi.partitionFiles(files)
		const summary: UploadSummary = { uploaded: 0, failed: 0, cancelled: 0, rejected: rejected.map((f) => f.name), renamed: 0, large: false }
		if (accepted.length === 0) {
			return summary
		}
		if (!running.value) {
			items.value = []
		}
		running.value = true
		const target = folder.value
		const added: { item: UploadItem, file: File }[] = []
		try {
			await uploadApi.ensureFolder(target)
			const names = uploadApi.assignNames(
				accepted.map((f) => f.name),
				await uploadApi.listFolderNames(target),
			)
			accepted.forEach((file, i) => {
				const item: UploadItem = {
					id: nextId++,
					name: names[i]!,
					originalName: file.name,
					size: file.size,
					progress: 0,
					status: 'uploading',
					error: null,
				}
				if (item.name !== file.name) {
					summary.renamed++
				}
				added.push({ item, file })
			})
			items.value = [...items.value, ...added.map((a) => a.item)]
			// take the reactive proxies so that updates are seen by the views
			const live = (id: number): UploadItem => items.value.find((i) => i.id === id)!
			await Promise.all(added.map(async ({ item, file }) => {
				const run = uploadApi.uploadFile(target, item.name, file)
				cancels.set(item.id, run.cancel)
				const timer = setInterval(() => {
					const it = live(item.id)
					if (it.status === 'uploading') {
						it.progress = run.progress()
					}
				}, PROGRESS_POLL_MS)
				try {
					await run.done
					live(item.id).progress = 1
					live(item.id).status = 'done'
					summary.uploaded++
					if (file.size >= uploadApi.LARGE_FILE_BYTES) {
						summary.large = true
					}
				} catch (e) {
					const it = live(item.id)
					if (it.status === 'cancelled') {
						summary.cancelled++
					} else {
						it.status = 'failed'
						it.error = typeof e === 'string' ? e : (e instanceof Error ? e.message : String(e))
						summary.failed++
					}
				} finally {
					clearInterval(timer)
					cancels.delete(item.id)
				}
			}))
		} catch {
			// folder could not be created or listed: nothing was uploaded
			summary.failed = accepted.length
		} finally {
			running.value = false
		}
		if (summary.uploaded > 0) {
			const library = useLibraryStore()
			await Promise.all([library.reload(), library.loadFacets()])
			if (summary.large) {
				// big files are indexed by a background job
				setTimeout(() => {
					void library.reload()
					void library.loadFacets()
				}, LARGE_FILE_RELOAD_MS)
			}
		}
		return summary
	}

	/**
	 * @param id
	 */
	function cancel(id: number): void {
		const item = items.value.find((i) => i.id === id)
		if (item && item.status === 'uploading') {
			item.status = 'cancelled'
			cancels.get(id)?.()
		}
	}

	/**
	 *
	 */
	function cancelAll(): void {
		items.value.forEach((i) => cancel(i.id))
	}

	/**
	 *
	 */
	function dismiss(): void {
		if (!running.value) {
			items.value = []
		}
	}

	return { items, running, folder, totalProgress, setFolder, start, cancel, cancelAll, dismiss }
})
