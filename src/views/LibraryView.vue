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

			<NcAppNavigationCaption :name="t('ebookreader', 'Filter')" />
			<NcAppNavigationItem
				v-for="group in facetGroups"
				:key="group.key"
				:name="group.name"
				:allowCollapse="true"
				:open="openGroups[group.key]"
				@update:open="(v: boolean) => (openGroups[group.key] = v)">
				<template #icon>
					<NcIconSvgWrapper :path="group.icon" />
				</template>
				<NcAppNavigationItem
					v-for="entry in group.entries"
					:key="entry.name"
					:name="entry.name"
					:active="store.filters[group.filter] === entry.name"
					@click="store.toggleFilter(group.filter, entry.name as never)">
					<template #counter>
						<NcCounterBubble :count="entry.count" />
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
		<div class="library">
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

				<NcSelect
					class="library__sort"
					:modelValue="currentSort"
					:options="sortOptions"
					:inputLabel="t('ebookreader', 'Sort by')"
					:clearable="false"
					:searchable="false"
					label="label"
					@update:modelValue="(o: { id: SortKey }) => o && store.setSort(o.id)" />

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
				<NcButton variant="primary" :disabled="store.selectedIds.length === 0" @click="showBulk = true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiTagMultipleOutline" />
					</template>
					{{ t('ebookreader', 'Edit genres and tags') }}
				</NcButton>
			</div>

			<div v-if="activeFilterChips.length" class="library__filters">
				<button
					v-for="chip in activeFilterChips"
					:key="chip.key"
					type="button"
					class="library__filter-chip"
					@click="chip.clear()">
					{{ chip.label }}
					<NcIconSvgWrapper :path="mdiClose" :size="16" />
				</button>
			</div>

			<NcNoteCard v-if="store.error" type="error">
				{{ store.error }}
			</NcNoteCard>

			<div v-if="!store.loaded && store.loading" class="library__center">
				<NcLoadingIcon :size="44" />
			</div>

			<NcEmptyContent
				v-else-if="store.loaded && store.books.length === 0 && !store.loading && !store.hasFilters"
				:name="t('ebookreader', 'Your library is empty')"
				:description="emptyDescription">
				<template #icon>
					<NcIconSvgWrapper :path="mdiBookshelf" :size="64" />
				</template>
				<template #action>
					<NcButton variant="primary" @click="showSettings = true">
						{{ t('ebookreader', 'Open settings') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<NcEmptyContent
				v-else-if="store.loaded && store.books.length === 0 && !store.loading"
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

			<template v-else-if="store.books.length">
				<ContinueReading
					v-if="showContinue"
					:books="store.recent"
					:activeFileId="store.activeFileId"
					@click="onBookClick" />

				<BookGrid
					v-if="viewMode === 'grid'"
					:books="store.books"
					:activeFileId="store.activeFileId"
					:selection="store.selection"
					:selectMode="store.selectMode"
					@click="onBookClick" />
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
		:key="store.activeBook.fileId"
		:book="store.activeBook"
		@close="store.setActive(null)"
		@filter="onDetailsFilter" />

	<BulkTagDialog v-if="showBulk" @close="showBulk = false" />
	<SettingsDialog v-if="showSettings" @close="showSettings = false" @saved="onSettingsSaved" />
</template>

<script setup lang="ts">
import type { FilterKey } from '../stores/library.ts'
import type { Book, SortKey } from '../types.ts'

import {
	mdiAccountOutline,
	mdiBookCheckOutline,
	mdiBookClockOutline,
	mdiBookOpenPageVariant,
	mdiBookOutline,
	mdiBookSearchOutline,
	mdiBookshelf,
	mdiCheckboxMultipleMarkedOutline,
	mdiClose,
	mdiCog,
	mdiDramaMasks,
	mdiFileOutline,
	mdiLibraryShelves,
	mdiRefresh,
	mdiSortAscending,
	mdiSortDescending,
	mdiTagMultipleOutline,
	mdiTagOutline,
	mdiViewGrid,
	mdiViewList,
} from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
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
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import BookDetails from '../components/library/BookDetails.vue'
import BookGrid from '../components/library/BookGrid.vue'
import BookList from '../components/library/BookList.vue'
import BulkTagDialog from '../components/library/BulkTagDialog.vue'
import ContinueReading from '../components/library/ContinueReading.vue'
import SettingsDialog from '../components/library/SettingsDialog.vue'
import { scan } from '../services/api.ts'
import { useLibraryStore } from '../stores/library.ts'

const VIEW_KEY = 'ebookreader.libraryView'

const store = useLibraryStore()

const showBulk = ref(false)
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
	const onlyStatus = (s: string | null) => f.status === s
		&& !f.format && !f.genre && !f.tag && !f.author && !f.series && !f.search
	return [
		{ key: 'all', name: t('ebookreader', 'All books'), icon: mdiLibraryShelves, active: !store.hasFilters, action: () => store.resetFilters() },
		{ key: 'reading', name: t('ebookreader', 'Continue reading'), icon: mdiBookClockOutline, active: onlyStatus('reading'), action: () => store.setFilter('status', f.status === 'reading' ? null : 'reading') },
		{ key: 'unread', name: t('ebookreader', 'Unread'), icon: mdiBookOutline, active: onlyStatus('unread'), action: () => store.setFilter('status', f.status === 'unread' ? null : 'unread') },
		{ key: 'finished', name: t('ebookreader', 'Finished'), icon: mdiBookCheckOutline, active: onlyStatus('finished'), action: () => store.setFilter('status', f.status === 'finished' ? null : 'finished') },
	]
})

