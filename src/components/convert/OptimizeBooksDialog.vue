<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('ebookreader', 'Optimize images')"
		:open="true"
		size="normal"
		:buttons="buttons"
		:closeOnClickOutside="!busy"
		@update:open="(open: boolean) => !open && !busy && $emit('close')">
		<div class="optimize-books">
			<p>{{ n('ebookreader', '%n comic selected', '%n comics selected', fileIds.length) }}</p>
			<NcNoteCard v-if="ignored > 0" type="info">
				{{ n('ebookreader', '%n selected book is not a comic and is skipped.', '%n selected books are not comics and are skipped.', ignored) }}
			</NcNoteCard>
			<OptimizeOptionsForm
				v-model="options"
				name="optimize-bulk"
				:unavailable="!available"
				:reason="reason"
				:disabled="busy" />
			<template v-if="available">
				<NcCheckboxRadioSwitch v-model="deleteOriginal" :disabled="busy">
					{{ t('ebookreader', 'Move the originals to the trash afterwards') }}
				</NcCheckboxRadioSwitch>
				<p class="optimize-books__muted">
					{{ t('ebookreader', 'The optimized comics are saved next to the originals as “Name (optimized)”. The books are processed one after another in the background. CBR files become CBZ.') }}
				</p>
			</template>
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>
	</NcDialog>
</template>

<script setup lang="ts">
import type { OptimizeOptions } from '../../convert/optimize.ts'

import { showError, showInfo, showSuccess, showWarning } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import OptimizeOptionsForm from './OptimizeOptionsForm.vue'
import { getCapabilities } from '../../convert/convertApi.ts'
import { isOptimizeActive, loadOptions, saveOptions } from '../../convert/optimize.ts'

const props = withDefaults(defineProps<{
	/** file ids of the comics to optimize */
	fileIds: number[]
	/** selected books that are not comics */
	ignored?: number
	/** Starts the tasks; resolves with the number of started tasks and the number of rejected books */
	start: (options: OptimizeOptions, deleteOriginal: boolean) => Promise<{ started: number, skipped: number }>
}>(), { ignored: 0 })
const emit = defineEmits<{
	close: []
	started: []
}>()

const DELETE_KEY = 'ebookreader.convert.deleteOriginal'
const options = ref<OptimizeOptions>(loadOptions())
const deleteOriginal = ref(readDelete())
const available = ref(true)
const reason = ref<string | undefined>(undefined)
const busy = ref(false)
const error = ref('')

const buttons = computed(() => [
	{
		label: t('ebookreader', 'Cancel'),
		variant: 'tertiary' as const,
		callback: (): void => {
			emit('close')
		},
	},
	{
		label: t('ebookreader', 'Optimize'),
		variant: 'primary' as const,
		disabled: busy.value || !available.value || !isOptimizeActive(options.value) || props.fileIds.length === 0,
		callback: (): false => {
			void run()
			return false
		},
	},
])

/**
 *
 */
function readDelete(): boolean {
	try {
		return localStorage.getItem(DELETE_KEY) === '1'
	} catch {
		return false
	}
}

/** Starts one server task per comic; progress shows in the banner of the library. */
async function run(): Promise<void> {
	busy.value = true
	error.value = ''
	try {
		const res = await props.start(options.value, deleteOriginal.value)
		saveOptions(options.value)
		if (res.started === 0) {
			error.value = t('ebookreader', 'None of the selected comics could be optimized.')
			showError(error.value)
			return
		}
		showSuccess(n('ebookreader', 'Optimizing %n comic in the background', 'Optimizing %n comics in the background', res.started))
		if (res.skipped > 0) {
			showWarning(n('ebookreader', '%n comic was skipped (name already taken or format not supported).', '%n comics were skipped (name already taken or format not supported).', res.skipped))
		} else {
			showInfo(t('ebookreader', 'You can follow the progress at the top of the library.'))
		}
		emit('started')
		emit('close')
	} catch (e) {
		error.value = e instanceof Error && e.message ? e.message : t('ebookreader', 'The optimization could not be started.')
	} finally {
		busy.value = false
	}
}

onMounted(async () => {
	try {
		const caps = await getCapabilities()
		if (caps.optimize && !caps.optimize.available) {
			available.value = false
			reason.value = 'Image optimization is not available on this server'
		}
	} catch {
		// older server without the field: let the server answer
	}
})
</script>

<style scoped lang="scss">
.optimize-books {
	display: flex;
	flex-direction: column;
	gap: 12px;

	p {
		margin: 0;
	}

	&__muted {
		color: var(--color-text-maxcontrast);
	}
}
</style>
