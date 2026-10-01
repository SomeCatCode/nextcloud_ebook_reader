<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('ebookreader', 'Large file')"
		:open="true"
		:closeOnClickOutside="false"
		:buttons="buttons"
		@update:open="(open: boolean) => !open && emit('cancel')">
		<p>
			{{ t('ebookreader', 'The file is {size} MB. It will be downloaded completely and unpacked in your browser, which can take a long time and a lot of memory. Installing 7z on the server does this on the server instead.', { size: formatMegabytes(sizeBytes) }) }}
		</p>
	</NcDialog>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { formatMegabytes } from '../../services/largeDownload.ts'

defineProps<{ sizeBytes: number }>()
const emit = defineEmits<{ confirm: [], cancel: [] }>()

const buttons = [
	{ label: t('ebookreader', 'Cancel'), variant: 'tertiary' as const, callback: (): void => { emit('cancel') } },
	{ label: t('ebookreader', 'Continue'), variant: 'primary' as const, callback: (): void => { emit('confirm') } },
]
</script>
