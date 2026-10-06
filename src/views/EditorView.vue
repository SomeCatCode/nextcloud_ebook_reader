<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="editor-view">
		<header class="editor-view__header">
			<NcButton variant="tertiary" @click="goBack">
				&larr; {{ t('ebookreader', 'Back') }}
			</NcButton>
			<h2 class="editor-view__title">
				{{ state.metadata.value.title || book?.path?.split('/').pop() || t('ebookreader', 'Edit e-book') }}
				<span v-if="state.dirty.value" class="editor-view__dirty" :title="t('ebookreader', 'Unsaved changes')">&#9679;</span>
			</h2>
			<div class="editor-view__actions">
				<NcButton :disabled="!structure || busy" @click="renameOpen = true">
					{{ t('ebookreader', 'Rename file…') }}
				</NcButton>
				<NcButton :disabled="!state.undoStack.value.length" variant="tertiary" @click="state.undo()">
					{{ t('ebookreader', 'Undo') }}
				</NcButton>
				<NcButton :disabled="!state.dirty.value || busy" variant="tertiary" @click="discard">
					{{ t('ebookreader', 'Discard') }}
				</NcButton>
				<NcButton :disabled="!structure || busy" @click="openSave(true)">
					{{ t('ebookreader', 'Save as copy') }}
				</NcButton>
				<NcButton :disabled="!structure || !structure.editable || !state.dirty.value || busy" variant="primary" @click="openSave(false)">
					{{ t('ebookreader', 'Save') }}
				</NcButton>
			</div>
		</header>

		<NcLoadingIcon v-if="loading" :size="44" class="editor-view__loading" />
		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>

		<template v-else-if="structure">
			<NcNoteCard v-if="!structure.editable" type="warning">
				{{ t('ebookreader', 'This file is a read-only share. Only "Save as copy" is possible.') }}
			</NcNoteCard>
			<NcNoteCard v-if="!structure.capabilities.writesFile" type="info">
				{{ t('ebookreader', 'The file format cannot be modified in place. Metadata is stored in the app only, the file stays unchanged.') }}
				<template v-if="structure.format === 'cbr'">
					<br>
					<NcButton :disabled="busy || converting" @click="convertCbr">
						{{ converting ? convertProgress : t('ebookreader', 'Convert to CBZ') }}
					</NcButton>
				</template>
			</NcNoteCard>
			<NcNoteCard v-for="w in structure.warnings" :key="w" type="warning">
				{{ w }}
			</NcNoteCard>

			<nav class="editor-view__tabs" role="tablist">
				<NcButton
					v-for="tab in tabs"
					:key="tab.id"
					role="tab"
					:aria-selected="activeTab === tab.id"
					:variant="activeTab === tab.id ? 'primary' : 'tertiary'"
					@click="activeTab = tab.id">
					{{ tab.label }}
				</NcButton>
			</nav>

			<main class="editor-view__body" role="tabpanel">
				<MetadataForm
					v-show="activeTab === 'metadata'"
					:fileId="structure.fileId"
					:etag="structure.etag"
					:capabilities="structure.capabilities"
					:isComic="isComic"
					:disabled="!structure.capabilities.metadata" />
				<template v-if="activeTab === 'content'">
					<p v-if="!structure.capabilities.content" class="editor-view__na">
						{{ t('ebookreader', 'Reordering and removing content is not supported for this format.') }}
					</p>
					<NcNoteCard v-else-if="contentError" type="error">
						{{ contentError }}
						<NcButton @click="ensureContent">
							{{ t('ebookreader', 'Retry') }}
						</NcButton>
					</NcNoteCard>
					<NcLoadingIcon v-else-if="structure.partial" :size="44" class="editor-view__loading" />
					<PageGrid v-else-if="isComic" :fileId="structure.fileId" />
					<ContentList v-else :fileId="structure.fileId" :format="structure.format" />
				</template>
				<template v-if="activeTab === 'toc'">
					<p v-if="!structure.capabilities.toc" class="editor-view__na">
						{{ t('ebookreader', 'Editing the table of contents is not supported for this format.') }}
					</p>
					<NcNoteCard v-else-if="contentError" type="error">
						{{ contentError }}
						<NcButton @click="ensureContent">
							{{ t('ebookreader', 'Retry') }}
						</NcButton>
					</NcNoteCard>
					<NcLoadingIcon v-else-if="structure.partial" :size="44" class="editor-view__loading" />
					<TocTreeEditor v-else />
				</template>
			</main>
		</template>

		<!-- change summary -->
		<NcDialog
			v-if="summaryOpen"
			:name="saveAsCopy ? t('ebookreader', 'Save as copy') : t('ebookreader', 'Save changes')"
			:buttons="summaryButtons"
			@update:open="summaryOpen = false">
			<p v-if="summary.length === 0">
				{{ t('ebookreader', 'No changes.') }}
			</p>
			<ul v-else class="editor-view__summary">
				<li v-for="s in summary" :key="s">
					{{ s }}
				</li>
			</ul>
			<NcNoteCard v-if="saveAsCopy" type="info">
				{{ t('ebookreader', 'A new file "… (edited)" is created, the original stays unchanged.') }}
			</NcNoteCard>
			<NcNoteCard v-else-if="structure && !structure.capabilities.writesFile" type="info">
				{{ t('ebookreader', 'Only the app database is updated.') }}
			</NcNoteCard>
		</NcDialog>

		<!-- progress while saving (can take a while for large files) -->
		<NcDialog
			v-if="saveProgress"
			:name="saveAsCopy ? t('ebookreader', 'Saving copy…') : t('ebookreader', 'Saving…')"
			:buttons="progressButtons"
			noClose
			:closeOnClickOutside="false">
			<div class="editor-view__progress" role="status" aria-live="polite">
				<template v-if="saveProgress.phase === 'upload'">
					<p>{{ t('ebookreader', 'Uploading changes… {percent} %', { percent: Math.round(saveProgress.upload * 100) }) }}</p>
					<NcProgressBar :value="Math.round(saveProgress.upload * 100)" size="medium" />
				</template>
				<TaskProgress
					v-else-if="saveProgress.phase === 'server'"
					:progress="saveTask?.progress ?? 0"
					:step="saveTask?.step || t('ebookreader', 'The server is rewriting and checking the book…')"
					:status="saveTask?.status"
					:hint="saveTask ? t('ebookreader', 'The task keeps running on the server, even if you leave this page.') : undefined" />
				<template v-else>
					<p class="editor-view__progress-row">
						<NcLoadingIcon :size="20" />
						{{ t('ebookreader', 'Loading the saved version…') }}
					</p>
					<NcProgressBar :value="95" size="medium" />
				</template>
				<p v-if="saveProgress.phase !== 'server'" class="editor-view__muted">
					{{ t('ebookreader', '{seconds} s elapsed.', { seconds: saveProgress.seconds }) }}
				</p>
			</div>
		</NcDialog>

		<!-- warnings after save -->
		<NcDialog
			v-if="resultWarnings"
			:name="t('ebookreader', 'Saved with warnings')"
			:buttons="[{ label: t('ebookreader', 'OK'), variant: 'primary', callback: finishSave }]"
			@update:open="finishSave">
			<ul class="editor-view__summary">
				<li v-for="w in resultWarnings.warnings" :key="w">
					{{ w }}
				</li>
			</ul>
		</NcDialog>

		<!-- conflict -->
		<NcDialog
			v-if="conflictOpen"
			:name="t('ebookreader', 'File changed')"
			:buttons="[
				{ label: t('ebookreader', 'Keep editing'), variant: 'tertiary', callback: () => { conflictOpen = false } },
				{ label: t('ebookreader', 'Reload'), variant: 'primary', callback: reloadAfterConflict },
			]"
			@update:open="conflictOpen = false">
			<p>{{ t('ebookreader', 'The file was changed in the meantime. Reload to get the latest version. Your unsaved changes will be lost.') }}</p>
		</NcDialog>

		<!-- generic confirm -->
		<NcDialog
			v-if="confirm"
			:name="confirm.title"
			:message="confirm.message"
			:buttons="[
				{ label: t('ebookreader', 'Cancel'), variant: 'tertiary', callback: () => resolveConfirm(false) },
				{ label: confirm.confirmLabel, variant: 'primary', callback: () => resolveConfirm(true) },
			]"
			@update:open="resolveConfirm(false)" />

		<LargeDownloadDialog
			v-if="largeDownload.pending.value"
			:sizeBytes="largeDownload.pending.value.size"
			@confirm="largeDownload.answer(true)"
			@cancel="largeDownload.answer(false)" />

		<RenameDialog
			v-model:open="renameOpen"
			:fileId="fileIdNum"
			:path="book?.path ?? null"
			@renamed="onRenamed" />
	</div>
