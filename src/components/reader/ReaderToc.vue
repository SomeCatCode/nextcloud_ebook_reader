<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<ul class="reader-toc">
		<li v-for="(item, i) in items" :key="i">
			<button
				type="button"
				class="reader-toc__item"
				:class="{ 'reader-toc__item--active': isActive(item) }"
				:style="{ paddingInlineStart: (12 + depth * 16) + 'px' }"
				@click="emit('select', item)">
				{{ item.label }}
			</button>
			<ReaderToc
				v-if="item.subitems?.length"
				:items="item.subitems"
				:depth="depth + 1"
				:currentHref="currentHref"
				@select="emit('select', $event)" />
		</li>
	</ul>
</template>

<script setup lang="ts">
import type { TocItem } from '../../../packages/reader-core/index.ts'

const props = withDefaults(defineProps<{ items: TocItem[], depth?: number, currentHref?: string }>(), { depth: 0, currentHref: '' })
const emit = defineEmits<{ select: [item: TocItem] }>()

/**
 * @param item
 */
function isActive(item: TocItem): boolean {
	return !!props.currentHref && item.href.split('#')[0] === props.currentHref
}
</script>

<style scoped>
.reader-toc { list-style: none; margin: 0; padding: 0; }
.reader-toc__item {
	display: block; width: 100%; text-align: start; background: none; border: 0;
	padding-block: 8px; padding-inline-end: 12px; cursor: pointer; color: inherit;
	border-radius: var(--border-radius-element, 8px);
}
.reader-toc__item:hover { background: var(--color-background-hover); }
.reader-toc__item--active { font-weight: bold; background: var(--color-primary-element-light); }
</style>
