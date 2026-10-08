<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="book-list-wrap">
		<table class="book-list">
			<thead>
				<tr>
					<th v-if="selectMode" class="book-list__check" />
					<th class="book-list__cover" />
					<th>{{ t('ebookreader', 'Title') }}</th>
					<th>{{ t('ebookreader', 'Author') }}</th>
					<th class="hide-narrow">
						{{ t('ebookreader', 'Series') }}
					</th>
					<th class="hide-narrow">
						{{ t('ebookreader', 'Genres') }}
					</th>
					<th>{{ t('ebookreader', 'Rating') }}</th>
					<th>{{ t('ebookreader', 'Progress') }}</th>
					<th class="hide-narrow">
						{{ t('ebookreader', 'Added') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="book in books"
					:key="book.fileId"
					:class="{ 'book-list__row--active': book.fileId === activeFileId, 'book-list__row--selected': selection.has(book.fileId) }"
					tabindex="0"
					@click="$emit('click', book)"
					@keydown.enter.prevent="$emit('click', book)">
					<td v-if="selectMode" class="book-list__check">
						<NcIconSvgWrapper :path="selection.has(book.fileId) ? mdiCheckCircle : mdiCircleOutline" :size="22" />
					</td>
					<td class="book-list__cover">
						<BookCover :book="book" />
					</td>
					<td class="book-list__title">
						{{ bookTitle(book) }}
						<span class="book-list__format">{{ book.format.toUpperCase() }}</span>
						<span v-if="book.ageRating !== null && book.ageRating !== undefined" class="book-list__format" :title="ageTitle(book.ageRating)">{{ ageBadge(book.ageRating) }}</span>
						<span v-if="book.completion" class="book-list__format">{{ completionLabel(book.completion) }}</span>
						<ShareBadge
							class="book-list__share"
							:shared="book.shared"
							:sharedOut="book.sharedOut === true"
							:owner="book.owner" />
					</td>
					<td>{{ bookAuthors(book) }}</td>
					<td class="hide-narrow">
						{{ book.series ? book.series + (book.seriesIndex ? ' #' + book.seriesIndex : '') : '' }}
					</td>
					<td class="hide-narrow">
						{{ book.genres.join(', ') }}
					</td>
					<td>
						<StarRating :value="book.rating" readonly :size="16" />
					</td>
					<td class="book-list__progress">
						<NcProgressBar v-if="progressPercent(book) > 0" :value="progressPercent(book)" size="small" />
					</td>
					<td class="hide-narrow">
						{{ formatDate(book.addedAt) }}
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script setup lang="ts">
import type { Book } from '../../types.ts'

import { mdiCheckCircle, mdiCircleOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import BookCover from './BookCover.vue'
import ShareBadge from './ShareBadge.vue'
import StarRating from './StarRating.vue'
import { ageBadge, ageTitle, completionLabel } from './bookFlags.ts'
import { bookAuthors, bookTitle, formatDate, progressPercent } from './utils.ts'

withDefaults(defineProps<{
	books: Book[]
	activeFileId?: number | null
	selection?: Set<number>
	selectMode?: boolean
}>(), { activeFileId: null, selection: () => new Set<number>(), selectMode: false })

defineEmits<{ click: [book: Book] }>()
</script>

<style scoped lang="scss">
.book-list-wrap {
	overflow-x: auto;
}

.book-list {
	width: 100%;
	border-collapse: collapse;

	th {
		text-align: start;
		padding: 6px 8px;
		color: var(--color-text-maxcontrast);
		font-weight: normal;
		border-bottom: 1px solid var(--color-border);
	}

	td {
		padding: 6px 8px;
		vertical-align: middle;
		border-bottom: 1px solid var(--color-border);
	}

	tbody tr {
		cursor: pointer;

		&:hover,
		&:focus-visible {
			background: var(--color-background-hover);
			outline: none;
		}
	}

	&__row--active {
		background: var(--color-primary-element-light);
	}

	&__row--selected {
		box-shadow: inset 3px 0 0 var(--color-primary-element);
	}

	&__check {
		width: 30px;
		color: var(--color-primary-element);
	}

	&__cover {
		width: 40px;

		:deep(.book-cover) {
			width: 32px;
			border-radius: var(--border-radius-small, 4px);
		}

		:deep(.book-cover__initials) {
			font-size: 0.8em;
		}
	}

	&__title {
		font-weight: bold;
	}

	&__format {
		margin-inline-start: 6px;
		font-size: 10px;
		font-weight: normal;
		color: var(--color-text-maxcontrast);
	}

	&__share {
		margin-inline-start: 6px;
		vertical-align: middle;
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
	}

	&__progress {
		min-width: 70px;
	}
}

@media (max-width: 767px) {
	.hide-narrow {
		display: none;
	}
}
</style>
