<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<article
		class="book-card"
		:class="{ 'book-card--active': active, 'book-card--selected': selected }"
		role="button"
		tabindex="0"
		:aria-pressed="selectMode ? selected : undefined"
		@click="$emit('click', book)"
		@keydown.enter.prevent="$emit('click', book)"
		@keydown.space.prevent="$emit('click', book)">
		<div class="book-card__cover">
			<BookCover :book="book" />
			<span class="book-card__format">{{ book.format.toUpperCase() }}</span>
			<span v-if="selectMode" class="book-card__check">
				<NcIconSvgWrapper :path="selected ? mdiCheckCircle : mdiCircleOutline" :size="26" />
			</span>
			<div v-if="percent > 0" class="book-card__progress" :title="t('ebookreader', '{percent}% read', { percent })">
				<div class="book-card__progress-bar" :style="{ width: percent + '%' }" />
			</div>
		</div>
		<div class="book-card__title" :title="title">
			{{ title }}
		</div>
		<div v-if="authors" class="book-card__authors" :title="authors">
			{{ authors }}
		</div>
		<div v-if="book.genres.length" class="book-card__chips">
			<button
				v-for="g in book.genres.slice(0, 2)"
				:key="g"
				type="button"
				class="book-card__chip"
				:title="t('ebookreader', 'Show books with “{name}”', { name: g })"
				@click.stop="$emit('filter', { type: 'genre', name: g })"
				@keydown.stop>
				{{ g }}
			</button>
		</div>
	</article>
</template>

<script setup lang="ts">
import type { Book, FilterTerm } from '../../types.ts'

import { mdiCheckCircle, mdiCircleOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import BookCover from './BookCover.vue'
import { bookAuthors, bookTitle, progressPercent } from './utils.ts'

const props = defineProps<{
	book: Book
	active?: boolean
	selected?: boolean
	selectMode?: boolean
}>()

defineEmits<{ click: [book: Book], filter: [term: FilterTerm] }>()

const title = computed(() => bookTitle(props.book))
const authors = computed(() => bookAuthors(props.book))
const percent = computed(() => progressPercent(props.book))
</script>

<style scoped lang="scss">
.book-card {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	padding: 6px;
	border-radius: var(--border-radius-large);
	cursor: pointer;
	outline: none;

	&:hover,
	&:focus-visible {
		background: var(--color-background-hover);
	}

	&--active {
		background: var(--color-primary-element-light);
	}

	&--selected {
		outline: 2px solid var(--color-primary-element);
	}

	&__cover {
		position: relative;
		margin-bottom: 4px;
	}

	&__format {
		position: absolute;
		top: 6px;
		left: 6px;
		padding: 0 6px;
		border-radius: var(--border-radius-small, 4px);
		background: rgba(0, 0, 0, 0.6);
		color: #fff;
		font-size: 10px;
		font-weight: bold;
		line-height: 18px;
	}

	&__check {
		position: absolute;
		top: 4px;
		right: 4px;
		display: flex;
		border-radius: 50%;
		background: var(--color-main-background);
		color: var(--color-primary-element);
	}

	&__progress {
		position: absolute;
		left: 0;
		right: 0;
		bottom: 0;
		height: 5px;
		background: rgba(0, 0, 0, 0.35);
	}

	&__progress-bar {
		height: 100%;
		background: var(--color-primary-element);
	}

	&__title {
		font-weight: bold;
		line-height: 1.25;
		display: -webkit-box;
		-webkit-line-clamp: 2;
		line-clamp: 2;
		-webkit-box-orient: vertical;
		overflow: hidden;
		overflow-wrap: anywhere;
	}

	&__authors {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		gap: 4px;
		margin-top: 2px;
	}

	&__chip {
		max-width: 100%;
		min-height: 0;
		padding: 0 8px;
		border: none;
		cursor: pointer;
		color: inherit;
		border-radius: var(--border-radius-pill);
		background: var(--color-background-dark);
		font-size: 11px;
		line-height: 18px;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;

		&:hover {
			background: var(--color-primary-element-light-hover);
		}
	}
}
</style>
