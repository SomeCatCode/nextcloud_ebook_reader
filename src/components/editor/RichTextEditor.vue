<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="rte">
		<div class="rte__toolbar" role="toolbar" :aria-label="t('ebookreader', 'Text formatting')">
			<NcButton
				variant="tertiary"
				:aria-label="t('ebookreader', 'Bold')"
				@mousedown.prevent
				@click="exec('bold')">
				<b>B</b>
			</NcButton>
			<NcButton
				variant="tertiary"
				:aria-label="t('ebookreader', 'Italic')"
				@mousedown.prevent
				@click="exec('italic')">
				<i>I</i>
			</NcButton>
			<NcButton
				variant="tertiary"
				:aria-label="t('ebookreader', 'Paragraph')"
				@mousedown.prevent
				@click="exec('formatBlock', 'p')">
				&para;
			</NcButton>
			<NcButton
				variant="tertiary"
				:aria-label="t('ebookreader', 'Bulleted list')"
				@mousedown.prevent
				@click="exec('insertUnorderedList')">
				&bull; {{ t('ebookreader', 'List') }}
			</NcButton>
			<NcButton
				variant="tertiary"
				:aria-label="t('ebookreader', 'Numbered list')"
				@mousedown.prevent
				@click="exec('insertOrderedList')">
				1. {{ t('ebookreader', 'List') }}
			</NcButton>
		</div>
		<div
			ref="area"
			class="rte__area"
			contenteditable="true"
			role="textbox"
			aria-multiline="true"
			:aria-label="label"
			@input="onInput"
			@paste="onPaste" />
	</div>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { sanitizeDescription } from '../../editor/sanitize.ts'

const props = defineProps<{ modelValue: string | null, label?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>()

const area = ref<HTMLDivElement | null>(null)
let lastEmitted: string | null = null

/**
 * @param html
 */
function render(html: string | null): void {
	if (area.value) {
		area.value.innerHTML = sanitizeDescription(html ?? '')
	}
}

onMounted(() => render(props.modelValue))

watch(() => props.modelValue, (v) => {
	if (v !== lastEmitted) {
		render(v)
	}
})

/**
 *
 */
function onInput(): void {
	const html = sanitizeDescription(area.value?.innerHTML ?? '')
	const value = html.trim() === '' || html === '<br>' ? null : html
	lastEmitted = value
	emit('update:modelValue', value)
}

/**
 * Paste as plain text so no foreign markup enters the editor.
 *
 * @param e
 */
function onPaste(e: ClipboardEvent): void {
	e.preventDefault()
	const text = e.clipboardData?.getData('text/plain') ?? ''
	document.execCommand('insertText', false, text)
}

/**
 * @param cmd
 * @param arg
 */
function exec(cmd: string, arg?: string): void {
	area.value?.focus()
	document.execCommand(cmd, false, arg)
	onInput()
}
</script>

<style scoped lang="scss">
.rte {
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element, 8px);

	&__toolbar {
		display: flex;
		flex-wrap: wrap;
		gap: 4px;
		padding: 4px;
		border-bottom: 1px solid var(--color-border);
	}

	&__area {
		min-height: 140px;
		max-height: 360px;
		overflow-y: auto;
		padding: 8px 12px;
		outline: none;

		:deep(ul), :deep(ol) {
			padding-inline-start: 24px;
		}
		:deep(ul) { list-style: disc; }
		:deep(ol) { list-style: decimal; }
	}

	&:focus-within {
		border-color: var(--color-main-text);
	}
}
</style>
