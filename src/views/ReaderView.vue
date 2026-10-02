<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ebr" :class="{ 'ebr--embedded': embedded }" :data-theme="effectiveTheme">
		<header v-show="chrome" class="ebr__bar ebr__bar--top">
			<NcButton
				v-if="!embedded"
				:aria-label="t('ebookreader', 'Close')"
				variant="tertiary"
				@click="close">
				<template #icon>
					<ReaderIcon name="close" />
				</template>
			</NcButton>
			<NcButton
				:aria-label="t('ebookreader', 'Table of contents')"
				variant="tertiary"
				@click="togglePanel('toc')">
				<template #icon>
					<ReaderIcon name="menu" />
				</template>
			</NcButton>
			<h1 class="ebr__title" :title="title">
				{{ title }}
			</h1>
			<NcButton
				v-if="state === 'ready'"
				:aria-label="bookmarkOnPage ? t('ebookreader', 'Remove bookmark') : t('ebookreader', 'Add bookmark')"
				:title="bookmarkOnPage ? t('ebookreader', 'Remove bookmark') : t('ebookreader', 'Add bookmark')"
				:pressed="bookmarkOnPage"
				variant="tertiary"
				@click="toggleBookmark">
				<template #icon>
					<ReaderIcon :name="bookmarkOnPage ? 'bookmark' : 'bookmark-outline'" />
				</template>
			</NcButton>
			<NcButton
				:aria-label="t('ebookreader', 'Highlights & bookmarks')"
				:title="t('ebookreader', 'Highlights & bookmarks')"
				variant="tertiary"
				@click="togglePanel('annotations')">
				<template #icon>
					<ReaderIcon name="notes" />
				</template>
			</NcButton>
			<NcButton
				v-if="!isComic"
				:aria-label="t('ebookreader', 'Search')"
				variant="tertiary"
				@click="togglePanel('search')">
				<template #icon>
					<ReaderIcon name="search" />
				</template>
			</NcButton>
			<NcPopover :shown="settingsOpen" @update:shown="settingsOpen = $event">
				<template #trigger>
					<NcButton :aria-label="t('ebookreader', 'Reader settings')" variant="tertiary">
						<template #icon>
							<ReaderIcon name="text" />
						</template>
					</NcButton>
				</template>
				<ReaderSettings :model="viewSettings" :isComic="isComic" @change="onSettingsChange" />
			</NcPopover>
			<NcButton
				v-if="book?.editable"
				:aria-label="t('ebookreader', 'Edit book')"
				variant="tertiary"
				@click="edit">
				<template #icon>
					<ReaderIcon name="edit" />
				</template>
			</NcButton>
		</header>

		<div ref="stage" class="ebr__stage" @click.self="onStageClick" />

		<div v-if="state === 'loading'" class="ebr__overlay">
			<NcLoadingIcon :size="44" />
		</div>
		<div v-else-if="state === 'error'" class="ebr__overlay">
			<NcEmptyContent :name="errorTitle" :description="errorText">
				<template #action>
					<NcButton v-if="!embedded" @click="close">
						{{ t('ebookreader', 'Back') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</div>

		<aside v-if="panel" class="ebr__panel" @keydown.esc.stop="panel = null">
			<div class="ebr__panel-head">
				<div v-if="panel === 'toc' || panel === 'annotations'" class="ebr__tabs" role="tablist">
					<button
						type="button"
						role="tab"
						class="ebr__tab"
						:class="{ 'ebr__tab--active': panel === 'toc' }"
						:aria-selected="panel === 'toc'"
						@click="panel = 'toc'">
						{{ t('ebookreader', 'Table of contents') }}
					</button>
					<button
						type="button"
						role="tab"
						class="ebr__tab"
						:class="{ 'ebr__tab--active': panel === 'annotations' }"
						:aria-selected="panel === 'annotations'"
						@click="panel = 'annotations'">
						{{ t('ebookreader', 'Highlights & bookmarks') }}
					</button>
				</div>
				<strong v-else>{{ t('ebookreader', 'Search') }}</strong>
				<NcButton :aria-label="t('ebookreader', 'Close')" variant="tertiary" @click="panel = null">
					<template #icon>
						<ReaderIcon name="close" />
					</template>
				</NcButton>
			</div>
			<div v-if="panel === 'toc'" class="ebr__panel-body">
				<ReaderToc :items="toc" :currentHref="currentHref" @select="onTocSelect" />
				<p v-if="!toc.length" class="ebr__muted">
					{{ t('ebookreader', 'This book has no table of contents.') }}
				</p>
			</div>
			<div v-else-if="panel === 'annotations'" class="ebr__panel-body">
				<ReaderAnnotations
					:highlights="annotations.highlights"
					:notes="annotations.notes"
					:bookmarks="annotations.bookmarks"
					@jump="jumpToAnnotation"
					@edit="editNote"
					@delete="(a) => annotations.remove(a.uuid)"
					@export="exportMarkdown" />
			</div>
			<div v-else class="ebr__panel-body">
				<form class="ebr__search" @submit.prevent="runSearch">
					<input
						v-model="query"
						type="search"
						:placeholder="t('ebookreader', 'Search in book')"
						:aria-label="t('ebookreader', 'Search in book')">
					<NcButton type="submit" variant="primary">
						{{ t('ebookreader', 'Search') }}
					</NcButton>
				</form>
				<progress v-if="searching" :value="searchProgress" max="1" />
				<div v-for="(group, gi) in results" :key="gi" class="ebr__group">
					<div class="ebr__group-label">
						{{ group.label }}
					</div>
					<button
						v-for="(hit, hi) in group.subitems"
						:key="hi"
						type="button"
						class="ebr__hit"
						@click="jumpToHit(hit.cfi)">
						{{ hit.excerpt.pre }}<mark>{{ hit.excerpt.match }}</mark>{{ hit.excerpt.post }}
					</button>
				</div>
				<p v-if="searched && !searching && !results.length" class="ebr__muted">
					{{ t('ebookreader', 'No results.') }}
				</p>
			</div>
		</aside>

		<footer v-show="chrome && state === 'ready'" class="ebr__bar ebr__bar--bottom">
			<input
				class="ebr__seek"
				type="range"
				min="0"
				max="1000"
				:value="Math.round(percentage * 1000)"
				:aria-label="t('ebookreader', 'Reading position')"
				@change="onSeek(Number(($event.target as HTMLInputElement).value) / 1000)">
			<span class="ebr__pct">{{ progressLabel }}</span>
		</footer>

		<AnnotationPopup
			v-if="popup && stage"
			:mode="popup.mode"
			:rect="popupRect"
			:bounds="stageSize"
			:color="popup.mode === 'edit' ? popupAnnotation?.color : null"
			:hasNote="!!popupAnnotation?.note"
			@color="onPopupColor"
			@note="onPopupNote"
			@copy="copySelection"
			@delete="deletePopupAnnotation" />

		<AnnotationNoteDialog
			v-if="noteDialog"
			:quote="noteDialog.quote"
			:initial="noteDialog.initial"
			:allowEmpty="noteDialog.uuid !== null"
			@save="saveNote"
			@close="noteDialog = null" />

		<LargeDownloadDialog
			v-if="largeDownload.pending.value"
			:sizeBytes="largeDownload.pending.value.size"
			@confirm="largeDownload.answer(true)"
			@cancel="largeDownload.answer(false)" />

		<NcDialog
			v-if="conflict"
			:name="t('ebookreader', 'Newer reading position')"
			:message="conflictMessage"
			@closing="keepLocal">
			<template #actions>
				<NcButton @click="keepLocal">
					{{ t('ebookreader', 'Stay here') }}
				</NcButton>
				<NcButton variant="primary" @click="jumpToRemote">
					{{ t('ebookreader', 'Jump') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcDialog
			v-if="externalLink"
			:name="t('ebookreader', 'Open external link?')"
			:message="externalLinkMessage"
			@closing="externalLink = null">
			<template #actions>
				<NcButton @click="externalLink = null">
					{{ t('ebookreader', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" @click="openExternalLink">
					{{ t('ebookreader', 'Open') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script setup lang="ts">
import type { ReaderHandle, ReaderLocator, ReaderSelection, SearchGroup, TocItem } from '../../packages/reader-core/index.ts'
import type { ViewSettings } from '../components/reader/ReaderSettings.vue'
import type { ProgressSync } from '../services/progressSync.ts'
import type { Annotation, AnnotationColor, Book, Locator, Progress, ReaderSettings as ServerReaderSettings } from '../types.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import LargeDownloadDialog from '../components/common/LargeDownloadDialog.vue'
import AnnotationNoteDialog from '../components/reader/AnnotationNoteDialog.vue'
import AnnotationPopup from '../components/reader/AnnotationPopup.vue'
import ReaderAnnotations from '../components/reader/ReaderAnnotations.vue'
import ReaderIcon from '../components/reader/ReaderIcon.vue'
import ReaderSettings from '../components/reader/ReaderSettings.vue'
import ReaderToc from '../components/reader/ReaderToc.vue'
import { createReader, ReaderError } from '../../packages/reader-core/index.ts'
import { loadLibarchive } from '../components/reader/libarchive.ts'
import { annotationsToMarkdown } from '../services/annotationExport.ts'
import { getBook, getSettings, putSettings } from '../services/api.ts'
import { loadBookSource, needsClientCover, uploadClientCover } from '../services/bookSource.ts'
import { DownloadDeclinedError } from '../services/largeDownload.ts'
import { createProgressSync } from '../services/progressSync.ts'
import { useLargeDownloadConfirm } from '../services/useLargeDownloadConfirm.ts'
import { useAnnotationsStore } from '../stores/annotations.ts'

const props = defineProps<{ fileId: string }>()

const logger = {
	// eslint-disable-next-line no-console
	warn: (...a: unknown[]) => console.warn(...a),
	// eslint-disable-next-line no-console
	error: (...a: unknown[]) => console.error(...a),
}

const route = useRoute()
const router = useRouter()
const embedded = computed(() => route.query.embedded === '1')

const largeDownload = useLargeDownloadConfirm()
const stage = ref<HTMLElement | null>(null)
const book = ref<Book | null>(null)
const state = ref<'loading' | 'ready' | 'error'>('loading')
const errorTitle = ref('')
const errorText = ref('')
const chrome = ref(true)
const panel = ref<'toc' | 'search' | 'annotations' | null>(null)
const settingsOpen = ref(false)
const toc = ref<TocItem[]>([])
const currentHref = ref('')
const percentage = ref(0)
const pageInfo = ref<{ current: number, total: number } | null>(null)
const isComic = ref(false)
const isRtl = ref(false)
const prefersDark = ref(globalThis.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false)
const conflict = ref<Progress | null>(null)

const viewSettings = reactive<ViewSettings>({
	theme: 'auto',
	fontSize: 100,
	fontFamily: 'default',
	lineHeight: 1.5,
	margin: 48,
	flow: 'paginated',
	maxColumns: 2,
	comicSpread: 'single',
	comicRtl: false,
	comicZoom: 'fit-page',
})

let reader: ReaderHandle | null = null
let sync: ProgressSync | null = null
let saveTimer: ReturnType<typeof setTimeout> | null = null
let serverSettings: ServerReaderSettings | null = null
const unsubs: (() => void)[] = []

/** Pending external link from the book (confirmation dialog). Only http(s) and mailto are ever opened. */
const externalLink = ref<{ url: string, host: string } | null>(null)
const externalLinkMessage = computed(() => externalLink.value
	? t('ebookreader', 'The book wants to open {host}. Full address: {url}', { host: externalLink.value.host, url: externalLink.value.url }, undefined, { escape: false })
	: '')

/**
 * @param url
 */
function askExternalLink(url: string): void {
	let parsed: URL
	try {
		parsed = new URL(url)
	} catch {
		return
	}
	if (!['http:', 'https:', 'mailto:'].includes(parsed.protocol)) {
		return
	}
	externalLink.value = { url: parsed.href, host: parsed.protocol === 'mailto:' ? parsed.pathname : parsed.host }
}

/**
 * Opens the confirmed link without opener/referrer.
 */
function openExternalLink(): void {
	const link = externalLink.value
	externalLink.value = null
	if (link) {
		window.open(link.url, '_blank', 'noopener,noreferrer')
	}
}
const abort = new AbortController()

// ---- highlights, notes, bookmarks -------------------------------------------
const annotations = useAnnotationsStore()
/** Bumped on every page change: the reader handle is not reactive */
const pageTick = ref(0)
const bookmarkOnPage = computed(() => {
	if (pageTick.value < 0) {
		return false
	}
	return !!reader && annotations.bookmarks.some((b) => reader!.isLocatorOnPage(b.locator as ReaderLocator))
})

/** Floating popup: new selection or an existing highlight */
const popup = ref<{ mode: 'selection' | 'edit', selection?: ReaderSelection, uuid?: string, rect: ReaderSelection['rect'] } | null>(null)
const popupAnnotation = computed(() => popup.value?.uuid ? annotations.byUuid(popup.value.uuid) : undefined)
const popupRect = computed(() => popup.value?.rect ?? { left: 0, top: 0, right: 0, bottom: 0 })
const stageSize = ref({ width: 800, height: 600 })
const noteDialog = ref<{ uuid: string | null, selection?: ReaderSelection, quote: string | null, initial: string | null } | null>(null)

/**
 * @param sel
 */
function onSelection(sel: ReaderSelection): void {
	measureStage()
	popup.value = { mode: 'selection', selection: sel, rect: sel.rect }
}

/**
 * @param id annotation uuid
 * @param rect
 */
function onAnnotationClick(id: string, rect: ReaderSelection['rect']): void {
	measureStage()
	popup.value = { mode: 'edit', uuid: id, rect }
}

/**
 * The popup is positioned inside the stage (the reader container).
 */
function measureStage(): void {
	if (stage.value) {
		stageSize.value = { width: stage.value.clientWidth, height: stage.value.clientHeight }
	}
}

/**
 *
 */
function closePopup(): void {
	popup.value = null
	reader?.clearSelection()
}

/**
 * Color of the popup: creates the highlight (selection) or recolors the existing one.
 *
 * @param color
 */
function onPopupColor(color: AnnotationColor): void {
	const p = popup.value
	if (!p) {
		return
	}
	if (p.mode === 'selection' && p.selection) {
		void annotations.create({ type: 'highlight', locator: p.selection.locator as Locator, text: p.selection.text, color }).catch(() => undefined)
		closePopup()
	} else if (p.uuid) {
		void annotations.update(p.uuid, { color })
	}
}

/**
 *
 */
function onPopupNote(): void {
	const p = popup.value
	if (!p) {
		return
	}
	if (p.mode === 'selection' && p.selection) {
		noteDialog.value = { uuid: null, selection: p.selection, quote: p.selection.text, initial: null }
	} else if (popupAnnotation.value) {
		editNote(popupAnnotation.value)
	}
	popup.value = null
}

/**
 * @param a
 */
function editNote(a: Annotation): void {
	noteDialog.value = { uuid: a.uuid, quote: a.text, initial: a.note }
}

/**
 * @param note
 */
function saveNote(note: string): void {
	const d = noteDialog.value
	noteDialog.value = null
	if (!d) {
		return
	}
	if (d.uuid) {
		void annotations.update(d.uuid, { note })
	} else if (d.selection && note) {
		void annotations.create({ type: 'note', locator: d.selection.locator as Locator, text: d.selection.text, note, color: 'yellow' }).catch(() => undefined)
		reader?.clearSelection()
	}
}

/**
 *
 */
async function copySelection(): Promise<void> {
	const text = popup.value?.selection?.text
	if (!text) {
		return
	}
	try {
		await navigator.clipboard.writeText(text)
		showSuccess(t('ebookreader', 'Copied to the clipboard'))
	} catch {
		showError(t('ebookreader', 'Could not copy the text'))
	}
	closePopup()
}

/**
 *
 */
function deletePopupAnnotation(): void {
	const uuid = popup.value?.uuid
	popup.value = null
	if (uuid) {
		void annotations.remove(uuid)
	}
}

/**
 * Bookmark of the visible page (page index for comics).
 */
async function toggleBookmark(): Promise<void> {
	const loc = reader?.getPageLocator()
	if (!reader || !loc) {
		return
	}
	const r = reader
	const label = loc.title || (isComic.value && pageInfo.value ? t('ebookreader', 'Page {current} of {total}', pageInfo.value) : undefined)
	await annotations.toggleBookmark(loc as Locator, (l) => r.isLocatorOnPage(l as ReaderLocator), label)
}

/**
 * @param a
 */
async function jumpToAnnotation(a: Annotation): Promise<void> {
	if (window.innerWidth < 900) {
		panel.value = null
	}
	await reader?.goTo(a.locator as ReaderLocator)
}

/**
 * Downloads the annotations as a Markdown file (generated in the browser).
 */
function exportMarkdown(): void {
	const name = book.value?.title || book.value?.path.split('/').pop() || ''
	const md = annotationsToMarkdown({ title: name, authors: reader?.getInfo()?.authors }, annotations.sorted)
	const url = URL.createObjectURL(new Blob([md], { type: 'text/markdown;charset=utf-8' }))
	const a = document.createElement('a')
	a.href = url
	a.download = `${(name || 'book').replace(/[\\/:*?"<>|]+/g, '_')} - ${t('ebookreader', 'Highlights')}.md`
	document.body.append(a)
	a.click()
	a.remove()
	setTimeout(() => URL.revokeObjectURL(url), 1000)
}

watch(() => annotations.drawable, (list) => reader?.setAnnotations(list), { deep: true })

const title = computed(() => book.value?.title || book.value?.path.split('/').pop() || '')
const effectiveTheme = computed(() => viewSettings.theme === 'auto' ? (prefersDark.value ? 'dark' : 'light') : viewSettings.theme)
const progressLabel = computed(() => {
	if (pageInfo.value && pageInfo.value.total > 0) {
		return isComic.value
			? t('ebookreader', 'Page {current} of {total}', pageInfo.value)
			: `${Math.round(percentage.value * 100)}% · ${pageInfo.value.current}/${pageInfo.value.total}`
	}
	return `${Math.round(percentage.value * 100)}%`
})
const conflictMessage = computed(() => t('ebookreader', 'Newer position from {device} – jump?', { device: conflict.value?.device || t('ebookreader', 'another device') }))

/**
 * @param raw
 */
function applyServerSettings(raw: ServerReaderSettings | undefined): void {
	if (!raw) {
		return
	}
	serverSettings = raw
	const r = raw as Record<string, unknown>
	if (['auto', 'light', 'dark', 'sepia'].includes(r.theme as string)) {
		viewSettings.theme = r.theme as ViewSettings['theme']
	}
	if (typeof r.fontSize === 'number') {
		// tolerate px values (<= 40) stored by other clients
		viewSettings.fontSize = r.fontSize <= 40 ? Math.round(r.fontSize / 16 * 100) : r.fontSize
	}
	if (typeof r.fontFamily === 'string' && r.fontFamily) {
		viewSettings.fontFamily = r.fontFamily
	}
	if (typeof r.lineHeight === 'number') {
		viewSettings.lineHeight = r.lineHeight
	}
	if (typeof r.margin === 'number') {
		viewSettings.margin = r.margin
	}
	if (r.flow === 'scrolled' || r.flow === 'paginated') {
		viewSettings.flow = r.flow
	}
	if (typeof r.maxColumns === 'number') {
		viewSettings.maxColumns = r.maxColumns
	}
	if (r.comicSpread === 'double' || r.comicSpread === 'single') {
		viewSettings.comicSpread = r.comicSpread
	}
	if (typeof r.comicRtl === 'boolean') {
		viewSettings.comicRtl = r.comicRtl
	}
	if (r.comicZoom === 'fit-width' || r.comicZoom === 'fit-page') {
		viewSettings.comicZoom = r.comicZoom
	}
}

/**
 * @param patch
 */
function onSettingsChange(patch: Partial<ViewSettings>): void {
	Object.assign(viewSettings, patch)
	if (!reader) {
		return
	}
	if ('theme' in patch) {
		reader.setTheme(viewSettings.theme)
	}
	if ('fontSize' in patch || 'fontFamily' in patch || 'lineHeight' in patch) {
		reader.setTypography({ fontSize: viewSettings.fontSize, fontFamily: viewSettings.fontFamily, lineHeight: viewSettings.lineHeight })
	}
	if (['flow', 'maxColumns', 'margin', 'comicSpread', 'comicRtl', 'comicZoom'].some((k) => k in patch)) {
		void reader.setLayout({
			flow: viewSettings.flow,
			maxColumns: viewSettings.maxColumns,
			margin: viewSettings.margin,
			comicSpread: viewSettings.comicSpread,
			comicRtl: viewSettings.comicRtl,
			comicZoom: viewSettings.comicZoom,
		})
		isRtl.value = isComic.value ? viewSettings.comicRtl : isRtl.value
	}
	if (saveTimer) {
		clearTimeout(saveTimer)
	}
	saveTimer = setTimeout(() => {
		putSettings({ reader: { ...(serverSettings ?? {}), ...viewSettings } as ServerReaderSettings })
			.then((s) => {
				serverSettings = s.reader
			})
			.catch((e) => logger.warn('ebookreader: could not save reader settings', e))
	}, 600)
}

/**
 * @param item
 */
async function onTocSelect(item: TocItem): Promise<void> {
	panel.value = null
	await reader?.goTo(item.href)
}

/**
 * @param fraction
 */
async function onSeek(fraction: number): Promise<void> {
	if (!reader) {
		return
	}
	const info = reader.getInfo()
	if (info?.isComic) {
		await reader.goTo({ href: '', locations: { position: Math.max(1, Math.round(fraction * info.pageCount)) } })
	} else {
		await reader.goTo({ href: '', locations: { totalProgression: fraction } })
	}
}

const query = ref('')
const results = ref<SearchGroup[]>([])
const searching = ref(false)
const searched = ref(false)
const searchProgress = ref(0)

/**
 *
 */
async function runSearch(): Promise<void> {
	if (!reader || !query.value.trim()) {
		return
	}
	results.value = []
	searching.value = true
	searched.value = true
	reader.clearSearch()
	try {
		for await (const r of reader.search({ query: query.value.trim() })) {
			if ('subitems' in r) {
				results.value.push(r)
			} else {
				searchProgress.value = r.progress
			}
		}
	} catch (e) {
		logger.warn('ebookreader: search failed', e)
	} finally {
		searching.value = false
	}
}

/**
 * @param cfi
 */
async function jumpToHit(cfi: string): Promise<void> {
	if (window.innerWidth < 900) {
		panel.value = null
	}
	await reader?.goToCfi(cfi)
}

/**
 *
 */
function goLeft(): void {
	void (isRtl.value ? reader?.next() : reader?.prev())
}

/**
 *
 */
function goRight(): void {
	void (isRtl.value ? reader?.prev() : reader?.next())
}

/**
 * @param which
 */
function togglePanel(which: 'toc' | 'search' | 'annotations'): void {
	panel.value = panel.value === which ? null : which
}

/**
 * @param zone
 */
function onTap(zone: 'left' | 'center' | 'right'): void {
	settingsOpen.value = false
	if (zone === 'center' || (viewSettings.flow === 'scrolled' && !isComic.value)) {
		chrome.value = !chrome.value
	} else if (zone === 'left') {
		goLeft()
	} else {
		goRight()
	}
}

/**
 * @param e
 */
function onStageClick(e: MouseEvent): void {
	const rect = (e.currentTarget as HTMLElement).getBoundingClientRect()
	const f = (e.clientX - rect.left) / rect.width
	onTap(f < 0.3 ? 'left' : f > 0.7 ? 'right' : 'center')
}

/**
 * @param key
 * @param e
 */
function handleKey(key: string, e?: KeyboardEvent): void {
	if (e && (e.target as HTMLElement)?.matches?.('input, textarea, select')) {
		return
	}
	switch (key) {
		case 'ArrowLeft':
			goLeft()
			break
		case 'ArrowRight':
			goRight()
			break
		case 'PageDown':
		case ' ':
		case 'ArrowDown':
			if (key === 'ArrowDown' && viewSettings.flow === 'scrolled' && !isComic.value) {
				return
			}
			void reader?.next()
			break
		case 'PageUp':
		case 'ArrowUp':
			if (key === 'ArrowUp' && viewSettings.flow === 'scrolled' && !isComic.value) {
				return
			}
			void reader?.prev()
			break
		case 'Escape':
			if (popup.value) {
				closePopup()
			} else if (settingsOpen.value) {
				settingsOpen.value = false
			} else if (panel.value) {
				panel.value = null
			} else if (!embedded.value) {
				close()
			}
			break
		default:
			return
	}
	e?.preventDefault()
}

/**
 * @param e
 */
function onWindowKey(e: KeyboardEvent): void {
	handleKey(e.key, e)
}

/**
 *
 */
function close(): void {
	void sync?.flush()
	if (window.history.state?.back) {
		router.back()
	} else {
		void router.push('/')
	}
}

/**
 *
 */
function edit(): void {
	void sync?.flush()
	void router.push(`/edit/${props.fileId}`)
}

/**
 *
 */
function jumpToRemote(): void {
	const c = conflict.value
	conflict.value = null
	sync?.discardPending()
	if (c) {
		void reader?.goTo(c.locator as ReaderLocator)
	}
}

/**
 *
 */
function keepLocal(): void {
	conflict.value = null
	void sync?.forceLocal()
}

/**
 * @param e
 */
function fail(e: unknown): void {
	state.value = 'error'
	if (e instanceof ReaderError && e.code === 'drm') {
		errorTitle.value = t('ebookreader', 'This book is DRM protected')
		errorText.value = t('ebookreader', 'Books with DRM can not be opened in the reader.')
	} else if (e instanceof DownloadDeclinedError) {
		errorTitle.value = t('ebookreader', 'Download cancelled')
		errorText.value = t('ebookreader', 'The large file was not downloaded. Installing 7z on the server lets the server read it instead.')
	} else if (e instanceof ReaderError && e.code === 'unsupported') {
		errorTitle.value = t('ebookreader', 'Unsupported format')
		errorText.value = t('ebookreader', 'This file format can not be opened in the reader.')
	} else {
		errorTitle.value = t('ebookreader', 'The book could not be opened')
		errorText.value = t('ebookreader', 'The file seems to be damaged or is not a valid book.')
		logger.error('ebookreader:', e)
	}
}

onMounted(async () => {
	window.addEventListener('keydown', onWindowKey)
	try {
		const fileId = Number(props.fileId)
		const [b, settings] = await Promise.all([getBook(fileId), getSettings().catch(() => null)])
		book.value = b
		if (b.downloadable === false) {
			state.value = 'error'
			errorTitle.value = t('ebookreader', 'View-only share')
			errorText.value = t('ebookreader', 'This book was shared with download disabled, so it can not be read in the app.')
			return
		}
		applyServerSettings(settings?.reader)
		isComic.value = ['cbz', 'cbr', 'cb7', 'cbt'].includes(b.format)
		isRtl.value = isComic.value && viewSettings.comicRtl

		void annotations.load(fileId)
		sync = createProgressSync(fileId, {
			onConflict: (c) => {
				conflict.value = c
			},
		})
		const [source, remote] = await Promise.all([
			loadBookSource(b, abort.signal, largeDownload.ask),
			sync.loadRemote().catch(() => b.progress),
		])
		if (!stage.value) {
			return
		}
		reader = createReader(stage.value, {
			theme: viewSettings.theme,
			typography: { fontSize: viewSettings.fontSize, fontFamily: viewSettings.fontFamily, lineHeight: viewSettings.lineHeight },
			layout: {
				flow: viewSettings.flow,
				maxColumns: viewSettings.maxColumns,
				margin: viewSettings.margin,
				comicSpread: viewSettings.comicSpread,
				comicRtl: viewSettings.comicRtl,
				comicZoom: viewSettings.comicZoom,
			},
			loadLibarchive,
		})
		unsubs.push(
			reader.on('relocate', ({ locator, percentage: p, page }) => {
				percentage.value = p
				pageInfo.value = page ?? null
				currentHref.value = locator.href
				pageTick.value++
				popup.value = null
				sync?.update(locator as Locator, p)
			}),
			reader.on('selection', onSelection),
			reader.on('selection-clear', () => {
				if (popup.value?.mode === 'selection') {
					popup.value = null
				}
			}),
			reader.on('annotation-click', ({ id, rect }) => onAnnotationClick(id, rect)),
			reader.on('tap', ({ zone }) => onTap(zone)),
			reader.on('key', ({ key }) => handleKey(key)),
			reader.on('external-link', ({ url }) => askExternalLink(url)),
		)
		reader.setAnnotations(annotations.drawable)
		await reader.open(source, b.format, (remote?.locator ?? null) as ReaderLocator | null)
		const info = reader.getInfo()
		isComic.value = info?.isComic ?? isComic.value
		isRtl.value = info?.rtl ?? isRtl.value
		toc.value = reader.getToc()
		state.value = 'ready'
		if (needsClientCover(b, source)) {
			// the server could not extract a cover (CBR/CB7 without tool): send the first page
			const r = reader
			uploadClientCover(b.fileId, () => r.getCover())
		}
	} catch (e) {
		if ((e as Error)?.name === 'AbortError') {
			return
		}
		fail(e)
	}
})

onBeforeUnmount(() => {
	abort.abort()
	window.removeEventListener('keydown', onWindowKey)
	unsubs.forEach((u) => u())
	if (saveTimer) {
		clearTimeout(saveTimer)
	}
	sync?.destroy()
	reader?.destroy()
	annotations.reset()
})
</script>

<style scoped>
/* Below the Nextcloud header; toolbar, page and progress bar stack instead of overlapping */
.ebr {
	position: fixed; inset: var(--header-height, 50px) 0 0 0; z-index: 1000; display: flex; flex-direction: column;
	background: var(--ebr-bg); color: var(--ebr-fg);
	--ebr-bg: #fff; --ebr-fg: #1a1a1a;
}
.ebr[data-theme='sepia'] { --ebr-bg: #f4ecd8; --ebr-fg: #5b4636; }
.ebr[data-theme='dark'] { --ebr-bg: #1c1c1e; --ebr-fg: #d8d8d8; }
/* Inside the Files viewer iframe: cover the embedded page completely, including its header */
.ebr--embedded { inset: 0; z-index: 10000; }
.ebr__stage { position: relative; flex: 1 1 auto; min-height: 0; }
.ebr__bar {
	position: relative; flex: 0 0 auto; z-index: 2; display: flex; align-items: center; gap: 4px;
	padding: 4px 8px; background: var(--ebr-bg); color: var(--ebr-fg);
}
.ebr__bar--top { border-bottom: 1px solid color-mix(in srgb, var(--ebr-fg) 15%, transparent); }
.ebr__bar--bottom { padding: 8px 16px; border-top: 1px solid color-mix(in srgb, var(--ebr-fg) 15%, transparent); }
.ebr__title { flex: 1 1 auto; margin: 0; font-size: 1rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: center; }
.ebr__seek { flex: 1 1 auto; }
.ebr__pct { min-width: 90px; text-align: end; font-variant-numeric: tabular-nums; }
.ebr__overlay { position: absolute; inset: 0; z-index: 3; display: flex; align-items: center; justify-content: center; background: var(--ebr-bg); }
.ebr__panel {
	position: absolute; top: 52px; bottom: 0; inset-inline-start: 0; z-index: 4; width: min(380px, 100%);
	display: flex; flex-direction: column; background: var(--color-main-background); color: var(--color-main-text);
	box-shadow: 2px 0 12px rgba(0, 0, 0, .3);
}
.ebr__panel-head { display: flex; align-items: center; justify-content: space-between; padding: 8px 8px 8px 16px; }
.ebr__tabs { display: flex; gap: 4px; min-width: 0; flex-wrap: wrap; }
.ebr__tab {
	padding: 6px 10px; cursor: pointer; color: inherit; background: none; border: 0;
	border-bottom: 2px solid transparent; font-weight: 600; opacity: .7;
}
.ebr__tab--active { opacity: 1; border-bottom-color: var(--color-primary-element); }
.ebr__panel-body { flex: 1 1 auto; overflow: auto; padding: 0 8px 16px; }
.ebr__muted { opacity: .7; padding: 12px; }
.ebr__search { display: flex; gap: 8px; padding: 8px 4px; }
.ebr__search input { flex: 1 1 auto; }
.ebr__group-label { font-weight: 600; padding: 8px 4px 4px; }
.ebr__hit { display: block; width: 100%; text-align: start; background: none; border: 0; padding: 6px 4px; cursor: pointer; color: inherit; border-radius: 8px; }
.ebr__hit:hover { background: var(--color-background-hover); }
</style>
