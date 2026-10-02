<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('ebookreader', 'Note')"
		:open="true"
		size="small"
		:buttons="buttons"
		closeOnClickOutside
		@update:open="(open: boolean) => !open && $emit('close')">
		<form class="ebr-note" @submit.prevent="save">
			<!-- the excerpt and the note are always rendered as text -->
			<blockquote v-if="quote" class="ebr-note__quote">
				{{ quote }}
			</blockquote>
			<textarea
				v-model="text"
				class="ebr-note__input"
				rows="6"
				:maxlength="MAX_NOTE"
				:placeholder="t('ebookreader', 'Write a note …')"
				:aria-label="t('ebookreader', 'Note')"
				autofocus />
			<p class="ebr-note__count">
				{{ text.length }} / {{ MAX_NOTE }}
			</p>
		</form>
	</NcDialog>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { MAX_NOTE } from '../../stores/annotations.ts'

const props = defineProps<{
	/** the highlighted text, shown above the input */
	quote?: string | null
	initial?: string | null
	/** a note may be saved empty (removes it) */
	allowEmpty?: boolean
}>()
const emit = defineEmits<{ close: [], save: [note: string] }>()

const text = ref(props.initial ?? '')
const quote = computed(() => (props.quote ?? '').length > 300 ? props.quote!.slice(0, 300) + '…' : (props.quote ?? ''))
const valid = computed(() => props.allowEmpty || text.value.trim().length > 0)

const buttons = computed(() => [
	{
		label: t('ebookreader', 'Cancel'),
		variant: 'tertiary' as const,
		callback: (): void => {
			emit('close')
		},
	},
	{
		label: t('ebookreader', 'Save'),
		variant: 'primary' as const,
		disabled: !valid.value,
		callback: (): false => {
			save()
			return false
		},
	},
])

/**
 *
 */
function save(): void {
	if (!valid.value) {
		return
	}
	emit('save', text.value.trim())
	emit('close')
}
</script>

<style scoped>
.ebr-note { display: flex; flex-direction: column; gap: 8px; }
.ebr-note__quote {
	margin: 0; padding: 4px 12px; max-height: 6em; overflow: auto; opacity: .8;
	border-inline-start: 3px solid var(--color-primary-element); white-space: pre-wrap;
}
.ebr-note__input { width: 100%; min-height: 120px; resize: vertical; }
.ebr-note__count { margin: 0; text-align: end; opacity: .6; font-size: .85em; }
</style>
