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
					<NcButton @click="showShelf = true">
						<template #icon>
							<NcIconSvgWrapper :path="mdiBookshelf" />
						</template>
						{{ t('ebookreader', 'Add to shelf…') }}
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
					<NcButton variant="tertiary" @click="$emit('delete', book.fileId)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDeleteOutline" />
						</template>
						{{ t('ebookreader', 'Delete…') }}
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

				<div v-if="nextBook" class="book-details__next">
					<NcButton variant="secondary" wide @click="readNext">
						<template #icon>
							<NcIconSvgWrapper :path="mdiBookArrowRightOutline" />
						</template>
						{{ t('ebookreader', 'Next volume: {title}', { title: nextLabel }) }}
					</NcButton>
				</div>

				<div class="book-details__row">
					<NcSelect
						:modelValue="currentCompletion"
						:options="completionOptions"
						:inputLabel="t('ebookreader', 'Completion status')"
						:clearable="false"
						:searchable="false"
						label="label"
						@update:modelValue="setCompletion" />
				</div>

				<div class="book-details__row">
					<NcSelect
						:modelValue="currentAge"
						:options="ageOptions"
						:inputLabel="t('ebookreader', 'Age rating')"
						:clearable="false"
						:searchable="false"
						label="label"
						@update:modelValue="setAge" />
					<p v-if="book.ageRatingManual" class="book-details__hint book-details__inline">
						<span>{{ t('ebookreader', 'Set in the app') }}</span>
						<NcButton
							variant="tertiary"
							size="small"
							:disabled="savingAge"
							@click="resetAge">
							{{ t('ebookreader', 'Use value from file') }}
						</NcButton>
					</p>
					<p v-else-if="book.ageRating !== null" class="book-details__hint">
						{{ t('ebookreader', 'From the book file') }}
					</p>
				</div>

				<TagEditor
					:key="`genres-${book.fileId}`"
					:label="t('ebookreader', 'Genres')"
					:addLabel="t('ebookreader', 'Add genre')"
					:items="book.genres"
					:options="genreOptions"
					@filter="(name: string) => $emit('filter', { type: 'genre', name })"
					@remove="(name: string) => saveTags(book.genres.filter((x) => x !== name), book.tags)"
					@add="(name: string) => saveTags([...book.genres, name], book.tags)" />

				<TagEditor
					:key="`tags-${book.fileId}`"
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
				<p v-else-if="writeQueued" class="book-details__hint">
					{{ t('ebookreader', 'Saved – will be written into the file in the background') }}
				</p>

				<p v-if="book.hasSidecar" class="book-details__hint">
					{{ t('ebookreader', 'Metadata stored in sidecar file') }}
				</p>

				<div v-if="embeddable" class="book-details__embed">
					<NcButton :disabled="embedding" @click="embed">
						<template #icon>
							<NcLoadingIcon v-if="embedding" :size="20" />
							<NcIconSvgWrapper v-else :path="mdiFileReplaceOutline" />
						</template>
						{{ t('ebookreader', 'Write metadata into the book file') }}
					</NcButton>
					<p class="book-details__hint">
						{{ t('ebookreader', 'Other readers such as Kobo or KOReader only read the metadata inside the book, not the sidecar file.') }}
					</p>
				</div>

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

				<ul v-if="overrideRows.length" class="book-details__overrides">
					<li v-for="o in overrideRows" :key="o.field">
						<span>{{ o.text }}</span>
						<NcButton variant="tertiary" :disabled="resettingOverride" @click="resetOverride(o.field)">
							{{ t('ebookreader', 'Use value from file') }}
						</NcButton>
					</li>
				</ul>
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>

	<AddToShelfDialog
		v-if="showShelf"
		:fileIds="[book.fileId]"
		@close="showShelf = false" />

	<ConvertDialog
		v-if="showConvert"
		:book="book"
		@close="showConvert = false"
		@converted="onConverted" />
</template>

<script setup lang="ts">
import type { AgeRating, Book, Completion, FilterTerm, MetadataOverrideField, ReadStatus } from '../../types.ts'

