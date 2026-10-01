<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<article
		class="series-card"
		role="button"
		tabindex="0"
		:title="t('ebookreader', 'Show the volumes of “{name}”', { name: series.name })"
		@click="$emit('click', series)"
		@keydown.enter.prevent="$emit('click', series)"
		@keydown.space.prevent="$emit('click', series)">
		<div class="series-card__stack">
			<div
				v-for="(id, i) in stackIds"
				:key="id"
				class="series-card__cover"
				:class="`series-card__cover--${i}`">
				<img
					v-if="!failed.has(id)"
					:src="coverUrl(id, 'small')"
					alt=""
					loading="lazy"
					decoding="async"
					@error="failed.add(id)">
				<div v-else class="series-card__initials" :style="{ background: `hsl(${hue(series.name)}, 35%, 40%)` }">
					{{ initials(series.name) }}
				</div>
			</div>
			<div
				v-if="stackIds.length === 0"
				class="series-card__cover series-card__cover--0">
				<div class="series-card__initials" :style="{ background: `hsl(${hue(series.name)}, 35%, 40%)` }">
					{{ initials(series.name) }}
				</div>
			</div>
		</div>
		<div class="series-card__title" :title="series.name">
			{{ series.name }}
		</div>
		<div class="series-card__meta">
			{{ n('ebookreader', '%n volume', '%n volumes', series.count) }}
			<span v-if="series.readCount > 0">· {{ t('ebookreader', '{read}/{total} read', { read: series.readCount, total: series.count }) }}</span>
		</div>
		<div v-if="series.readCount > 0" class="series-card__progress">
			<div class="series-card__progress-bar" :style="{ width: Math.min(100, Math.round(100 * series.readCount / series.count)) + '%' }" />
		</div>
	</article>
</template>

<script setup lang="ts">
import type { SeriesEntry } from '../../types.ts'

import { n, t } from '@nextcloud/l10n'
import { computed, reactive } from 'vue'
import { coverUrl } from '../../services/api.ts'
import { hue, initials } from './utils.ts'

const props = defineProps<{ series: SeriesEntry }>()

defineEmits<{ click: [series: SeriesEntry] }>()

const failed = reactive(new Set<number>())
/** back to front: the last cover is the front one */
const stackIds = computed(() => props.series.coverFileIds.slice(0, 3).slice().reverse())
</script>

<style scoped lang="scss">
.series-card {
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

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
	}

	&__stack {
		position: relative;
		width: 100%;
		aspect-ratio: 2 / 3;
	}

	&__cover {
		position: absolute;
		inset: 0;
		width: 84%;
		height: 92%;
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

		// the stack grows from the front cover (last element) towards the back right
		&:nth-last-child(1) { inset: auto auto 0 0; }
		&:only-child { inset: 0; width: 100%; height: 100%; }
		&:nth-last-child(2) { inset: 4% 0 auto auto; width: 84%; opacity: .95; }
		&:nth-last-child(3) { inset: 0 0 auto auto; width: 84%; opacity: .85; }
	}

	&__initials {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 100%;
		height: 100%;
		color: #fff;
		font-size: 2em;
		font-weight: bold;
		user-select: none;
	}

	&__title {
		margin-top: 4px;
		font-weight: bold;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__meta {
		color: var(--color-text-maxcontrast);
		font-size: .9em;
	}

	&__progress {
		height: 3px;
		border-radius: 2px;
		background: var(--color-background-dark);
		overflow: hidden;
	}

	&__progress-bar {
		height: 100%;
		background: var(--color-primary-element);
	}
}
</style>
