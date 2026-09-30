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

				<div v-if="book.genres.length" class="book-details__row">
					<label>{{ t('ebookreader', 'Genres') }}</label>
					<div class="book-details__chips">
						<button
							v-for="g in book.genres"
							:key="g"
							type="button"
							class="book-details__chip"
							@click="$emit('filter', 'genre', g)">
							{{ g }}
						</button>
					</div>
				</div>

				<div v-if="book.tags.length" class="book-details__row">
					<label>{{ t('ebookreader', 'Tags') }}</label>
					<div class="book-details__chips">
						<button
							v-for="tag in book.tags"
							:key="tag"
							type="button"
							class="book-details__chip"
							@click="$emit('filter', 'tag', tag)">
							{{ tag }}
						</button>
					</div>
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
								@click="$emit('filter', row.filter.key, row.filter.value)">
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
</template>

<script setup lang="ts">
import type { FilterKey } from '../../stores/library.ts'
import type { Book, ReadStatus } from '../../types.ts'

import { mdiBookOpenPageVariant, mdiBookOpenVariant, mdiFolderOutline, mdiPencil } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import DOMPurify from 'dompurify'
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import BookCover from './BookCover.vue'
import StarRating from './StarRating.vue'
import { useLibraryStore } from '../../stores/library.ts'
import { bookAuthors, bookTitle, dirName, formatDate, progressPercent } from './utils.ts'

const props = defineProps<{ book: Book }>()

defineEmits<{
	close: []
	filter: [key: FilterKey, value: string]
}>()

const router = useRouter()
const store = useLibraryStore()

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
	filter?: { key: FilterKey, value: string }
}

const metaRows = computed<MetaRow[]>(() => {
	const b = props.book
	const rows: MetaRow[] = []
	for (const a of b.authors) {
		rows.push({ label: t('ebookreader', 'Author'), value: a, filter: { key: 'author', value: a } })
	}
	if (b.series) {
		rows.push({
			label: t('ebookreader', 'Series'),
			value: b.series + (b.seriesIndex ? ' #' + b.seriesIndex : ''),
			filter: { key: 'series', value: b.series },
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
