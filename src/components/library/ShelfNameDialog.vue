<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="title"
		:open="true"
		size="small"
		:buttons="buttons"
		closeOnClickOutside
		@update:open="(open: boolean) => !open && $emit('close')">
		<form class="shelf-name" @submit.prevent="submit">
			<NcTextField
				v-model="name"
				:label="t('ebookreader', 'Shelf name')"
				:maxlength="255"
				:error="error !== null"
				:helperText="error ?? ''"
				autofocus />
		</form>
	</NcDialog>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const props = defineProps<{
	title: string
	confirmLabel: string
	initial?: string
	/** Creates the shelf; throws on failure (the message is shown in the dialog) */
	onSubmit: (name: string) => Promise<void>
}>()

const emit = defineEmits<{ close: [] }>()

const name = ref(props.initial ?? '')
const busy = ref(false)
const error = ref<string | null>(null)
const valid = computed(() => name.value.trim().length > 0 && name.value.trim().length <= 255)

const buttons = computed(() => [
	{
		label: t('ebookreader', 'Cancel'),
		variant: 'tertiary' as const,
		callback: (): void => {
			emit('close')
		},
	},
	{
		label: props.confirmLabel,
		variant: 'primary' as const,
		disabled: busy.value || !valid.value,
		callback: (): false => {
			void submit()
			return false
		},
	},
])

/**
 *
 */
async function submit(): Promise<void> {
	if (!valid.value || busy.value) {
		return
	}
	busy.value = true
	error.value = null
	try {
		await props.onSubmit(name.value.trim())
		emit('close')
	} catch (e) {
		const status = (e as { status?: number }).status
		error.value = status === 409 || status === 400
			? t('ebookreader', 'A shelf with this name already exists.')
			: t('ebookreader', 'Could not save the shelf')
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.shelf-name { padding: 4px 0 8px; }
</style>