</template>

<script setup lang="ts">
import type { Book, MetadataPatch, SaveResult, Structure, Task } from '../types.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, provide, ref, shallowRef, watch } from 'vue'
import { onBeforeRouteLeave, useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import LargeDownloadDialog from '../components/common/LargeDownloadDialog.vue'
import TaskProgress from '../components/common/TaskProgress.vue'
import ContentList from '../components/editor/ContentList.vue'
import MetadataForm from '../components/editor/MetadataForm.vue'
import PageGrid from '../components/editor/PageGrid.vue'
import RenameDialog from '../components/editor/RenameDialog.vue'
import TocTreeEditor from '../components/editor/TocTreeEditor.vue'
import { convertCbrToCbz, deleteOriginal, TargetExistsError, uploadCbz } from '../editor/cbrToCbz.ts'
import { EDITOR_STATE_KEY, isMetadataOnlyRequest, useEditorState } from '../editor/useEditorState.ts'
import { ConflictError, getBook, getStructure, patchMetadata, putStructureAsync, scan } from '../services/api.ts'
import { DownloadDeclinedError, ensureDownloadConfirmed } from '../services/largeDownload.ts'
import { pollTask, TaskFailedError } from '../services/tasks.ts'
import { useLargeDownloadConfirm } from '../services/useLargeDownloadConfirm.ts'

const props = defineProps<{ fileId?: string }>()

const router = useRouter()
const state = useEditorState()
provide(EDITOR_STATE_KEY, state)

const fileIdNum = computed(() => Number(props.fileId))
const structure = state.structure
const book = shallowRef<Book | null>(null)
const loading = ref(true)
const loadError = ref('')
const busy = ref(false)
/** Save progress for the progress dialog; null when not saving */
const saveProgress = ref<{ phase: 'upload' | 'server' | 'reload', upload: number, seconds: number } | null>(null)
const saveTask = ref<Task | null>(null)
const largeDownload = useLargeDownloadConfirm()
let saveAbort: AbortController | null = null
const activeTab = ref<'metadata' | 'content' | 'toc'>('metadata')
const isComic = computed(() => structure.value?.format === 'cbz' || structure.value?.format === 'cbr')

const tabs = computed(() => [
	{ id: 'metadata' as const, label: t('ebookreader', 'Metadata') },
	{ id: 'content' as const, label: isComic.value ? t('ebookreader', 'Pages') : t('ebookreader', 'Content') },
	{ id: 'toc' as const, label: t('ebookreader', 'Table of contents') },
])

let allowLeave = false

const contentLoading = ref(false)
const contentError = ref('')

/**
 * Loads the structure. By default only the metadata part (answered from the library, the book file is not read);
 * items and table of contents follow when a tab needs them.
 *
 * @param fileId
 * @param withContent load items and toc right away (reload after a content save or conflict)
 */
async function load(fileId = fileIdNum.value, withContent = false): Promise<void> {
	loading.value = true
	loadError.value = ''
	contentError.value = ''
	try {
		const [s, b] = await Promise.all([getStructure(fileId, withContent ? 'all' : 'metadata'), getBook(fileId).catch(() => null)])
		state.load(s as Structure)
		book.value = b
	} catch (e) {
		loadError.value = (e as Error).message || t('ebookreader', 'Could not load the book structure.')
	} finally {
		loading.value = false
	}
	if (activeTab.value !== 'metadata') {
		void ensureContent()
	}
}

/**
 * Loads items and table of contents (reads the book file) if only the metadata part is present yet.
 */
async function ensureContent(): Promise<void> {
	const s = structure.value
	if (!s || !s.partial || contentLoading.value || (!s.capabilities.content && !s.capabilities.toc)) {
		return
	}
	contentLoading.value = true
	contentError.value = ''
	try {
		state.loadContent(await getStructure(s.fileId, 'all'))
	} catch (e) {
		contentError.value = (e as Error).message || t('ebookreader', 'Could not load the book structure.')
	} finally {
		contentLoading.value = false
	}
}

watch(activeTab, (tab) => {
	if (tab !== 'metadata') {
		void ensureContent()
	}
})

watch(() => props.fileId, () => {
	allowLeave = false
	void load()
})

// ---- confirm helper ------------------------------------------------------

interface ConfirmState { title: string, message: string, confirmLabel: string, resolve: (v: boolean) => void }
const confirm = ref<ConfirmState | null>(null)

/**
 * @param title
 * @param message
 * @param confirmLabel
 */
function askConfirm(title: string, message: string, confirmLabel: string): Promise<boolean> {
	return new Promise((resolve) => {
		confirm.value = { title, message, confirmLabel, resolve }
	})
}

/**
 * @param v
 */
function resolveConfirm(v: boolean): void {
	const c = confirm.value
	confirm.value = null
	c?.resolve(v)
}

// ---- leave guard ---------------------------------------------------------

onBeforeRouteLeave(async () => {
	if (!state.dirty.value || allowLeave) {
		return true
	}
	return await askConfirm(
		t('ebookreader', 'Unsaved changes'),
		t('ebookreader', 'You have unsaved changes. Leave without saving?'),
		t('ebookreader', 'Leave'),
	)
})

/**
 * @param e
 */
function onBeforeUnload(e: BeforeUnloadEvent): void {
	if (state.dirty.value && !allowLeave) {
		e.preventDefault()
		e.returnValue = ''
	}
}

/**
 * @param e
 */
function onKeydown(e: KeyboardEvent): void {
	if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key.toLowerCase() === 'z') {
		const el = e.target as HTMLElement | null
		if (el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName))) {
			return
		}
		if (state.undo()) {
			e.preventDefault()
		}
	}
}

