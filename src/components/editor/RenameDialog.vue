<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('ebookreader', 'Rename file')"
		:open="open"
		:buttons="buttons"
		@update:open="(v: boolean) => emit('update:open', v)">
		<NcTextField v-model="name" :label="t('ebookreader', 'File name')" :disabled="busy" />
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</NcDialog>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { renameBook } from '../../services/api.ts'

const props = defineProps<{ open: boolean, fileId: number, path: string | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean], renamed: [path: string] }>()

const name = ref('')
const busy = ref(false)
const error = ref('')

watch(() => props.open, (o) => {
	if (o) {
		name.value = (props.path ?? '').split('/').pop() ?? ''
		error.value = ''
	}
})

/**
 * @param req
 * @param req.name
 * @param req.usePattern
 */
async function run(req: { name?: string, usePattern?: boolean }): Promise<false> {
	busy.value = true
	error.value = ''
	try {
		const book = await renameBook(props.fileId, req)
		emit('renamed', book.path)
		emit('update:open', false)
	} catch (e) {
		error.value = (e as Error).message
	} finally {
		busy.value = false
	}
	return false
}

const buttons = computed(() => [
	{ label: t('ebookreader', 'Cancel'), variant: 'tertiary' as const, callback: () => {} },
	{ label: t('ebookreader', 'Generate from metadata'), variant: 'secondary' as const, callback: () => run({ usePattern: true }) },
	{ label: t('ebookreader', 'Rename'), variant: 'primary' as const, callback: () => name.value.trim() ? run({ name: name.value.trim() }) : false },
])
</script>
