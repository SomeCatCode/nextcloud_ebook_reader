<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppSidebar
		:name="title"
		:subname="authors"
		:open="true"
		@update:open="(open: boolean) => !open && $emit('close')"
		@close="$emit('close')">
		<NcAppSidebarTab id="details" :name="t('ebookreader', 'Details')" :order="1">
			<template #icon>
				<NcIconSvgWrapper :path="mdiBookOpenPageVariant" :size="20" />
			</template>
			<div class="book-details">
				<div class="book-details__cover">
					<BookCover :book="book" size="large" />
				</div>

				<div class="book-details__actions">
					<NcButton variant="primary" @click="read">
						<template #icon>
							<NcIconSvgWrapper :path="mdiBookOpenVariant" />
						</template>
						{{ t('ebookreader', 'Read') }}
					</NcButton>
					<NcButton @click="edit">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPencil" />
						</template>
						{{ t('ebookreader', 'Edit') }}
					</NcButton>
					<NcButton :href="filesUrl">
						<template #icon>
							<NcIconSvgWrapper :path="mdiFolderOutline" />
						</template>
						{{ t('ebookreader', 'Show in Files') }}
					</NcButton>
					<NcButton @click="$emit('organize', book.fileId)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiFolderMoveOutline" />
						</template>
						{{ t('ebookreader', 'Rename / organise…') }}
					</NcButton>
					<NcButton v-if="convertible" @click="showConvert = true">
						<template #icon>
							<NcIconSvgWrapper :path="mdiSwapHorizontal" />
						</template>
						{{ t('ebookreader', 'Convert format…') }}
					</NcButton>
				</div>

				<div class="book-details__row">
					<label>{{ t('ebookreader', 'Rating') }}</label>
					<StarRating :value="book.rating" @update="setRating" />
				</div>

				<div class="book-details__row">
					<NcSelect
						:modelValue="currentStatus"
						:options="statusOptions"
						:inputLabel="t('ebookreader', 'Reading status')"
						:clearable="false"
						:searchable="false"
						label="label"
						@update:modelValue="setStatus" />
				</div>

				<div v-if="percent > 0" class="book-details__row">
					<label>{{ t('ebookreader', 'Progress') }}: {{ percent }}%</label>
					<NcProgressBar :value="percent" />
				</div>

				<TagEditor
					:label="t('ebookreader', 'Genres')"
					:addLabel="t('ebookreader', 'Add genre')"
					:items="book.genres"
					:options="genreOptions"
					@filter="(name: string) => $emit('filter', { type: 'genre', name })"
					@remove="(name: string) => saveTags(book.genres.filter((x) => x !== name), book.tags)"
					@add="(name: string) => saveTags([...book.genres, name], book.tags)" />

				<TagEditor
					:label="t('ebookreader', 'Tags')"
					:addLabel="t('ebookreader', 'Add tag')"
					:items="book.tags"
					:options="tagOptions"
					@filter="(name: string) => $emit('filter', { type: 'tag', name })"
					@remove="(name: string) => saveTags(book.genres, book.tags.filter((x) => x !== name))"
					@add="(name: string) => saveTags(book.genres, [...book.tags, name])" />

				<p v-if="warnings.length" class="book-details__warning">
					{{ warnings.join(' ') }}
				</p>

				<!-- eslint-disable-next-line vue/no-v-html -->
				<div v-if="description" class="book-details__description" v-html="description" />

				<dl class="book-details__meta">
					<template v-for="row in metaRows" :key="row.label">
						<dt>{{ row.label }}</dt>
						<dd>
							<button
								v-if="row.filter"
								type="button"
								class="book-details__link"
								@click="$emit('filter', row.filter)">
								{{ row.value }}
							</button>
							<template v-else>
								{{ row.value }}
							</template>
						</dd>
					</template>
				</dl>
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>

	<ConvertDialog
		v-if="showConvert"
		:book="book"
		@close="showConvert = false"
		@converted="onConverted" />
</template>

<script setup lang="ts">
import type { Book, FilterTerm, ReadStatus } from '../../types.ts'

import { mdiBookOpenPageVariant, mdiBookOpenVariant, mdiFolderMoveOutline, mdiFolderOutline, mdiPencil, mdiSwapHorizontal } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import DOMPurify from 'dompurify'
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ConvertDialog from '../convert/ConvertDialog.vue'
import BookCover from './BookCover.vue'
import StarRating from './StarRating.vue'
import TagEditor from './TagEditor.vue'
import { useLibraryStore } from '../../stores/library.ts'
import { useSettingsStore } from '../../stores/settings.ts'
import { bookAuthors, bookTitle, dirName, formatDate, progressPercent } from './utils.ts'

const props = defineProps<{ book: Book }>()

const emit = defineEmits<{
	close: []
	filter: [term: FilterTerm]
	organize: [fileId: number]
	converted: [fileId: number]
}>()

const router = useRouter()
const store = useLibraryStore()
const settings = useSettingsStore()

const showConvert = ref(false)
const warnings = ref<string[]>([])
const convertible = computed(() => ['cbz', 'cbr', 'cb7', 'cbt'].includes(props.book.format))
const genreOptions = computed(() => [...new Set([
	...(settings.settings.genreList ?? []),
	...store.facets.genres.map((g) => g.name),
])])
const tagOptions = computed(() => store.facets.tags.map((x) => x.name))

