<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="book-grid">
		<BookCard
			v-for="book in books"
			:key="book.fileId"
			:book="book"
			:active="book.fileId === activeFileId"
			:selected="selection.has(book.fileId)"
			:selectMode="selectMode"
			@click="$emit('click', $event)"
			@filter="$emit('filter', $event)" />
	</div>
</template>

<script setup lang="ts">
import type { Book, FilterTerm } from '../../types.ts'

import BookCard from './BookCard.vue'

withDefaults(defineProps<{
	books: Book[]
	activeFileId?: number | null
	selection?: Set<number>
	selectMode?: boolean
}>(), { activeFileId: null, selection: () => new Set<number>(), selectMode: false })

defineEmits<{ click: [book: Book], filter: [term: FilterTerm] }>()
</script>

<style scoped lang="scss">
.book-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
	gap: 8px;

	@media (min-width: 768px) {
		grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
		gap: 12px;
	}
}
</style>
