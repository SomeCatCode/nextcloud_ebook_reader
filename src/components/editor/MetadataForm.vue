<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<form class="meta-form" @submit.prevent>
		<NcTextField v-model="title" :label="t('ebookreader', 'Title')" :disabled="disabled" />

		<div class="meta-form__field">
			<label :for="ids.authors">{{ t('ebookreader', 'Authors') }}</label>
			<NcSelect
				v-model="authors"
				:inputId="ids.authors"
				:options="facetAuthors"
				multiple
				taggable
				:disabled="disabled"
				:placeholder="t('ebookreader', 'Add author…')" />
		</div>

		<div class="meta-form__row">
			<NcTextField v-model="series" :label="t('ebookreader', 'Series')" :disabled="disabled" />
			<NcTextField
				v-model="seriesIndex"
				type="number"
				step="any"
				:label="t('ebookreader', 'Volume')"
				:disabled="disabled" />
		</div>

		<div class="meta-form__row">
			<NcTextField v-model="language" :label="t('ebookreader', 'Language (e.g. en, de)')" :disabled="disabled" />
			<NcTextField v-model="publisher" :label="t('ebookreader', 'Publisher')" :disabled="disabled" />
		</div>

		<div class="meta-form__row">
			<NcTextField v-model="publishedAt" :label="t('ebookreader', 'Publication date (YYYY-MM-DD)')" :disabled="disabled" />
			<NcTextField v-model="isbn" :label="t('ebookreader', 'ISBN')" :disabled="disabled" />
		</div>

		<div class="meta-form__field">
			<label>{{ t('ebookreader', 'Description') }}</label>
			<RichTextEditor v-model="description" :label="t('ebookreader', 'Description')" />
		</div>

		<div class="meta-form__field">
			<label :for="ids.genres">{{ t('ebookreader', 'Genres') }}</label>
			<NcSelect
				v-model="genres"
				:inputId="ids.genres"
				:options="facetGenres"
				multiple
				taggable
				:disabled="disabled"
				:placeholder="t('ebookreader', 'Add genre…')" />
		</div>

		<div class="meta-form__field">
			<label :for="ids.tags">{{ t('ebookreader', 'Tags') }}</label>
			<NcSelect
				v-model="tags"
				:inputId="ids.tags"
				:options="facetTags"
				multiple
				taggable
				:disabled="disabled"
				:placeholder="t('ebookreader', 'Add tag…')" />
		</div>

		<div v-if="capabilities.cover" class="meta-form__field">
			<label>{{ t('ebookreader', 'Cover') }}</label>
			<CoverPicker
				:fileId="fileId"
				:etag="etag"
				:isComic="isComic"
				:disabled="disabled" />
		</div>
	</form>
</template>

<script setup lang="ts">
import type { StructureCapabilities } from '../../types.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, onMounted, ref, useId } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import CoverPicker from './CoverPicker.vue'
import RichTextEditor from './RichTextEditor.vue'
import { useEditor } from '../../editor/useEditorState.ts'
import { getFacets } from '../../services/api.ts'

defineProps<{
	fileId: number
	etag: string
	capabilities: StructureCapabilities
	isComic: boolean
	disabled?: boolean
}>()

const { metadata } = useEditor()

const uid = useId()
const ids = { authors: `${uid}-authors`, genres: `${uid}-genres`, tags: `${uid}-tags` }

const facetAuthors = ref<string[]>([])
const facetGenres = ref<string[]>([])
const facetTags = ref<string[]>([])

onMounted(async () => {
	try {
		const f = await getFacets()
		facetAuthors.value = f.authors.map((e) => e.name)
		facetGenres.value = f.genres.map((e) => e.name)
		facetTags.value = f.tags.map((e) => e.name)
	} catch {
		// suggestions are optional
	}
})

/**
 * @param key
 */
function textField(key: 'title' | 'series' | 'language' | 'publisher' | 'publishedAt' | 'isbn') {
	return computed({
		get: () => metadata.value[key] ?? '',
		set: (v: string) => {
			metadata.value[key] = v === '' ? null : v
		},
	})
}

/**
 * @param key
 */
function listField(key: 'authors' | 'genres' | 'tags') {
	return computed({
		get: () => metadata.value[key],
		set: (v: string[] | null) => {
			metadata.value[key] = [...new Set((v ?? []).map((s) => s.trim()).filter(Boolean))]
		},
	})
}

const title = textField('title')
const series = textField('series')
const language = textField('language')
const publisher = textField('publisher')
const publishedAt = textField('publishedAt')
const isbn = textField('isbn')
const authors = listField('authors')
const genres = listField('genres')
const tags = listField('tags')

const seriesIndex = computed({
	get: () => (metadata.value.seriesIndex === null ? '' : String(metadata.value.seriesIndex)),
	set: (v: string) => {
		const n = Number.parseFloat(v)
		metadata.value.seriesIndex = v === '' || Number.isNaN(n) ? null : n
	},
})

const description = computed({
	get: () => metadata.value.description,
	set: (v: string | null) => {
		metadata.value.description = v
	},
})
</script>

<style scoped lang="scss">
.meta-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 780px;

	&__row {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
		gap: 12px;
	}

	&__field {
		display: flex;
		flex-direction: column;
		gap: 4px;

		label {
			font-weight: bold;
		}
	}
}
</style>
