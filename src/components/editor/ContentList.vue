<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="content-list">
		<div class="content-list__toolbar">
			<NcButton :disabled="disabled || selected.size === 0" variant="error" @click="deleteSelected">
				{{ t('ebookreader', 'Remove selected ({n})', { n: selected.size }) }}
			</NcButton>
			<NcButton :disabled="disabled || selectedRemoved.length === 0" @click="restoreSelected">
				{{ t('ebookreader', 'Restore selected') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="selected.size === 0" @click="selected = new Set()">
				{{ t('ebookreader', 'Clear selection') }}
			</NcButton>
			<span class="content-list__count">
				{{ t('ebookreader', '{active} of {total} chapters kept', { active: activeItems.length, total: orderedItems.length }) }}
			</span>
		</div>

		<VueDraggable
			v-model="list"
			tag="ul"
			class="content-list__list"
			handle=".drag-handle"
			:animation="150"
			:disabled="disabled">
			<li
				v-for="item in list"
				:key="item.id"
				class="content-list__item"
				:class="{ 'content-list__item--removed': removed.has(item.id) }">
				<span class="drag-handle" :title="t('ebookreader', 'Drag to reorder')" aria-hidden="true">&#8942;&#8942;</span>
				<input
					type="checkbox"
					:checked="selected.has(item.id)"
					:aria-label="t('ebookreader', 'Select {label}', { label: item.label })"
					@change="toggle(item.id)">
				<span class="content-list__label">{{ item.label || item.href }}</span>
				<span v-if="!item.linear" class="badge">{{ t('ebookreader', 'non-linear') }}</span>
				<span v-if="removed.has(item.id)" class="badge badge--removed">{{ t('ebookreader', 'removed') }}</span>
				<span class="content-list__size">{{ formatSize(item.size) }}</span>
				<NcButton
					v-if="canPreview"
					variant="tertiary"
					@click="preview = item">
					{{ t('ebookreader', 'Preview') }}
				</NcButton>
			</li>
		</VueDraggable>

		<NcDialog
			v-if="preview"
			:name="preview.label || preview.href"
			size="large"
			@update:open="preview = null">
			<!-- sandbox="" disables scripts, forms and same-origin access -->
			<iframe
				class="content-list__preview"
				sandbox=""
				:title="t('ebookreader', 'Preview')"
				:src="itemUrl(fileId, preview.id, state.structure.value?.etag)" />
		</NcDialog>
	</div>
</template>

<script setup lang="ts">
import type { StructureItem } from '../../types.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import { VueDraggable } from 'vue-draggable-plus'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { useEditor } from '../../editor/useEditorState.ts'
import { itemUrl } from '../../services/api.ts'

const props = defineProps<{ fileId: number, format: string, disabled?: boolean }>()

const state = useEditor()
const { orderedItems, activeItems, removed } = state

const selected = ref<Set<string>>(new Set())
const preview = ref<StructureItem | null>(null)
const canPreview = computed(() => props.format === 'epub')

const list = computed<StructureItem[]>({
	get: () => orderedItems.value,
	set: (items) => state.setOrder(items.map((i) => i.id)),
})

const selectedRemoved = computed(() => [...selected.value].filter((id) => removed.value.has(id)))

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

/**
 * @param bytes
 */
function formatSize(bytes: number): string {
	if (bytes < 1024) {
		return `${bytes} B`
	}
	if (bytes < 1024 * 1024) {
		return `${(bytes / 1024).toFixed(1)} KB`
	}
	return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
</script>

<style scoped lang="scss">
.content-list {
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

	&__list {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 0;
		margin: 0;
		list-style: none;
	}

	&__item {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 4px 8px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius);
		background: var(--color-main-background);

		&--removed {
			opacity: 0.55;
			background: var(--color-background-dark);

			.content-list__label {
				text-decoration: line-through;
			}
		}
	}

	&__label {
		flex: 1;
		min-width: 0;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__size {
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
	}

	&__preview {
		width: 100%;
		height: 65vh;
		border: 1px solid var(--color-border);
		background: #fff;
	}
}

.drag-handle {
	cursor: grab;
	color: var(--color-text-maxcontrast);
	user-select: none;
	letter-spacing: -3px;
}

.badge {
	font-size: 0.8em;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);

	&--removed {
		background: var(--color-error);
		color: var(--color-error-text-on-primary, #fff);
	}
}
</style>
