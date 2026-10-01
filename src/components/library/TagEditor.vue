<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div ref="root" class="tag-editor">
		<label class="tag-editor__label">{{ label }}</label>
		<div class="tag-editor__chips">
			<span v-for="item in items" :key="item" class="tag-editor__chip">
				<button
					type="button"
					class="tag-editor__name"
					:title="t('ebookreader', 'Show books with “{name}”', { name: item })"
					@click="$emit('filter', item)">
					{{ item }}
				</button>
				<button
					type="button"
					class="tag-editor__remove"
					:aria-label="t('ebookreader', 'Remove “{name}”', { name: item })"
					:title="t('ebookreader', 'Remove “{name}”', { name: item })"
					@click="$emit('remove', item)">
					<NcIconSvgWrapper :path="mdiClose" :size="14" />
				</button>
			</span>
			<button
				v-if="!adding"
				type="button"
				class="tag-editor__add"
				@click="startAdding">
				<NcIconSvgWrapper :path="mdiPlus" :size="14" />
				{{ t('ebookreader', 'Add') }}
			</button>
		</div>
		<NcSelect
			v-if="adding"
			class="tag-editor__select"
			:modelValue="null"
			:options="available"
			:inputLabel="addLabel"
			taggable
			:clearable="false"
			@update:modelValue="onPick"
			@search:blur="adding = false" />
	</div>
</template>

<script setup lang="ts">
import { mdiClose, mdiPlus } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { computed, nextTick, ref } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcSelect from '@nextcloud/vue/components/NcSelect'

const props = defineProps<{
	label: string
	addLabel: string
	items: string[]
	options: string[]
}>()

const emit = defineEmits<{
	filter: [name: string]
	remove: [name: string]
	add: [name: string]
}>()

const adding = ref(false)
const root = ref<HTMLElement | null>(null)
const available = computed(() => props.options.filter((o) => !props.items.includes(o)))

/**
 *
 */
async function startAdding(): Promise<void> {
	adding.value = true
	await nextTick()
	root.value?.querySelector<HTMLInputElement>('.tag-editor__select input')?.focus()
}

/**
 * @param value
 */
function onPick(value: string | null): void {
	const name = (value ?? '').trim()
	if (name && !props.items.includes(name)) {
		emit('add', name)
	}
	adding.value = false
}
</script>

<style scoped lang="scss">
.tag-editor {
	display: flex;
	flex-direction: column;
	gap: 4px;

	&__label {
		color: var(--color-text-maxcontrast);
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	&__chip {
		display: inline-flex;
		align-items: center;
		border-radius: var(--border-radius-pill);
		background: var(--color-background-dark);
		overflow: hidden;

		&:hover {
			background: var(--color-primary-element-light-hover);
		}
	}

	&__name,
	&__remove,
	&__add {
		border: none;
		background: none;
		cursor: pointer;
		min-height: 0;
		color: inherit;
	}

	&__name {
		padding: 2px 4px 2px 12px;
	}

	&__remove {
		display: inline-flex;
		align-items: center;
		padding: 2px 8px 2px 2px;
		opacity: 0.6;

		&:hover {
			opacity: 1;
		}
	}

	&__add {
		display: inline-flex;
		align-items: center;
		gap: 2px;
		padding: 2px 10px;
		border: 1px dashed var(--color-border-maxcontrast);
		border-radius: var(--border-radius-pill);
		color: var(--color-text-maxcontrast);
	}

	&__select {
		width: 100%;
	}
}
</style>