onMounted(() => {
	window.addEventListener('beforeunload', onBeforeUnload)
	window.addEventListener('keydown', onKeydown)
	void load()
})

onBeforeUnmount(() => {
	window.removeEventListener('beforeunload', onBeforeUnload)
	window.removeEventListener('keydown', onKeydown)
	saveAbort?.abort()
	largeDownload.answer(false)
})

/**
 *
 */
/**
 * Back to the list the editor was opened from (keeps filters, shelf and scroll position, like the
 * reader does); falls back to the library when the editor was opened directly.
 */
function returnToList(): void {
	const back = window.history.state?.back
	if (typeof back === 'string' && !back.startsWith('/edit/')) {
		router.back()
	} else {
		void router.push({ name: 'library' })
	}
}

/**
 *
 */
function goBack(): void {
	returnToList()
}

/**
 *
 */
async function discard(): Promise<void> {
	if (await askConfirm(t('ebookreader', 'Discard changes'), t('ebookreader', 'Discard all unsaved changes?'), t('ebookreader', 'Discard'))) {
		if (structure.value) {
			state.load(structure.value)
		}
	}
}

// ---- save flow -----------------------------------------------------------

const summaryOpen = ref(false)
const saveAsCopy = ref(false)
const summary = ref<string[]>([])
const resultWarnings = ref<SaveResult | null>(null)

