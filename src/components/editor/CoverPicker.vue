<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="cover-picker">
		<img class="cover-picker__img" :src="previewSrc" :alt="t('ebookreader', 'Cover')">
		<div class="cover-picker__actions">
			<input
				ref="fileInput"
				type="file"
				accept="image/jpeg,image/png,image/webp"
				class="hidden-visually"
				@change="onFile">
			<NcButton :disabled="disabled" @click="fileInput?.click()">
				{{ t('ebookreader', 'Upload image…') }}
			</NcButton>
			<NcButton v-if="cover" variant="tertiary" @click="state.setCover(null)">
				{{ t('ebookreader', 'Keep current cover') }}
			</NcButton>
			<p v-if="isComic" class="cover-picker__hint">
				{{ t('ebookreader', 'To use a page as cover, choose "Use as cover" in the content tab.') }}
			</p>
			<p v-if="error" class="cover-picker__error">
				{{ error }}
			</p>
		</div>
	</div>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { useEditor } from '../../editor/useEditorState.ts'
import { coverUrl, itemUrl } from '../../services/api.ts'

const props = defineProps<{ fileId: number, etag: string, isComic: boolean, disabled?: boolean }>()

const state = useEditor()
const cover = state.cover
const fileInput = ref<HTMLInputElement | null>(null)
const uploadedPreview = ref<string | null>(null)
const error = ref('')

const previewSrc = computed(() => {
	const c = cover.value
	if (c?.source === 'upload' && uploadedPreview.value) {
		return uploadedPreview.value
	}
	if (c?.source === 'item') {
		return itemUrl(props.fileId, c.itemId)
	}
	return coverUrl(props.fileId, 'large', props.etag)
})

/**
 * @param e
 */
async function onFile(e: Event): Promise<void> {
	const input = e.target as HTMLInputElement
	const file = input.files?.[0]
	input.value = ''
	if (!file) {
		return
	}
	error.value = ''
	if (!file.type.startsWith('image/')) {
		error.value = t('ebookreader', 'Please choose an image file.')
		return
	}
	const dataUrl = await new Promise<string>((resolve, reject) => {
		const r = new FileReader()
		r.onload = () => resolve(String(r.result))
		r.onerror = () => reject(r.error)
		r.readAsDataURL(file)
	})
	uploadedPreview.value = dataUrl
	state.setCover({ source: 'upload', data: dataUrl.slice(dataUrl.indexOf(',') + 1) })
}
</script>

<style scoped lang="scss">
.cover-picker {
	display: flex;
	gap: 16px;
	align-items: flex-start;
	flex-wrap: wrap;

	&__img {
		width: 160px;
		max-height: 240px;
		object-fit: contain;
		background: var(--color-background-dark);
		border-radius: var(--border-radius);
	}

	&__actions {
		display: flex;
		flex-direction: column;
		gap: 8px;
		align-items: flex-start;
		max-width: 320px;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__error {
		color: var(--color-error-text);
	}
}
.hidden-visually {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
}
</style>
