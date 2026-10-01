<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppNavigation :aria-label="t('ebookreader', 'E-book library')">
		<template #list>
			<NcAppNavigationCaption :name="t('ebookreader', 'Library')" />
			<NcAppNavigationItem
				v-for="item in mainItems"
				:key="item.key"
				:name="item.name"
				:active="item.active"
				@click="item.action">
				<template #icon>
					<NcIconSvgWrapper :path="item.icon" />
				</template>
			</NcAppNavigationItem>

			<ShelvesNav />

			<NcAppNavigationCaption :name="t('ebookreader', 'Filter')" />
			<NcAppNavigationItem
				v-for="group in facetGroups"
				:key="group.key"
				:name="group.name"
				:allowCollapse="true"
				:open="openGroups[group.key]"
				@click="openGroups[group.key] = !openGroups[group.key]"
				@update:open="(v: boolean) => (openGroups[group.key] = v)">
				<template #icon>
					<NcIconSvgWrapper :path="group.icon" />
				</template>
				<TagTreeNav
					v-if="group.tree"
					:type="group.filter"
					:nodes="group.nodes" />
				<NcAppNavigationItem
					v-for="entry in (group.tree ? [] : group.entries)"
					:key="entry.name"
					:name="entry.name"
					:active="store.termState({ type: group.filter, name: entry.name }) === 'include'"
					:class="{ 'library-nav__excluded': store.termState({ type: group.filter, name: entry.name }) === 'exclude' }"
					@click="store.cycleTerm({ type: group.filter, name: entry.name })">
					<template v-if="store.termState({ type: group.filter, name: entry.name }) === 'exclude'" #icon>
						<NcIconSvgWrapper :path="mdiMinusCircleOutline" />
					</template>
					<template #counter>
						<NcCounterBubble :count="entry.count" />
					</template>
					<template #actions>
						<NcActionButton @click="store.setTermState({ type: group.filter, name: entry.name }, 'include')">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPlusCircleOutline" />
							</template>
							{{ t('ebookreader', 'Include') }}
						</NcActionButton>
						<NcActionButton @click="store.setTermState({ type: group.filter, name: entry.name }, 'exclude')">
							<template #icon>
								<NcIconSvgWrapper :path="mdiMinusCircleOutline" />
							</template>
							{{ t('ebookreader', 'Exclude') }}
						</NcActionButton>
						<NcActionButton @click="store.onlyTerm({ type: group.filter, name: entry.name })">
							<template #icon>
								<NcIconSvgWrapper :path="mdiTarget" />
							</template>
							{{ t('ebookreader', 'Only this') }}
						</NcActionButton>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					v-if="group.entries.length === 0"
					:name="t('ebookreader', 'Nothing here yet')"
					class="library-nav__empty" />
			</NcAppNavigationItem>
		</template>

		<template #footer>
			<div class="library-nav__footer">
				<NcButton wide variant="primary" @click="pickFiles">
					<template #icon>
						<NcIconSvgWrapper :path="mdiUpload" />
					</template>
					{{ t('ebookreader', 'Upload') }}
				</NcButton>
				<NcButton wide :disabled="scanning" @click="onScan">
					<template #icon>
						<NcLoadingIcon v-if="scanning" :size="20" />
						<NcIconSvgWrapper v-else :path="mdiRefresh" />
					</template>
					{{ t('ebookreader', 'Scan library') }}
				</NcButton>
				<NcButton wide variant="tertiary" @click="showSettings = true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiCog" />
					</template>
					{{ t('ebookreader', 'Settings') }}
				</NcButton>
			</div>
		</template>
	</NcAppNavigation>

	<NcAppContent :pageHeading="t('ebookreader', 'E-book library')">
		<div
			class="library"
			@dragenter="onDragEnter"
			@dragover="onDragOver"
			@dragleave="onDragLeave"
			@drop="onDrop">
			<div v-if="dragging" class="library__drop" aria-hidden="true">
				<NcIconSvgWrapper :path="mdiUpload" :size="48" />
				<span>{{ t('ebookreader', 'Drop to add to your library') }}</span>
			</div>
			<input
				ref="fileInput"
				class="library__file-input"
				type="file"
				multiple
				:accept="acceptExtensions"
				tabindex="-1"
				aria-hidden="true"
				@change="onFilesPicked">

			<div class="library__toolbar">
				<NcTextField
					class="library__search"
					:modelValue="store.filters.search"
					:label="t('ebookreader', 'Search books')"
					trailingButtonIcon="close"
					:showTrailingButton="store.filters.search !== ''"
					:trailingButtonLabel="t('ebookreader', 'Clear search')"
					@update:modelValue="(v: string | number) => store.setSearch(String(v))"
					@trailingButtonClick="store.setSearch('')" />

				<NcActions
					class="library__sort"
					:menuName="currentSort ? currentSort.label : t('ebookreader', 'Sort')"
					:forceName="true"
					:aria-label="t('ebookreader', 'Sort by')"
					variant="tertiary">
					<template #icon>
						<NcIconSvgWrapper :path="mdiSort" />
					</template>
					<NcActionButton
						v-for="opt in sortOptions"
						:key="opt.id"
						type="radio"
						:modelValue="store.sort === opt.id"
						closeAfterClick
						@click="store.setSort(opt.id)">
						{{ opt.label }}
					</NcActionButton>
				</NcActions>

				<NcButton
					:aria-label="store.order === 'asc' ? t('ebookreader', 'Ascending') : t('ebookreader', 'Descending')"
					:title="store.order === 'asc' ? t('ebookreader', 'Ascending') : t('ebookreader', 'Descending')"
					variant="tertiary"
					@click="store.toggleOrder()">
					<template #icon>
						<NcIconSvgWrapper :path="store.order === 'asc' ? mdiSortAscending : mdiSortDescending" />
					</template>
				</NcButton>

				<NcButton
					:aria-label="viewMode === 'grid' ? t('ebookreader', 'Switch to list view') : t('ebookreader', 'Switch to grid view')"
					:title="viewMode === 'grid' ? t('ebookreader', 'Switch to list view') : t('ebookreader', 'Switch to grid view')"
					variant="tertiary"
					@click="setViewMode(viewMode === 'grid' ? 'list' : 'grid')">
					<template #icon>
						<NcIconSvgWrapper :path="viewMode === 'grid' ? mdiViewList : mdiViewGrid" />
					</template>
				</NcButton>

				<NcButton
					:pressed="store.groupSeries"
					:aria-label="t('ebookreader', 'Group series')"
					:title="t('ebookreader', 'Group series')"
					variant="tertiary"
					@update:pressed="(v: boolean) => store.setGroupSeries(v)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiBookMultipleOutline" />
					</template>
				</NcButton>

				<NcButton
					:pressed="store.selectMode"
					:aria-label="t('ebookreader', 'Select multiple books')"
					:title="t('ebookreader', 'Select multiple books')"
					variant="tertiary"
					@update:pressed="(v: boolean) => store.setSelectMode(v)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiCheckboxMultipleMarkedOutline" />
					</template>
				</NcButton>
			</div>

			<div v-if="store.selectMode" class="library__selection">
				<span>{{ n('ebookreader', '%n book selected', '%n books selected', store.selectedIds.length) }}</span>
				<NcButton variant="tertiary" @click="store.selectAllLoaded()">
					{{ t('ebookreader', 'Select all') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="store.selectedIds.length === 0" @click="store.clearSelection()">
					{{ t('ebookreader', 'Clear selection') }}
				</NcButton>
				<NcButton :disabled="store.selectedIds.length === 0" @click="shelfIds = store.selectedIds">
					<template #icon>
						<NcIconSvgWrapper :path="mdiBookPlusOutline" />
					</template>
					{{ t('ebookreader', 'Add to shelf…') }}
				</NcButton>
				<NcButton
					v-if="store.activeManualShelfId !== null"
					:disabled="store.selectedIds.length === 0"
					@click="removeFromShelf">
					<template #icon>
						<NcIconSvgWrapper :path="mdiBookMinusOutline" />
					</template>
					{{ t('ebookreader', 'Remove from shelf') }}
				</NcButton>
				<NcButton :disabled="store.selectedIds.length === 0" @click="organizeIds = store.selectedIds">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFolderMoveOutline" />
					</template>
					{{ t('ebookreader', 'Rename / organise…') }}
				</NcButton>
				<NcButton variant="primary" :disabled="store.selectedIds.length === 0" @click="showBulk = true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiTagMultipleOutline" />
					</template>
					{{ t('ebookreader', 'Edit selected…') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="store.selectedIds.length === 0" @click="askDelete(store.selectedIds)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDeleteOutline" />
					</template>
					{{ t('ebookreader', 'Delete…') }}
				</NcButton>
			</div>

			<div v-if="store.drillSeries !== null" class="library__heading">
				<NcButton variant="tertiary" @click="store.closeSeries()">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArrowLeft" />
					</template>
					{{ t('ebookreader', 'Back to series') }}
				</NcButton>
				<h2>{{ store.drillSeries }}</h2>
			</div>
			<div v-else-if="shelfHeading" class="library__heading">
				<NcIconSvgWrapper :path="store.smartShelfId !== null ? mdiFilterVariant : mdiBookshelf" />
				<h2>{{ shelfHeading }}</h2>
			</div>

			<FilterBar />

			<UploadPanel />

			<ActiveTasksBanner @finished="onTasksFinished" />

			<NcNoteCard v-if="store.error" type="error">
				{{ store.error }}
			</NcNoteCard>

			<div v-if="!store.loaded && store.loading" class="library__center">
				<NcLoadingIcon :size="44" />
			</div>

			<NcEmptyContent
				v-else-if="store.loaded && store.isEmpty && !store.loading && !store.hasFilters && store.drillSeries === null"
				:name="t('ebookreader', 'Your library is empty')"
				:description="emptyDescription">
				<template #icon>
					<NcIconSvgWrapper :path="mdiBookshelf" :size="64" />
				</template>
				<template #action>
					<NcButton variant="primary" @click="pickFiles">
						<template #icon>
							<NcIconSvgWrapper :path="mdiUpload" />
						</template>
						{{ t('ebookreader', 'Upload') }}
					</NcButton>
					<NcButton @click="showSettings = true">
						{{ t('ebookreader', 'Open settings') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<NcEmptyContent
				v-else-if="store.loaded && store.isEmpty && !store.loading"
				:name="t('ebookreader', 'No matching books')"
				:description="t('ebookreader', 'Try a different search or remove some filters.')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiBookSearchOutline" :size="64" />
				</template>
				<template #action>
					<NcButton @click="store.resetFilters()">
						{{ t('ebookreader', 'Clear filters') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<template v-else-if="!store.isEmpty">
				<ContinueReading
					v-if="showContinue"
					:books="store.recent"
					:activeFileId="store.activeFileId"
					@click="onBookClick" />

				<SeriesGrid
					v-if="store.seriesMode && store.seriesList.length"
					:series="store.seriesList"
					@click="(s: SeriesEntry) => store.openSeries(s.name)" />
				<h3 v-if="store.seriesMode && store.seriesList.length && store.books.length" class="library__subheading">
					{{ t('ebookreader', 'Books without a series') }}
				</h3>

				<BookGrid
					v-if="viewMode === 'grid'"
					:books="store.books"
					:activeFileId="store.activeFileId"
					:selection="store.selection"
					:selectMode="store.selectMode"
					@click="onBookClick"
					@filter="onDetailsFilter" />
				<BookList
					v-else
					:books="store.books"
					:activeFileId="store.activeFileId"
					:selection="store.selection"
					:selectMode="store.selectMode"
					@click="onBookClick" />

				<div ref="sentinel" class="library__sentinel">
					<NcLoadingIcon v-if="store.loadingMore || store.loading" :size="28" />
				</div>
			</template>
		</div>
	</NcAppContent>

	<BookDetails
		v-if="store.activeBook"
		:book="store.activeBook"
		@close="store.setActive(null)"
		@filter="onDetailsFilter"
		@organize="(id: number) => (organizeIds = [id])"
		@delete="(id: number) => askDelete([id])"
		@converted="onConverted" />

	<BulkEditDialog v-if="showBulk" @close="showBulk = false" />
	<AddToShelfDialog
		v-if="shelfIds.length"
		:fileIds="shelfIds"
		@close="shelfIds = []"
		@done="store.setSelectMode(false)" />
	<DeleteBooksDialog
		v-if="deleteList.length"
		:books="deleteList"
		@close="deleteList = []"
		@deleted="onDeleted" />
	<OrganizeDialog
		v-if="organizeIds.length"
		:fileIds="organizeIds"
		@close="organizeIds = []"
		@done="store.setSelectMode(false)" />
	<SettingsDialog v-if="showSettings" @close="showSettings = false" @saved="onSettingsSaved" />
</template>

<script setup lang="ts">
import type { Book, FacetEntry, FilterTerm, SeriesEntry, SortKey } from '../types.ts'

import {
	mdiAccountOutline,
	mdiArrowLeft,
	mdiBookCheckOutline,
	mdiBookClockOutline,
	mdiBookMinusOutline,
	mdiBookMultipleOutline,
	mdiBookOpenPageVariant,
	mdiBookOutline,
	mdiBookPlusOutline,
	mdiBookSearchOutline,
	mdiBookshelf,
	mdiCheckboxMultipleMarkedOutline,
	mdiCog,
	mdiDeleteOutline,
	mdiDramaMasks,
	mdiFileOutline,
	mdiFilterVariant,
	mdiFolderMoveOutline,
	mdiLibraryShelves,
	mdiMinusCircleOutline,
	mdiPlusCircleOutline,
	mdiRefresh,
	mdiSort,
	mdiSortAscending,
	mdiSortDescending,
	mdiTagMultipleOutline,
	mdiTagOutline,
	mdiTarget,
	mdiUpload,
	mdiViewGrid,
	mdiViewList,
} from '@mdi/js'
import { showError, showInfo, showSuccess, showWarning } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActiveTasksBanner from '../components/common/ActiveTasksBanner.vue'
import AddToShelfDialog from '../components/library/AddToShelfDialog.vue'
import BookDetails from '../components/library/BookDetails.vue'
import BookGrid from '../components/library/BookGrid.vue'
import BookList from '../components/library/BookList.vue'
import BulkEditDialog from '../components/library/BulkEditDialog.vue'
import ContinueReading from '../components/library/ContinueReading.vue'
import DeleteBooksDialog from '../components/library/DeleteBooksDialog.vue'
import FilterBar from '../components/library/FilterBar.vue'
import SeriesGrid from '../components/library/SeriesGrid.vue'
import SettingsDialog from '../components/library/SettingsDialog.vue'
import ShelvesNav from '../components/library/ShelvesNav.vue'
import TagTreeNav from '../components/library/TagTreeNav.vue'
import UploadPanel from '../components/library/UploadPanel.vue'
import OrganizeDialog from '../components/organize/OrganizeDialog.vue'
import { scan } from '../services/api.ts'
import { buildTree } from '../services/hierarchy.ts'
import { ALLOWED_EXTENSIONS } from '../services/upload.ts'
import { queryToState, useLibraryStore } from '../stores/library.ts'
import { useShelvesStore } from '../stores/shelves.ts'
import { useUploadStore } from '../stores/upload.ts'

const VIEW_KEY = 'ebookreader.libraryView'

const store = useLibraryStore()
const shelves = useShelvesStore()
const uploadStore = useUploadStore()

const route = useRoute()
const router = useRouter()

const showBulk = ref(false)
/** Books in the "add to shelf" dialog; empty = closed */
const shelfIds = ref<number[]>([])
const organizeIds = ref<number[]>([])
/** Books in the delete confirmation dialog; empty = closed */
const deleteList = ref<Book[]>([])

/**
 * @param ids
 */
function askDelete(ids: number[]): void {
	const byId = new Map([...store.books, ...store.recent].map((b) => [b.fileId, b]))
	deleteList.value = ids.map((id) => byId.get(id)).filter((b): b is Book => b !== undefined)
}

/**
 * @param ids
 */
function onDeleted(ids: number[]): void {
	store.removeBooks(ids)
	if (store.selectMode && store.selectedIds.length === 0) {
		store.setSelectMode(false)
	}
}
const showSettings = ref(false)
const scanning = ref(false)
const sentinel = ref<HTMLElement | null>(null)

// ---- view mode (localStorage may be unavailable) -----------------------

/**
 *
 */
function readViewMode(): 'grid' | 'list' {
	try {
		return localStorage.getItem(VIEW_KEY) === 'list' ? 'list' : 'grid'
	} catch {
		return 'grid'
	}
}

const viewMode = ref<'grid' | 'list'>(readViewMode())

/**
 * @param mode
 */
function setViewMode(mode: 'grid' | 'list'): void {
	viewMode.value = mode
	try {
		localStorage.setItem(VIEW_KEY, mode)
	} catch {
		// ignore
	}
}

// ---- sort -------------------------------------------------------------

const sortOptions = computed<{ id: SortKey, label: string }[]>(() => [
	...(store.activeManualShelfId !== null ? [{ id: 'shelf' as SortKey, label: t('ebookreader', 'Shelf order') }] : []),
	{ id: 'title', label: t('ebookreader', 'Title') },
	{ id: 'author', label: t('ebookreader', 'Author') },
	{ id: 'series', label: t('ebookreader', 'Series') },
	{ id: 'rating', label: t('ebookreader', 'Rating') },
	{ id: 'added', label: t('ebookreader', 'Recently added') },
	{ id: 'read', label: t('ebookreader', 'Recently read') },
])
const currentSort = computed(() => sortOptions.value.find((o) => o.id === store.sort))

// ---- navigation -------------------------------------------------------

const mainItems = computed(() => {
	const f = store.filters
	const onlyStatus = (st: string | null) => f.status === st
		&& f.include.length === 0 && f.exclude.length === 0 && !f.search
	return [
		{ key: 'all', name: t('ebookreader', 'All books'), icon: mdiLibraryShelves, active: !store.hasFilters && store.drillSeries === null, action: () => store.resetFilters() },
		{ key: 'reading', name: t('ebookreader', 'Continue reading'), icon: mdiBookClockOutline, active: onlyStatus('reading'), action: () => store.setStatus(f.status === 'reading' ? null : 'reading') },
		{ key: 'unread', name: t('ebookreader', 'Unread'), icon: mdiBookOutline, active: onlyStatus('unread'), action: () => store.setStatus(f.status === 'unread' ? null : 'unread') },
		{ key: 'finished', name: t('ebookreader', 'Finished'), icon: mdiBookCheckOutline, active: onlyStatus('finished'), action: () => store.setStatus(f.status === 'finished' ? null : 'finished') },
	]
})

const openGroups = reactive<Record<string, boolean>>({
	genres: false,
	tags: false,
	authors: false,
	series: false,
	formats: false,
})

const facetGroups = computed(() => {
	const group = (key: string, filter: 'genre' | 'tag' | 'author' | 'series' | 'format', name: string, icon: string, entries: FacetEntry[], tree = false) => ({
		key,
		filter,
		name,
		icon,
		entries,
		tree,
		nodes: tree ? buildTree(entries) : [],
	})
	return [
		group('genres', 'genre', t('ebookreader', 'Genres'), mdiDramaMasks, store.facets.genres, true),
		group('tags', 'tag', t('ebookreader', 'Tags'), mdiTagOutline, store.facets.tags, true),
		group('authors', 'author', t('ebookreader', 'Authors'), mdiAccountOutline, store.facets.authors),
		group('series', 'series', t('ebookreader', 'Series'), mdiBookOpenPageVariant, store.facets.series),
		group('formats', 'format', t('ebookreader', 'Formats'), mdiFileOutline, store.facets.formats),
	]
})

/** Heading of a shown shelf (manual or smart) */
const shelfHeading = computed(() => {
	if (store.smartShelfId !== null) {
		return store.smartShelf?.name ?? null
	}
	return store.activeManualShelfId !== null ? shelves.byId(store.activeManualShelfId)?.name ?? null : null
})

const acceptExtensions = ALLOWED_EXTENSIONS.map((e) => '.' + e).join(',')

const showContinue = computed(() => !store.hasFilters && store.recent.length > 0)

const emptyDescription = computed(() => t('ebookreader', 'Books are found in your library folders (default: /Books). Put e-books there or choose other folders in the settings, then scan the library.'))

// ---- actions ----------------------------------------------------------

/**
 * @param book
 */
function onBookClick(book: Book): void {
	if (store.selectMode) {
		store.toggleSelected(book.fileId)
	} else {
		store.setActive(book.fileId)
	}
}

/**
 * Chip click in the grid or the details: add as include filter; the sidebar
 * closes on narrow screens so the result is visible.
 *
 * @param term
 */
function onDetailsFilter(term: FilterTerm): void {
	if (store.termState(term) !== 'include') {
		store.setTermState(term, 'include')
	}
	if (window.innerWidth < 1024) {
		store.setActive(null)
	}
}

/**
 * "Remove from shelf" in the selection toolbar of a manual shelf.
 */
async function removeFromShelf(): Promise<void> {
	const id = store.activeManualShelfId
	if (id === null) {
		return
	}
	try {
		const removed = await shelves.removeBooks(id, store.selectedIds)
		showSuccess(n('ebookreader', '%n book removed from the shelf', '%n books removed from the shelf', removed))
		store.setSelectMode(false)
		await store.reload()
	} catch {
		showError(t('ebookreader', 'Could not remove the books from the shelf'))
	}
}

// ---- upload -------------------------------------------------------------

const fileInput = ref<HTMLInputElement | null>(null)
const dragging = ref(false)
let dragDepth = 0

/**
 * @param e
 */
function hasFiles(e: DragEvent): boolean {
	return Array.from(e.dataTransfer?.types ?? []).includes('Files')
}

/**
 * @param e
 */
function onDragEnter(e: DragEvent): void {
	if (hasFiles(e)) {
		e.preventDefault()
		dragDepth++
		dragging.value = true
	}
}

/**
 * @param e
 */
function onDragOver(e: DragEvent): void {
	if (hasFiles(e)) {
		e.preventDefault()
	}
}

/**
 * @param e
 */
function onDragLeave(e: DragEvent): void {
	if (hasFiles(e)) {
		dragDepth = Math.max(0, dragDepth - 1)
		dragging.value = dragDepth > 0
	}
}

/**
 * @param e
 */
function onDrop(e: DragEvent): void {
	if (!hasFiles(e)) {
		return
	}
	e.preventDefault()
	dragDepth = 0
	dragging.value = false
	void startUpload(Array.from(e.dataTransfer?.files ?? []))
}

/**
 *
 */
function pickFiles(): void {
	fileInput.value?.click()
}

/**
 * @param e
 */
function onFilesPicked(e: Event): void {
	const input = e.target as HTMLInputElement
	const files = Array.from(input.files ?? [])
	input.value = ''
	void startUpload(files)
}

/**
 * @param files
 */
async function startUpload(files: File[]): Promise<void> {
	if (files.length === 0) {
		return
	}
	const res = await uploadStore.start(files)
	if (res.rejected.length > 0) {
		showWarning(n('ebookreader', '%n file skipped: only e-book formats ({formats}) can be uploaded', '%n files skipped: only e-book formats ({formats}) can be uploaded', res.rejected.length, { formats: ALLOWED_EXTENSIONS.join(', ') }))
	}
	if (res.uploaded > 0) {
		showSuccess(n('ebookreader', '%n book uploaded', '%n books uploaded', res.uploaded))
		void shelves.load()
	}
	if (res.large) {
		showInfo(t('ebookreader', 'Large files are being indexed in the background'))
	}
	if (res.failed > 0) {
		showError(n('ebookreader', '%n upload failed', '%n uploads failed', res.failed))
	}
}

/** Background tasks finished (edit/convert): the library content changed. */
async function onTasksFinished(): Promise<void> {
	await Promise.all([store.reload(), store.loadFacets()])
}

/**
 * A converted book is a new file: reload and show its details.
 *
 * @param fileId
 */
async function onConverted(fileId: number): Promise<void> {
	await Promise.all([store.reload(), store.loadFacets()])
	store.setActive(fileId)
}

// ---- URL <-> filter state ---------------------------------------------

/**
 * @param q
 */
function queryKey(q: Record<string, unknown>): string {
	return JSON.stringify(Object.keys(q).sort().map((k) => [k, q[k]]))
}

watch(() => store.urlQuery, (q) => {
	if (queryKey(q) !== queryKey(route.query)) {
		void router.replace({ query: q })
	}
}, { deep: true })

// Back/forward or a pasted link changed the URL
watch(() => route.query, (q) => {
	if (queryKey(q) !== queryKey(store.urlQuery)) {
		store.applyState(queryToState(q))
		void store.reload()
	}
})

/**
 *
 */
async function onScan(): Promise<void> {
	scanning.value = true
	try {
		const res = await scan()
		if (res.found === 0) {
			showWarning(t('ebookreader', 'No e-books found in your library folders. Check the folders in the settings.'))
		} else if (res.queued > 0) {
			showSuccess(n('ebookreader', '%n book indexed, the rest continues in the background', '%n books indexed, the rest continues in the background', res.indexed))
		} else {
			showSuccess(n('ebookreader', 'Library is up to date: %n book', 'Library is up to date: %n books', res.found))
		}
		await store.init()
	} catch {
		showError(t('ebookreader', 'Could not scan the library'))
	} finally {
		scanning.value = false
	}
}

/**
 *
 */
function onSettingsSaved(): void {
	// Library folders may have changed: scan right away (cheap when nothing changed), then reload
	void onScan()
}

// ---- infinite scroll --------------------------------------------------

let observer: IntersectionObserver | null = null

/**
 *
 */
function observeSentinel(): void {
	observer?.disconnect()
	if (observer && sentinel.value) {
		observer.observe(sentinel.value)
	}
}

onMounted(() => {
	if (Object.keys(route.query).length > 0) {
		store.applyState(queryToState(route.query))
	} else if (Object.keys(store.urlQuery).length > 0) {
		void router.replace({ query: store.urlQuery })
	}
	void store.init()
	void shelves.load()
	if (typeof IntersectionObserver !== 'undefined') {
		observer = new IntersectionObserver((entries) => {
			if (entries.some((e) => e.isIntersecting)) {
				void store.loadMore()
			}
		}, { rootMargin: '400px' })
		observeSentinel()
	}
})

// Re-arm the observer after each page so a still-visible sentinel triggers again.
watch(() => [store.books.length, sentinel.value], async () => {
	await nextTick()
	observeSentinel()
})

onBeforeUnmount(() => {
	observer?.disconnect()
	observer = null
})
</script>

<style scoped lang="scss">
.library {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 8px 8px 24px 52px; // room for the navigation toggle
	min-height: 100%;
	box-sizing: border-box;

	@media (min-width: 1024px) {
		padding-inline-start: 16px;
		padding-inline-end: 16px;
	}

	&__toolbar {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 4px 8px;
		min-height: var(--default-clickable-area);
		// keep clear of the app navigation toggle that NcAppContent places in the top-left corner
		padding-inline-start: calc(var(--default-clickable-area) + var(--app-navigation-padding, 8px));

		:deep(.input-field) {
			margin-block: 0;
		}
	}

	&__search {
		flex: 1 1 200px;
		min-width: 160px;
	}

	&__sort {
		flex: 0 0 auto;
	}

	&__selection {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
	}

	&__center {
		display: flex;
		justify-content: center;
		padding: 48px 0;
	}

	&__drop {
		position: fixed;
		inset: 0;
		z-index: 2000;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 12px;
		margin: 12px;
		border: 3px dashed var(--color-primary-element);
		border-radius: var(--border-radius-large);
		background: color-mix(in srgb, var(--color-main-background) 85%, transparent);
		font-size: 1.3em;
		pointer-events: none;
	}

	&__file-input {
		display: none;
	}

	&__heading {
		display: flex;
		align-items: center;
		gap: 8px;

		h2 {
			margin: 0;
			font-size: 1.4em;
		}
	}

	&__subheading {
		margin: 8px 0 0;
		color: var(--color-text-maxcontrast);
		font-size: 1em;
	}

	&__sentinel {
		display: flex;
		justify-content: center;
		min-height: 32px;
	}
}

.library-nav {
	&__excluded :deep(.app-navigation-entry__name) {
		text-decoration: line-through;
		opacity: 0.7;
	}

	&__footer {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 8px;
	}
}
</style>
