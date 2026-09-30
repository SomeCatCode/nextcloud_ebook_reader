<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="toc-editor">
		<NcNoteCard v-if="brokenTocIds.size > 0" type="warning">
			{{ t('ebookreader', '{n} entries point to removed items and will be dropped on save.', { n: brokenTocIds.size }) }}
		</NcNoteCard>

		<div class="toc-editor__add">
			<NcTextField v-model="newLabel" :label="t('ebookreader', 'New entry title')" :disabled="disabled" />
			<NcSelect
				v-model="newTarget"
				:options="targetOptions"
				label="label"
				:clearable="false"
				:disabled="disabled"
				:placeholder="t('ebookreader', 'Target')"
				:aria-label-combobox="t('ebookreader', 'Target')" />
			<NcButton variant="primary" :disabled="disabled || !newLabel.trim() || !newTarget" @click="add">
				{{ t('ebookreader', 'Add entry') }}
			</NcButton>
		</div>

		<p v-if="toc.length === 0" class="toc-editor__empty">
			{{ t('ebookreader', 'This book has no table of contents.') }}
		</p>
		<TocBranch :nodes="toc" :disabled="disabled" @update="onRootUpdate" />
	</div>
</template>

<script setup lang="ts">
import type { TocNode } from '../../types.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import TocBranch from './TocBranch.vue'
import { useEditor } from '../../editor/useEditorState.ts'

defineProps<{ disabled?: boolean }>()

const state = useEditor()
const { toc, brokenTocIds, activeItems } = state

const newLabel = ref('')
const newTarget = ref<{ id: string, label: string } | null>(null)

const targetOptions = computed(() => activeItems.value.map((i) => ({ id: i.id, label: i.label || i.href })))

/**
 *
 */
function add(): void {
	if (!newTarget.value) {
		return
	}
	state.addTocEntry(newLabel.value.trim(), newTarget.value.id)
	newLabel.value = ''
	newTarget.value = null
}

/**
 * @param nodes
 */
function onRootUpdate(nodes: TocNode[]): void {
	state.setToc(nodes)
}
</script>

<style scoped lang="scss">
.toc-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;

	&__add {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		align-items: end;

		> * {
			flex: 1 1 220px;
		}
		> button {
			flex: 0 0 auto;
		}
	}

	&__empty {
		color: var(--color-text-maxcontrast);
	}
}
</style>