const openGroups = reactive<Record<string, boolean>>({
	genres: false,
	tags: false,
	authors: false,
	series: false,
	formats: false,
})

const facetGroups = computed(() => [
	{ key: 'genres', filter: 'genre' as const, name: t('ebookreader', 'Genres'), icon: mdiDramaMasks, entries: store.facets.genres },
	{ key: 'tags', filter: 'tag' as const, name: t('ebookreader', 'Tags'), icon: mdiTagOutline, entries: store.facets.tags },
	{ key: 'authors', filter: 'author' as const, name: t('ebookreader', 'Authors'), icon: mdiAccountOutline, entries: store.facets.authors },
	{ key: 'series', filter: 'series' as const, name: t('ebookreader', 'Series'), icon: mdiBookOpenPageVariant, entries: store.facets.series },
	{ key: 'formats', filter: 'format' as const, name: t('ebookreader', 'Formats'), icon: mdiFileOutline, entries: store.facets.formats },
])

const statusLabels = computed<Record<string, string>>(() => ({
	unread: t('ebookreader', 'Unread'),
	reading: t('ebookreader', 'Reading'),
	finished: t('ebookreader', 'Finished'),
}))

const activeFilterChips = computed(() => {
	const f = store.filters
	const chips: { key: string, label: string, clear: () => void }[] = []
	const add = (key: FilterKey, label: string, value: string | null) => {
		if (value) {
			chips.push({ key, label: `${label}: ${key === 'status' ? statusLabels.value[value] : value}`, clear: () => store.setFilter(key, null as never) })
		}
	}
	add('status', t('ebookreader', 'Status'), f.status)
	add('genre', t('ebookreader', 'Genre'), f.genre)
	add('tag', t('ebookreader', 'Tag'), f.tag)
	add('author', t('ebookreader', 'Author'), f.author)
	add('series', t('ebookreader', 'Series'), f.series)
	add('format', t('ebookreader', 'Format'), f.format)
	return chips
})

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
 * @param key
 * @param value
 */
function onDetailsFilter(key: FilterKey, value: string): void {
	store.setFilter(key, value as never)
}

/**
 *
 */
async function onScan(): Promise<void> {
	scanning.value = true
	try {
		const res = await scan()
		showSuccess(res.queued > 0
			? n('ebookreader', 'Scan started: %n folder queued', 'Scan started: %n folders queued', res.queued)
			: t('ebookreader', 'Scan started'))
	} catch {
		showError(t('ebookreader', 'Could not start the scan'))
	} finally {
		scanning.value = false
	}
}

/**
 *
 */
function onSettingsSaved(): void {
	void store.init()
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
	void store.init()
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
		align-items: flex-end;
		gap: 8px;
	}

	&__search {
		flex: 1 1 200px;
		min-width: 160px;
	}

	&__sort {
		flex: 0 1 200px;
		min-width: 140px;
	}

	&__selection,
	&__filters {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
	}

	&__filter-chip {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 2px 8px 2px 12px;
		border: none;
		border-radius: var(--border-radius-pill);
		background: var(--color-primary-element-light);
		cursor: pointer;
		min-height: 0;
	}

	&__center {
		display: flex;
		justify-content: center;
		padding: 48px 0;
	}

	&__sentinel {
		display: flex;
		justify-content: center;
		min-height: 32px;
	}
}

.library-nav {
	&__footer {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 8px;
	}
}
</style>