/** After a successful save: return to the list, but only after the warnings dialog was closed */
let returnAfterWarnings = false

/**
 * @param result
 * @param result.warnings
 */
function finishSaved(result: { warnings?: string[] }): void {
	if (result.warnings?.length) {
		returnAfterWarnings = true
		resultWarnings.value = result as SaveResult
	} else {
		returnToList()
	}
}
const conflictOpen = ref(false)
const renameOpen = ref(false)

/**
 * @param asCopy
 */
function openSave(asCopy: boolean): void {
	saveAsCopy.value = asCopy
	// The texts are literal tr() calls in useEditorState.ts and are extracted there.
	summary.value = state.computeChangeSummary((s, v) => t('ebookreader', s, v)) // l10n-ignore
	summaryOpen.value = true
}

const summaryButtons = computed(() => [
	{ label: t('ebookreader', 'Cancel'), variant: 'tertiary' as const, callback: () => { summaryOpen.value = false } },
	{ label: saveAsCopy.value ? t('ebookreader', 'Save as copy') : t('ebookreader', 'Save'), variant: 'primary' as const, callback: () => { void doSave() } },
])

/**
 *
 */
async function doSave(): Promise<void> {
	summaryOpen.value = false
	const req = state.buildEditRequest(saveAsCopy.value)
	// Background writes only while the content is not loaded: once pages/TOC are open, a queued
	// metadata job would change the file etag under the editor and make the next page save a 409.
	if (isMetadataOnlyRequest(req) && req.metadata && structure.value?.partial !== false) {
		await saveMetadataOnly(req.metadata)
		return
	}
	busy.value = true
	saveTask.value = null
	saveAbort = new AbortController()
	saveProgress.value = { phase: 'upload', upload: 0, seconds: 0 }
	const started = Date.now()
	const timer = window.setInterval(() => {
		if (saveProgress.value) {
			saveProgress.value = { ...saveProgress.value, seconds: Math.round((Date.now() - started) / 1000) }
		}
	}, 1000)
	try {
		const started = await putStructureAsync(fileIdNum.value, req, (f) => {
			if (saveProgress.value) {
				saveProgress.value = { ...saveProgress.value, upload: f, phase: f >= 1 ? 'server' : 'upload' }
			}
		})
		let result: SaveResult
		if ('sync' in started) {
			// older server: saved synchronously
			result = started.sync
		} else {
			if (saveProgress.value) {
				saveProgress.value = { ...saveProgress.value, upload: 1, phase: 'server' }
			}
			const done = await pollTask(started.taskId, {
				signal: saveAbort.signal,
				onUpdate: (tk) => {
					saveTask.value = tk
				},
			})
			result = { book: done.result?.book as Book, warnings: done.result?.warnings ?? [] }
		}
		saveProgress.value = { phase: 'reload', upload: 1, seconds: saveProgress.value?.seconds ?? 0 }
		// Always reload right away (new etag, renumbered pages); warnings are shown afterwards,
		// so closing the warnings dialog in any way can not leave a stale state behind.
		await afterSave(result)
		if (!saveAsCopy.value) {
			finishSaved(result)
		} else if (result.warnings?.length) {
			resultWarnings.value = result
		}
	} catch (e) {
		if ((e as Error)?.name === 'AbortError') {
			// left the page, the task keeps running on the server
		} else if (e instanceof ConflictError) {
			conflictOpen.value = true
		} else if (e instanceof TaskFailedError) {
			showError(t('ebookreader', 'Saving failed: {message}', { message: e.message }))
		} else {
			showError(t('ebookreader', 'Saving failed: {message}', { message: (e as Error).message }))
		}
	} finally {
		window.clearInterval(timer)
		saveProgress.value = null
		saveTask.value = null
		saveAbort = null
		busy.value = false
	}
}