import { mdiBookArrowRightOutline, mdiBookOpenPageVariant, mdiBookOpenVariant, mdiBookshelf, mdiDeleteOutline, mdiFileReplaceOutline, mdiFolderMoveOutline, mdiFolderOutline, mdiPencil, mdiSwapHorizontal } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ConvertDialog from '../convert/ConvertDialog.vue'
import AddToShelfDialog from './AddToShelfDialog.vue'
import BookCover from './BookCover.vue'
import StarRating from './StarRating.vue'
import TagEditor from './TagEditor.vue'
import { sanitizeDescription } from '../../editor/sanitize.ts'
import { nextVolume } from '../../services/api.ts'
import { useLibraryStore } from '../../stores/library.ts'
import { useSettingsStore } from '../../stores/settings.ts'
import { AGE_RATINGS, ageBadge, completionLabel, volumeLabel } from './bookFlags.ts'
import { canEmbed } from './metadataStorage.ts'
import { bookAuthors, bookTitle, dirName, formatDate, progressPercent } from './utils.ts'

const props = defineProps<{ book: Book }>()

const emit = defineEmits<{
	close: []
	filter: [term: FilterTerm]
	organize: [fileId: number]
	converted: [fileId: number]
	delete: [fileId: number]
}>()

const router = useRouter()
const store = useLibraryStore()
const settings = useSettingsStore()

const showConvert = ref(false)
const showShelf = ref(false)
const warnings = ref<string[]>([])
const writeQueued = ref(false)
const embedding = ref(false)
const embeddable = computed(() => canEmbed(props.book.format, props.book.editable, props.book.downloadable))
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
	? sanitizeDescription(props.book.description)
	: ''))

const statusOptions = computed<{ id: ReadStatus, label: string }[]>(() => [
	{ id: 'unread', label: t('ebookreader', 'Unread') },
	{ id: 'reading', label: t('ebookreader', 'Reading') },
	{ id: 'finished', label: t('ebookreader', 'Finished') },
])
const currentStatus = computed(() => statusOptions.value.find((o) => o.id === props.book.readStatus) ?? statusOptions.value[0])

const completionOptions = computed<{ id: Completion | null, label: string }[]>(() => [
	{ id: null, label: completionLabel(null) },
	{ id: 'ongoing', label: completionLabel('ongoing') },
	{ id: 'completed', label: completionLabel('completed') },
])
const currentCompletion = computed(() => completionOptions.value.find((o) => o.id === (props.book.completion ?? null)) ?? completionOptions.value[0])

const ageOptions = computed<{ id: AgeRating | null, label: string }[]>(() => [
	{ id: null, label: t('ebookreader', 'No age rating') },
	...AGE_RATINGS.map((a) => ({ id: a, label: ageBadge(a) })),
])
const currentAge = computed(() => ageOptions.value.find((o) => o.id === (props.book.ageRating ?? null)) ?? ageOptions.value[0])
const savingAge = ref(false)

/** next volume of a finished book in a series ("continue the series") */
const nextBook = ref<Book | null>(null)
const nextLabel = computed(() => (nextBook.value ? volumeLabel(nextBook.value, bookTitle(nextBook.value)) : ''))
let nextRequest = 0

/**
 * Looks up the next volume once the book is finished.
 */
async function loadNext(): Promise<void> {
	const id = ++nextRequest
	nextBook.value = null
	if (!props.book.series || props.book.readStatus !== 'finished') {
		return
	}
	try {
		const next = await nextVolume(props.book.fileId)
		if (id === nextRequest) {
			nextBook.value = next
		}
	} catch {
		// no link then
	}
}

watch(() => [props.book.fileId, props.book.readStatus, props.book.series], () => {
	void loadNext()
}, { immediate: true })

/**
 *
 */
function readNext(): void {
	if (nextBook.value) {
		void router.push(`/read/${nextBook.value.fileId}`)
	}
}

const filesUrl = computed(() => generateUrl('/apps/files/files/{fileId}', { fileId: props.book.fileId })
	+ '?dir=' + encodeURIComponent(dirName(props.book.path)) + '&openfile=false')

