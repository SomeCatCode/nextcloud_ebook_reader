<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<VueDraggable
		:modelValue="nodes"
		tag="ul"
		class="toc-branch"
		group="toc"
		handle=".drag-handle"
		:animation="150"
		:disabled="disabled"
		@update:modelValue="(v: TocNode[]) => emit('update', v)">
		<li v-for="node in nodes" :key="node.id" class="toc-branch__node">
			<div class="toc-branch__row" :class="{ 'toc-branch__row--broken': broken.has(node.id) }">
				<span class="drag-handle" :title="t('ebookreader', 'Drag to move')" aria-hidden="true">&#8942;&#8942;</span>
				<input
					class="toc-branch__label"
					type="text"
					:value="node.label"
					:disabled="disabled"
					:aria-label="t('ebookreader', 'Entry title')"
					@change="(e) => state.renameToc(node.id, (e.target as HTMLInputElement).value)">
				<span class="toc-branch__target">{{ targetLabel(node) }}</span>
				<span v-if="broken.has(node.id)" class="toc-branch__warn" :title="t('ebookreader', 'Points to a removed item, will be dropped on save')">
					&#9888; {{ t('ebookreader', 'removed target') }}
				</span>
				<NcButton
					variant="tertiary"
					:disabled="disabled"
					:aria-label="t('ebookreader', 'Outdent')"
					@click="state.outdentToc(node.id)">
					&larr;
				</NcButton>
				<NcButton
					variant="tertiary"
					:disabled="disabled"
					:aria-label="t('ebookreader', 'Indent')"
					@click="state.indentToc(node.id)">
					&rarr;
				</NcButton>
				<NcButton
					variant="tertiary"
					:disabled="disabled"
					:aria-label="t('ebookreader', 'Delete entry')"
					@click="state.deleteToc(node.id)">
					&#10005;
				</NcButton>
			</div>
			<TocBranch
				:nodes="node.children"
				:disabled="disabled"
				@update="(v: TocNode[]) => updateChildren(node, v)" />
		</li>
	</VueDraggable>
</template>

<script setup lang="ts">
import type { TocNode } from '../../types.ts'

import { translate as t } from '@nextcloud/l10n'
import { VueDraggable } from 'vue-draggable-plus'
import NcButton from '@nextcloud/vue/components/NcButton'
import { useEditor } from '../../editor/useEditorState.ts'

defineProps<{ nodes: TocNode[], disabled?: boolean }>()
const emit = defineEmits<{ update: [nodes: TocNode[]] }>()

const state = useEditor()
const broken = state.brokenTocIds

/**
 * @param node
 */
function targetLabel(node: TocNode): string {
	if (node.itemId === null) {
		return ''
	}
	const item = state.structure.value?.items.find((i) => i.id === node.itemId)
	return '→ ' + (item?.label || item?.href || node.itemId)
}

/**
 * @param node
 * @param children
 */
function updateChildren(node: TocNode, children: TocNode[]): void {
	state.pushUndo()
	node.children = children
}
</script>

<style scoped lang="scss">
.toc-branch {
	list-style: none;
	margin: 0;
	padding: 0 0 0 24px;
	min-height: 8px;

	&__row {
		display: flex;
		align-items: center;
		gap: 6px;
		padding: 2px 4px;
		margin: 2px 0;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius);
		background: var(--color-main-background);

		&--broken {
			border-color: var(--color-warning);
			background: var(--color-warning-hover, var(--color-background-dark));
		}
	}

	&__label {
		flex: 1;
		min-width: 80px;
		margin: 0;
	}

	&__target {
		color: var(--color-text-maxcontrast);
		max-width: 200px;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__warn {
		color: var(--color-warning-text);
		white-space: nowrap;
	}
}

.drag-handle {
	cursor: grab;
	color: var(--color-text-maxcontrast);
	user-select: none;
	letter-spacing: -3px;
}
</style>
