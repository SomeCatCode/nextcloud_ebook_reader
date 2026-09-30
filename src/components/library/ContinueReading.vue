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
		</div>
	</section>
</template>

<script setup lang="ts">
import type { Book } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import BookCard from './BookCard.vue'

withDefaults(defineProps<{
	books: Book[]
	activeFileId?: number | null
}>(), { activeFileId: null })

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
}
</style>
