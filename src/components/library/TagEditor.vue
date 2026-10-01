<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div ref="root" class="tag-editor">
		<span class="tag-editor__label">{{ label }}</span>
		<ul class="tag-editor__chips" :aria-label="label">
			<li v-for="item in items" :key="item" class="tag-editor__chip">
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
					<NcIconSvgWrapper :path="mdiClose" :size="14" inline />
				</button>
			</li>
			<li v-if="!adding">
				<button type="button" class="tag-editor__add" @click="startAdding">
					<NcIconSvgWrapper :path="mdiPlus" :size="16" inline />
					<span>{{ t('ebookreader', 'Add') }}</span>
				</button>
			</li>
		</ul>
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
$chip-height: 28px;

.tag-editor {
	display: flex;
	flex-direction: column;
	gap: 6px;

	&__label {
		color: var(--color-text-maxcontrast);
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
		margin: 0;
		padding: 0;
		list-style: none;
	}

	// Reset Nextcloud's global <button> styles (min-height, margin, bold font, padding, border)
	&__name,
	&__remove,
	&__add {
		margin: 0;
		min-height: 0;
		height: $chip-height;
		border: none;
		background: transparent;
		color: inherit;
		font: inherit;
		font-weight: normal;
		line-height: $chip-height;
		cursor: pointer;
	}

	&__chip {
		display: inline-flex;
		align-items: center;
		height: $chip-height;
		max-width: 100%;
		border-radius: var(--border-radius-pill);
		background: var(--color-background-dark);
		transition: background-color var(--animation-quick);

		&:hover,
		&:focus-within {
			background: var(--color-primary-element-light);
			color: var(--color-primary-element-light-text);
		}
	}

	&__name {
		padding: 0 4px 0 12px;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		border-radius: var(--border-radius-pill) 0 0 var(--border-radius-pill);
	}

	&__remove {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		flex: 0 0 auto;
		width: 22px;
		height: 22px;
		margin-inline-end: 3px;
		padding: 0;
		border-radius: 50%;
		opacity: .55;

		&:hover,
		&:focus-visible {
			opacity: 1;
			background: var(--color-background-hover);
		}
	}

	&__add {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 0 12px 0 8px;
		height: $chip-height;
		border: 1px dashed var(--color-border-maxcontrast);
		border-radius: var(--border-radius-pill);
		color: var(--color-text-maxcontrast);

		&:hover,
		&:focus-visible {
			border-style: solid;
			color: var(--color-main-text);
			background: var(--color-background-hover);
		}
	}

	&__name:focus-visible,
	&__add:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 1px;
	}

	&__select {
		width: 100%;
	}
}
</style>
