<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="book-cover" :class="{ 'book-cover--placeholder': !showImage }">
		<img
			v-if="showImage"
			:src="src"
			alt=""
			:loading="size === 'small' ? 'lazy' : 'eager'"
			decoding="async"
			@error="failed = true">
		<div
			v-else
			class="book-cover__initials"
			:style="{ background: `hsl(${hue(title)}, 35%, 40%)` }"
			aria-hidden="true">
			{{ initials(title) }}
		</div>
	</div>
</template>

<script setup lang="ts">
import type { Book } from '../../types.ts'

import { computed, ref, watch } from 'vue'
import { coverUrl } from '../../services/api.ts'
import { bookTitle, hue, initials } from './utils.ts'

const props = withDefaults(defineProps<{
	book: Book
	size?: 'small' | 'large'
}>(), { size: 'small' })

const failed = ref(false)
watch(() => [props.book.fileId, props.book.coverEtag], () => {
	failed.value = false
})

const title = computed(() => bookTitle(props.book))
const showImage = computed(() => props.book.hasCover && !failed.value)
const src = computed(() => coverUrl(props.book.fileId, props.size, props.book.coverEtag))
</script>

<style scoped lang="scss">
.book-cover {
	position: relative;
	width: 100%;
	aspect-ratio: 2 / 3;
	overflow: hidden;
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
	box-shadow: 0 1px 4px var(--color-box-shadow);

	img {
		width: 100%;
		height: 100%;
		object-fit: cover;
		display: block;
	}

	&__initials {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 100%;
		height: 100%;
		color: #fff;
		font-size: 2.2em;
		font-weight: bold;
		letter-spacing: 0.05em;
		user-select: none;
	}
}
</style>
