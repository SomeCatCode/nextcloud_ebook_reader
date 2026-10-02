<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="optimize">
		<h3>{{ t('ebookreader', 'Optimize images') }}</h3>
		<NcNoteCard v-if="unavailable" type="info">
			{{ unavailableText }}
		</NcNoteCard>
		<template v-else>
			<p class="optimize__muted">
				{{ t('ebookreader', 'Optional: shrinks large page images, so the file gets smaller and downloads and offline use are faster. This is lossy and cannot be undone. When the original is deleted it goes to the trash (or the file versions), so you can restore it.') }}
			</p>
			<div role="radiogroup" :aria-label="t('ebookreader', 'Maximum page height')">
				<NcCheckboxRadioSwitch
					v-model="height"
					type="radio"
					:name="name + '-height'"
					value="0"
					:disabled="disabled">
					{{ t('ebookreader', 'Original (off)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="height"
					type="radio"
					:name="name + '-height'"
					value="2560"
					:disabled="disabled">
					{{ t('ebookreader', '2560 px – tablet and monitor') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="height"
					type="radio"
					:name="name + '-height'"
					value="1920"
					:disabled="disabled">
					{{ t('ebookreader', '1920 px – phone and e-ink') }}
				</NcCheckboxRadioSwitch>
			</div>
			<NcCheckboxRadioSwitch v-model="pngToJpeg" :disabled="disabled">
				{{ t('ebookreader', 'Convert PNG pages to JPEG') }}
			</NcCheckboxRadioSwitch>
			<p class="optimize__muted">
				{{ t('ebookreader', 'Pages that are already small enough are copied unchanged. PNG pages with transparency stay PNG.') }}
			</p>
			<slot />
		</template>
	</section>
</template>

<script setup lang="ts">
import type { MaxHeight, OptimizeOptions } from '../../convert/optimize.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { translateReason } from '../../convert/formats.ts'
import { parseOptions } from '../../convert/optimize.ts'

const props = withDefaults(defineProps<{
	modelValue: OptimizeOptions
	/** the server cannot optimize (no GD, or it cannot read the format) */
	unavailable?: boolean
	/** English reason from the server */
	reason?: string
	disabled?: boolean
	/** unique radio group name */
	name?: string
}>(), { unavailable: false, reason: undefined, disabled: false, name: 'optimize' })

const emit = defineEmits<{ 'update:modelValue': [value: OptimizeOptions] }>()

const height = computed<string>({
	get: () => String(props.modelValue.maxHeight),
	set: (v) => emit('update:modelValue', parseOptions({ maxHeight: Number(v) as MaxHeight, pngToJpeg: props.modelValue.pngToJpeg })),
})
const pngToJpeg = computed<boolean>({
	get: () => props.modelValue.pngToJpeg,
	set: (v) => emit('update:modelValue', { maxHeight: props.modelValue.maxHeight, pngToJpeg: v }),
})
const unavailableText = computed(() => translateReason(props.reason) || t('ebookreader', 'Image optimization is not available on this server'))
</script>

<style scoped lang="scss">
.optimize {
	display: flex;
	flex-direction: column;
	gap: 6px;

	h3 {
		margin: 0;
		font-size: 1.05em;
	}

	p {
		margin: 0;
	}

	&__muted {
		color: var(--color-text-maxcontrast);
	}
}
</style>