/** While the server works the user may leave: the task keeps running there. */
const progressButtons = computed(() => saveProgress.value?.phase === 'server' && saveTask.value
	? [{ label: t('ebookreader', 'Continue in background'), variant: 'tertiary' as const, callback: (): void => {
			saveAbort?.abort()
			allowLeave = true
			goBack()
		} }]
	: [])

/**
 * Metadata-only save: PATCH metadata, which honours the user's write mode (the file is usually written later in the
 * background). The book file is not read or rewritten here, so no progress dialog and no content reload are needed.
 *
 * @param patch
 */
async function saveMetadataOnly(patch: MetadataPatch): Promise<void> {
	busy.value = true
	try {
		const result = await patchMetadata(fileIdNum.value, patch)
		showSuccess(result.writeQueued
			? t('ebookreader', 'Saved – will be written into the file in the background')
			: t('ebookreader', 'Changes saved'))
		const [s, b] = await Promise.all([getStructure(fileIdNum.value, 'metadata'), getBook(fileIdNum.value).catch(() => null)])
		state.reloadMetadata(s)
		book.value = b
		finishSaved(result)
	} catch (e) {
		showError(t('ebookreader', 'Saving failed: {message}', { message: (e as Error).message }))
	} finally {
		busy.value = false
	}
}

