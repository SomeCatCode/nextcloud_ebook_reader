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
					<PageGrid v-else-if="isComic" :fileId="structure.fileId" />
					<ContentList v-else :fileId="structure.fileId" :format="structure.format" />
				</template>
				<template v-if="activeTab === 'toc'">
					<p v-if="!structure.capabilities.toc" class="editor-view__na">
						{{ t('ebookreader', 'Editing the table of contents is not supported for this format.') }}
					</p>
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

		<RenameDialog
			v-model:open="renameOpen"
			:fileId="fileIdNum"
			:path="book?.path ?? null"
			@renamed="onRenamed" />
	</div>
</template>

<script setup lang="ts">
import type { Book, SaveResult, Structure } from '../types.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, provide, ref, shallowRef, watch } from 'vue'
import { onBeforeRouteLeave, useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ContentList from '../components/editor/ContentList.vue'
import MetadataForm from '../components/editor/MetadataForm.vue'
import PageGrid from '../components/editor/PageGrid.vue'
import RenameDialog from '../components/editor/RenameDialog.vue'
import TocTreeEditor from '../components/editor/TocTreeEditor.vue'
import { convertCbrToCbz, deleteOriginal, TargetExistsError, uploadCbz } from '../editor/cbrToCbz.ts'
import { EDITOR_STATE_KEY, useEditorState } from '../editor/useEditorState.ts'
import { ConflictError, getBook, getStructure, putStructure, scan } from '../services/api.ts'

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
const activeTab = ref<'metadata' | 'content' | 'toc'>('metadata')
const isComic = computed(() => structure.value?.format === 'cbz' || structure.value?.format === 'cbr')

const tabs = computed(() => [
	{ id: 'metadata' as const, label: t('ebookreader', 'Metadata') },
	{ id: 'content' as const, label: isComic.value ? t('ebookreader', 'Pages') : t('ebookreader', 'Content') },
	{ id: 'toc' as const, label: t('ebookreader', 'Table of contents') },
])

let allowLeave = false

/**
 * @param fileId
 */
async function load(fileId = fileIdNum.value): Promise<void> {
	loading.value = true
	loadError.value = ''
	try {
		const [s, b] = await Promise.all([getStructure(fileId), getBook(fileId).catch(() => null)])
		state.load(s as Structure)
		book.value = b
	} catch (e) {
		loadError.value = (e as Error).message || t('ebookreader', 'Could not load the book structure.')
	} finally {
		loading.value = false
	}
}

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
})

/**
 *
 */
function goBack(): void {
	void router.push({ name: 'library' })
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
const conflictOpen = ref(false)
const renameOpen = ref(false)

/**
 * @param asCopy
 */
function openSave(asCopy: boolean): void {
	saveAsCopy.value = asCopy
	summary.value = state.computeChangeSummary((s, v) => t('ebookreader', s, v))
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
	busy.value = true
	try {
		const result = await putStructure(fileIdNum.value, state.buildEditRequest(saveAsCopy.value))
		if (result.warnings?.length) {
			resultWarnings.value = result
		} else {
			await afterSave(result)
		}
	} catch (e) {
		if (e instanceof ConflictError) {
			conflictOpen.value = true
		} else {
			showError(t('ebookreader', 'Saving failed: {message}', { message: (e as Error).message }))
		}
	} finally {
		busy.value = false
	}
}

/**
 *
 */
async function finishSave(): Promise<void> {
	const r = resultWarnings.value
	resultWarnings.value = null
	if (r) {
		await afterSave(r)
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
		await load()
	}
}

/**
 *
 */
async function reloadAfterConflict(): Promise<void> {
	conflictOpen.value = false
	await load()
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
