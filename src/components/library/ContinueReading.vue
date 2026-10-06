<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="continue-reading" :aria-label="t('ebookreader', 'Continue reading')">
		<h3>{{ t('ebookreader', 'Continue reading') }}</h3>
		<div class="continue-reading__row">
			<BookCard
				v-for="book in books"
				:key="book.fileId"
				class="continue-reading__item"
				:book="book"
				:active="book.fileId === activeFileId"
				@click="$emit('click', $event)" />
			<div
				v-for="entry in upNext"
				:key="'next-' + entry.book.fileId"
				class="continue-reading__item continue-reading__next">
				<BookCard
					:book="entry.book"
					:active="entry.book.fileId === activeFileId"
					@click="$emit('click', $event)" />
				<span class="continue-reading__label" :title="t('ebookreader', 'You finished the previous volume of this series')">
					{{ t('ebookreader', 'Next volume') }}
				</span>
			</div>
		</div>
	</section>
</template>

<script setup lang="ts">
import type { Book, UpNext } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import BookCard from './BookCard.vue'

withDefaults(defineProps<{
	books: Book[]
	/** next volumes of series whose latest read volume is finished */
	upNext?: UpNext[]
	activeFileId?: number | null
}>(), { activeFileId: null, upNext: () => [] })

defineEmits<{ click: [book: Book] }>()
</script>

<style scoped lang="scss">
.continue-reading {
	margin-bottom: 16px;

	h3 {
		margin: 0 0 4px 6px;
	}

	&__row {
		display: flex;
		gap: 8px;
		overflow-x: auto;
		padding-bottom: 6px;
		scroll-snap-type: x proximity;
	}

	&__item {
		flex: 0 0 130px;
		scroll-snap-align: start;
	}

	&__next {
		display: flex;
		flex-direction: column;
	}

	&__label {
		align-self: flex-start;
		margin: 0 6px;
		padding: 0 8px;
		border-radius: var(--border-radius-pill);
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
		font-size: 11px;
		line-height: 18px;
		white-space: nowrap;
	}
}
</style>