/**
 *
 */
function finishSave(): void {
	resultWarnings.value = null
	if (returnAfterWarnings) {
		returnAfterWarnings = false
		returnToList()
	}
}

/**
 * @param result
 */
async function afterSave(result: SaveResult): Promise<void> {
	if (saveAsCopy.value) {
		showSuccess(t('ebookreader', 'Copy saved'))
		allowLeave = true
		await router.push({ name: 'editor', params: { fileId: String(result.book.fileId) } })
	} else {
		showSuccess(t('ebookreader', 'Changes saved'))
		await load(fileIdNum.value, !structure.value?.partial)
	}
}

/**
 *
 */
async function reloadAfterConflict(): Promise<void> {
	conflictOpen.value = false
	await load(fileIdNum.value, !structure.value?.partial)
}

/**
 * @param path
 */
function onRenamed(path: string): void {
	showSuccess(t('ebookreader', 'File renamed'))
	if (book.value) {
		book.value = { ...book.value, path }
	}
}

// ---- CBR -> CBZ ----------------------------------------------------------

const converting = ref(false)
const convertProgress = ref('')

/**
 *
 */
async function convertCbr(): Promise<void> {
	if (!book.value) {
		return
	}
	try {
		await ensureDownloadConfirmed(book.value, largeDownload.ask)
	} catch (e) {
		if (e instanceof DownloadDeclinedError) {
			return
		}
		throw e
	}
	converting.value = true
	try {
		const cbz = await convertCbrToCbz(book.value, {
			onProgress: (done, total) => {
				convertProgress.value = t('ebookreader', 'Converting… {done}/{total}', { done, total })
			},
		})
		let overwrite = false
		for (;;) {
			try {
				await uploadCbz(book.value.path, cbz, overwrite)
				break
			} catch (e) {
				if (e instanceof TargetExistsError) {
					if (!await askConfirm(t('ebookreader', 'File exists'), t('ebookreader', '"{path}" already exists. Overwrite it?', { path: e.path }), t('ebookreader', 'Overwrite'))) {
						return
					}
					overwrite = true
				} else {
					throw e
				}
			}
		}
		if (await askConfirm(t('ebookreader', 'Delete original?'), t('ebookreader', 'The CBZ was created. Move the original CBR file to the trash?'), t('ebookreader', 'Delete original'))) {
			await deleteOriginal(book.value.path)
		}
		showSuccess(t('ebookreader', 'Converted to CBZ'))
		await scan().catch(() => {})
		goBack()
	} catch (e) {
		showError(t('ebookreader', 'Conversion failed: {message}', { message: (e as Error).message }))
	} finally {
		converting.value = false
	}
}
</script>

<style scoped lang="scss">
.editor-view__progress { display: flex; flex-direction: column; gap: 12px; min-width: min(420px, 80vw); }
.editor-view__progress-row { display: flex; align-items: center; gap: 8px; }
.editor-view__muted { opacity: .7; font-size: .9em; }
.editor-view {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px 16px 48px;
	max-width: 1200px;
	margin: 0 auto;
	box-sizing: border-box;

	&__header {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 12px;
	}

	&__title {
		flex: 1;
		min-width: 200px;
		margin: 0;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__dirty {
		color: var(--color-warning-text);
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
	}

	&__tabs {
		display: flex;
		gap: 8px;
		border-bottom: 1px solid var(--color-border);
		padding-bottom: 8px;
	}

	&__loading {
		margin: 48px auto;
	}

	&__na {
		color: var(--color-text-maxcontrast);
	}

	&__summary {
		list-style: disc;
		padding-inline-start: 24px;
	}
}
</style>
