<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="task-progress" role="status" aria-live="polite">
		<p class="task-progress__row">
			<NcLoadingIcon :size="20" />
			<span>{{ label }}</span>
		</p>
		<NcProgressBar :value="percent" size="medium" />
		<p v-if="hint" class="task-progress__hint">
			{{ hint }}
		</p>
	</div>
</template>

<script setup lang="ts">
import type { TaskStatus } from '../../types.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'

const props = defineProps<{
	/** 0..1 */
	progress: number
	/** step text sent by the server (English) */
	step?: string
	status?: TaskStatus
	hint?: string
}>()

const percent = computed(() => Math.max(0, Math.min(100, Math.round((props.progress || 0) * 100))))
const label = computed(() => {
	if (props.step) {
		return props.step
	}
	return props.status === 'queued' ? t('ebookreader', 'Waiting for the server…') : t('ebookreader', 'Working…')
})
</script>

<style scoped lang="scss">
.task-progress {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: min(420px, 80vw);

	p {
		margin: 0;
	}

	&__row {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	&__hint {
		opacity: 0.7;
		font-size: 0.9em;
	}
}
</style>
