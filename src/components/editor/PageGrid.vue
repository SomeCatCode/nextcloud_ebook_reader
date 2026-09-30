<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="page-grid">
		<div class="page-grid__toolbar">
			<NcButton :disabled="disabled || selected.size === 0" variant="error" @click="deleteSelected">
				{{ t('ebookreader', 'Remove selected ({n})', { n: selected.size }) }}
			</NcButton>
			<NcButton :disabled="disabled || selectedRemoved.length === 0" @click="restoreSelected">
				{{ t('ebookreader', 'Restore selected') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="selected.size === 0" @click="selected = new Set()">
				{{ t('ebookreader', 'Clear selection') }}
			</NcButton>
			<span class="page-grid__count">
				{{ t('ebookreader', '{active} of {total} pages kept', { active: activeItems.length, total: orderedItems.length }) }}
			</span>
		</div>

		<VueDraggable
			v-model="list"
			class="page-grid__grid"
			:animation="150"
			:disabled="disabled">
			<figure
				v-for="(item, index) in list"
				:key="item.id"
				class="page-grid__page"
				:class="{
					'page-grid__page--selected': selected.has(item.id),
					'page-grid__page--removed': removed.has(item.id),
				}">
				<img
					:src="itemUrl(fileId, item.id)"
					loading="lazy"
					draggable="false"
					:alt="t('ebookreader', 'Page {n}', { n: index + 1 })"
					@click="toggle(item.id)">
				<figcaption>
					<label>
						<input type="checkbox" :checked="selected.has(item.id)" @change="toggle(item.id)">
						{{ index + 1 }}
					</label>
					<span v-if="isCover(item.id)" class="badge">{{ t('ebookreader', 'Cover') }}</span>
					<NcButton
						v-else
						variant="tertiary"
						:disabled="disabled || removed.has(item.id)"
						@click="state.setCover({ source: 'item', itemId: item.id })">
						{{ t('ebookreader', 'Use as cover') }}
					</NcButton>
				</figcaption>
			</figure>
		</VueDraggable>
	</div>
</template>

<script setup lang="ts">
import type { StructureItem } from '../../types.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import { VueDraggable } from 'vue-draggable-plus'
import NcButton from '@nextcloud/vue/components/NcButton'
import { useEditor } from '../../editor/useEditorState.ts'
import { itemUrl } from '../../services/api.ts'

defineProps<{ fileId: number, disabled?: boolean }>()

const state = useEditor()
const { orderedItems, activeItems, removed, cover } = state

const selected = ref<Set<string>>(new Set())

const list = computed<StructureItem[]>({
	get: () => orderedItems.value,
	set: (items) => state.setOrder(items.map((i) => i.id)),
})

const selectedRemoved = computed(() => [...selected.value].filter((id) => removed.value.has(id)))

/**
 * @param id
 */
function isCover(id: string): boolean {
	return cover.value?.source === 'item' && cover.value.itemId === id
}

/**
 * @param id
 */
function toggle(id: string): void {
	const next = new Set(selected.value)
	if (!next.delete(id)) {
		next.add(id)
	}
	selected.value = next
}

/**
 *
 */
function deleteSelected(): void {
	state.removeItems([...selected.value])
	selected.value = new Set()
}

/**
 *
 */
function restoreSelected(): void {
	state.restoreItems(selectedRemoved.value)
	selected.value = new Set()
}
</script>

<style scoped lang="scss">
.page-grid {
	display: flex;
	flex-direction: column;
	gap: 12px;

	&__toolbar {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		align-items: center;
	}

	&__count {
		color: var(--color-text-maxcontrast);
		margin-inline-start: auto;
	}

	&__grid {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
		gap: 10px;
	}

	&__page {
		margin: 0;
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 4px;
		border: 2px solid var(--color-border);
		border-radius: var(--border-radius);
		cursor: grab;

		img {
			width: 100%;
			height: 170px;
			object-fit: contain;
			background: var(--color-background-dark);
		}

		figcaption {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 4px;
			font-size: 0.85em;
		}

		&--selected {
			border-color: var(--color-primary-element);
		}

		&--removed {
			opacity: 0.4;
			border-style: dashed;
		}
	}
}

.badge {
	padding: 1px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-primary-element-light);
}
</style>
