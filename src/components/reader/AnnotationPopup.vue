<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		class="ebr-pop"
		role="toolbar"
		:aria-label="t('ebookreader', 'Highlight')"
		:style="{ left: `${left}px`, top: `${top}px` }"
		@pointerdown.stop
		@click.stop>
		<button
			v-for="c in ANNOTATION_COLOR_NAMES"
			:key="c"
			type="button"
			class="ebr-pop__color"
			:class="{ 'ebr-pop__color--active': c === color }"
			:style="{ background: ANNOTATION_COLORS[c] }"
			:title="colorLabel(c)"
			:aria-label="colorLabel(c)"
			:aria-pressed="c === color"
			@click="emit('color', c)" />
		<span class="ebr-pop__sep" />
		<button
			type="button"
			class="ebr-pop__btn"
			:title="noteLabel"
			:aria-label="noteLabel"
			@click="emit('note')">
			<ReaderIcon name="notes" :size="18" />
		</button>
		<button
			v-if="mode === 'selection'"
			type="button"
			class="ebr-pop__btn"
			:title="t('ebookreader', 'Copy')"
			:aria-label="t('ebookreader', 'Copy')"
			@click="emit('copy')">
			<ReaderIcon name="copy" :size="18" />
		</button>
		<button
			v-else
			type="button"
			class="ebr-pop__btn"
			:title="t('ebookreader', 'Delete')"
			:aria-label="t('ebookreader', 'Delete')"
			@click="emit('delete')">
			<ReaderIcon name="delete" :size="18" />
		</button>
	</div>
</template>

<script setup lang="ts">
import type { AnnotationColor } from '../../types.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'
import ReaderIcon from './ReaderIcon.vue'
import { ANNOTATION_COLOR_NAMES, ANNOTATION_COLORS } from '../../../packages/reader-core/src/annotations.ts'

const props = defineProps<{
	/** `selection`: new highlight from selected text, `edit`: an existing one */
	mode: 'selection' | 'edit'
	/** anchor rectangle relative to the stage */
	rect: { left: number, top: number, right: number, bottom: number }
	/** size of the stage, keeps the popup inside */
	bounds: { width: number, height: number }
	/** current color in edit mode */
	color?: AnnotationColor | null
	hasNote?: boolean
}>()

const emit = defineEmits<{ color: [color: AnnotationColor], note: [], copy: [], delete: [] }>()

/** keep in sync with the width/height in the style block */
const POPUP_WIDTH = 252
const POPUP_HEIGHT = 44

const noteLabel = computed(() => props.hasNote ? t('ebookreader', 'Edit note') : t('ebookreader', 'Add note'))
const left = computed(() => {
	const center = (props.rect.left + props.rect.right) / 2
	return Math.round(Math.max(4, Math.min(props.bounds.width - POPUP_WIDTH - 4, center - POPUP_WIDTH / 2)))
})
const top = computed(() => {
	const above = props.rect.top - POPUP_HEIGHT - 8
	const y = above >= 4 ? above : props.rect.bottom + 8
	return Math.round(Math.max(4, Math.min(props.bounds.height - POPUP_HEIGHT - 4, y)))
})

/**
 * @param c
 */
function colorLabel(c: AnnotationColor): string {
	switch (c) {
		case 'yellow': return t('ebookreader', 'Yellow')
		case 'green': return t('ebookreader', 'Green')
		case 'blue': return t('ebookreader', 'Blue')
		case 'pink': return t('ebookreader', 'Pink')
		default: return t('ebookreader', 'Purple')
	}
}
</script>

<style scoped>
.ebr-pop {
	position: absolute; z-index: 5; box-sizing: border-box; width: 252px; height: 44px;
	display: flex; align-items: center; gap: 6px; padding: 0 8px;
	background: var(--color-main-background); color: var(--color-main-text);
	border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 12px);
	box-shadow: 0 2px 12px rgba(0, 0, 0, .3);
}
.ebr-pop__color {
	width: 24px; height: 24px; padding: 0; cursor: pointer; border-radius: 50%;
	border: 2px solid transparent; box-shadow: inset 0 0 0 1px rgba(0, 0, 0, .25);
}
.ebr-pop__color--active { border-color: var(--color-main-text); }
.ebr-pop__sep { width: 1px; height: 22px; background: var(--color-border); margin: 0 2px; }
.ebr-pop__btn {
	display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; padding: 0;
	background: none; border: 0; cursor: pointer; color: inherit; border-radius: var(--border-radius-element, 8px);
}
.ebr-pop__btn:hover { background: var(--color-background-hover); }
</style>