const OVERRIDE_LABELS: Record<MetadataOverrideField, () => string> = {
	title: () => t('ebookreader', 'Title'),
	authors: () => t('ebookreader', 'Author'),
	series: () => t('ebookreader', 'Series'),
	seriesIndex: () => t('ebookreader', 'Series number'),
	description: () => t('ebookreader', 'Description'),
	language: () => t('ebookreader', 'Language'),
	publisher: () => t('ebookreader', 'Publisher'),
	isbn: () => t('ebookreader', 'ISBN'),
	publishedAt: () => t('ebookreader', 'Published'),
}

/** Fields edited in the app only: a re-scan of the file does not overwrite them. */
const overrideRows = computed(() => (props.book.overrides ?? []).map((field) => ({
	field,
	text: t('ebookreader', '{field} edited in app', { field: OVERRIDE_LABELS[field]() }),
})))
const resettingOverride = ref(false)

// the sidebar stays mounted when another book is selected: drop per-book local state
watch(() => props.book.fileId, () => {
	showConvert.value = false
	showShelf.value = false
	writeQueued.value = false
	warnings.value = []
	resettingOverride.value = false
	embedding.value = false
})

/**
 * Writes the library metadata into the book file.
 */
async function embed(): Promise<void> {
	const fileId = props.book.fileId
	embedding.value = true
	try {
		const res = await store.embedMetadata(fileId)
		if (props.book.fileId !== fileId) {
			return
		}
		warnings.value = res.warnings
		showSuccess(res.written
			? t('ebookreader', 'Metadata written into the book file')
			: t('ebookreader', 'The book file already contains this metadata'))
	} catch {
		showError(t('ebookreader', 'Could not write the metadata into the book file'))
	} finally {
		if (props.book.fileId === fileId) {
			embedding.value = false
		}
	}
}

/**
 * @param field
 */
async function resetOverride(field: MetadataOverrideField): Promise<void> {
	resettingOverride.value = true
	try {
		await store.resetOverrides(props.book.fileId, field)
	} catch {
		showError(t('ebookreader', 'Could not read the value from the file'))
	} finally {
		resettingOverride.value = false
	}
}

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
		const res = await store.saveBookTags(props.book.fileId, { genres, tags })
		warnings.value = res.warnings
		writeQueued.value = res.writeQueued
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
async function setCompletion(option: { id: Completion | null } | null): Promise<void> {
	if (!option || option.id === (props.book.completion ?? null)) {
		return
	}
	try {
		await store.setCompletion(props.book.fileId, option.id)
	} catch {
		showError(t('ebookreader', 'Could not save the completion status'))
	}
}

/**
 * @param option
 * @param option.id
 */
async function setAge(option: { id: AgeRating | null } | null): Promise<void> {
	if (!option || (option.id === (props.book.ageRating ?? null) && props.book.ageRatingManual)) {
		return
	}
	savingAge.value = true
	try {
		await store.setAgeRating(props.book.fileId, option.id)
	} catch {
		showError(t('ebookreader', 'Could not save the age rating'))
	} finally {
		savingAge.value = false
	}
}

/**
 * Drops the age rating set in the app: the value from the file applies again.
 */
async function resetAge(): Promise<void> {
	savingAge.value = true
	try {
		await store.resetAgeRating(props.book.fileId)
	} catch {
		showError(t('ebookreader', 'Could not read the value from the file'))
	} finally {
		savingAge.value = false
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

	&__overrides {
		list-style: none;
		margin: 8px 0 0;
		padding: 0;
		font-size: 0.85em;
		color: var(--color-text-maxcontrast);

		li {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 8px;
		}
	}

	&__inline {
		display: flex;
		align-items: center;
		gap: 4px;
	}

	&__embed {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 4px;
	}

	&__warning,
	&__hint {
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
		gap: 4px 16px;
		margin: 0;
		padding: 0;

		dt {
			margin: 0;
			padding: 0;
			color: var(--color-text-maxcontrast);
		}

		dd {
			margin: 0;
			padding: 0;
			min-width: 0;
			overflow-wrap: anywhere;
		}
	}

	&__link {
		margin: 0;
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