const title = computed(() => bookTitle(props.book))
const authors = computed(() => bookAuthors(props.book))
const percent = computed(() => progressPercent(props.book))
const description = computed(() => (props.book.description
	? DOMPurify.sanitize(props.book.description, { USE_PROFILES: { html: true } })
	: ''))

const statusOptions = computed<{ id: ReadStatus, label: string }[]>(() => [
	{ id: 'unread', label: t('ebookreader', 'Unread') },
	{ id: 'reading', label: t('ebookreader', 'Reading') },
	{ id: 'finished', label: t('ebookreader', 'Finished') },
])
const currentStatus = computed(() => statusOptions.value.find((o) => o.id === props.book.readStatus) ?? statusOptions.value[0])

const filesUrl = computed(() => generateUrl('/apps/files/files/{fileId}', { fileId: props.book.fileId })
	+ '?dir=' + encodeURIComponent(dirName(props.book.path)) + '&openfile=false')

interface MetaRow {
	label: string
	value: string
	filter?: FilterTerm
}

const metaRows = computed<MetaRow[]>(() => {
	const b = props.book
	const rows: MetaRow[] = []
	for (const a of b.authors) {
		rows.push({ label: t('ebookreader', 'Author'), value: a, filter: { type: 'author', name: a } })
	}
	if (b.series) {
		rows.push({
			label: t('ebookreader', 'Series'),
			value: b.series + (b.seriesIndex ? ' #' + b.seriesIndex : ''),
			filter: { type: 'series', name: b.series },
		})
	}
	const plain: [string, string | null][] = [
		[t('ebookreader', 'Publisher'), b.publisher],
		[t('ebookreader', 'Published'), b.publishedAt],
		[t('ebookreader', 'Language'), b.language],
		[t('ebookreader', 'ISBN'), b.isbn],
		[t('ebookreader', 'Format'), b.format.toUpperCase()],
		[t('ebookreader', 'Size'), formatSize(b.size)],
		[t('ebookreader', 'Added'), formatDate(b.addedAt)],
		[t('ebookreader', 'Path'), b.path],
	]
	for (const [label, value] of plain) {
		if (value) {
			rows.push({ label, value })
		}
	}
	return rows
})

/**
 * @param bytes
 */
function formatSize(bytes: number): string {
	if (bytes >= 1024 * 1024) {
		return (bytes / 1024 / 1024).toFixed(1) + ' MB'
	}
	return Math.max(1, Math.round(bytes / 1024)) + ' KB'
}

/**
 * @param genres
 * @param tags
 */
async function saveTags(genres: string[], tags: string[]): Promise<void> {
	try {
		warnings.value = await store.saveBookTags(props.book.fileId, { genres, tags })
	} catch {
		showError(t('ebookreader', 'Could not save genres and tags'))
	}
}

/**
 * @param fileId
 */
function onConverted(fileId: number): void {
	showConvert.value = false
	emit('converted', fileId)
}

/**
 *
 */
function read(): void {
	void router.push(`/read/${props.book.fileId}`)
}

/**
 *
 */
function edit(): void {
	void router.push(`/edit/${props.book.fileId}`)
}

/**
 * @param rating
 */
async function setRating(rating: number | null): Promise<void> {
	try {
		await store.setRating(props.book.fileId, rating)
	} catch {
		showError(t('ebookreader', 'Could not save the rating'))
	}
}

/**
 * @param option
 * @param option.id
 */
async function setStatus(option: { id: ReadStatus } | null): Promise<void> {
	if (!option) {
		return
	}
	try {
		await store.setReadStatus(props.book.fileId, option.id)
	} catch {
		showError(t('ebookreader', 'Could not save the reading status'))
	}
}
</script>

<style scoped lang="scss">
.book-details {
	display: flex;
	flex-direction: column;
	gap: 14px;
	padding: 8px 0;

	&__cover {
		width: 60%;
		max-width: 240px;
		margin: 0 auto;
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		justify-content: center;
	}

	&__row {
		display: flex;
		flex-direction: column;
		gap: 4px;

		label {
			color: var(--color-text-maxcontrast);
		}
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	&__chip {
		padding: 2px 12px;
		border: none;
		border-radius: var(--border-radius-pill);
		background: var(--color-background-dark);
		cursor: pointer;
		min-height: 0;

		&:hover {
			background: var(--color-primary-element-light-hover);
		}
	}

	&__warning {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
	}

	&__description {
		overflow-wrap: anywhere;

		:deep(p) {
			margin: 0 0 0.6em;
		}
	}

	&__meta {
		display: grid;
		grid-template-columns: max-content 1fr;
		gap: 4px 12px;
		margin: 0;

		dt {
			color: var(--color-text-maxcontrast);
		}

		dd {
			margin: 0;
			overflow-wrap: anywhere;
		}
	}

	&__link {
		padding: 0;
		border: none;
		background: none;
		color: var(--color-primary-element);
		cursor: pointer;
		min-height: 0;
		text-align: start;

		&:hover {
			text-decoration: underline;
		}
	}
}
</style>
