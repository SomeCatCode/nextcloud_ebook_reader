<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('ebookreader', 'Edit genres and tags')"
		:open="true"
		size="normal"
		:buttons="buttons"
		closeOnClickOutside
		@update:open="(open: boolean) => !open && $emit('close')">
		<div class="bulk-tag">
			<p>{{ n('ebookreader', '%n book selected', '%n books selected', count) }}</p>

			<NcSelect
				v-model="addGenres"
				:options="genreOptions"
				:inputLabel="t('ebookreader', 'Add genres')"
				multiple
				taggable
				keepOpen />
			<NcSelect
				v-model="removeGenres"
				:options="genreOptions"
				:inputLabel="t('ebookreader', 'Remove genres')"
				multiple
				keepOpen />
			<NcSelect
				v-model="addTags"
				:options="tagOptions"
				:inputLabel="t('ebookreader', 'Add tags')"
				multiple
				taggable
				keepOpen />
			<NcSelect
				v-model="removeTags"
				:options="tagOptions"
				:inputLabel="t('ebookreader', 'Remove tags')"
				multiple
				keepOpen />

			<NcNoteCard v-if="failed.length" type="error">
				<p>{{ t('ebookreader', 'Some books could not be updated:') }}</p>
				<ul>
					<li v-for="f in failed" :key="f.fileId">
						{{ titleOf(f.fileId) }}: {{ f.error }}
					</li>
				</ul>
			</NcNoteCard>
		</div>
	</NcDialog>
</template>

<script setup lang="ts">
import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { useLibraryStore } from '../../stores/library.ts'
import { useSettingsStore } from '../../stores/settings.ts'
import { bookTitle } from './utils.ts'

const emit = defineEmits<{ close: [] }>()

const store = useLibraryStore()
const settings = useSettingsStore()

const addGenres = ref<string[]>([])
const removeGenres = ref<string[]>([])
const addTags = ref<string[]>([])
const removeTags = ref<string[]>([])
const failed = ref<{ fileId: number, error: string }[]>([])
const busy = ref(false)

const count = computed(() => store.selectedIds.length)
const genreOptions = computed(() => [...new Set([
	...(settings.settings.genreList ?? []),
	...store.facets.genres.map((g) => g.name),
])])
const tagOptions = computed(() => store.facets.tags.map((x) => x.name))

const hasChanges = computed(() => addGenres.value.length + removeGenres.value.length + addTags.value.length + removeTags.value.length > 0)

const buttons = computed(() => [
	{
		label: t('ebookreader', 'Cancel'),
		variant: 'tertiary' as const,
		callback: (): void => {
			emit('close')
		},
	},
	{
		label: t('ebookreader', 'Apply'),
		variant: 'primary' as const,
		disabled: busy.value || !hasChanges.value || count.value === 0,
		callback: (): false => {
			void apply()
			return false
		},
	},
])

/**
 * @param fileId
 */
function titleOf(fileId: number): string {
	const b = store.books.find((x) => x.fileId === fileId)
	return b ? bookTitle(b) : String(fileId)
}

/**
 *
 */
async function apply(): Promise<void> {
	busy.value = true
	failed.value = []
	try {
		const res = await store.bulkTags({
			addGenres: addGenres.value,
			removeGenres: removeGenres.value,
			addTags: addTags.value,
			removeTags: removeTags.value,
		})
		if (res.failed.length === 0) {
			showSuccess(n('ebookreader', '%n book updated', '%n books updated', res.updated))
			emit('close')
		} else {
			failed.value = res.failed
		}
	} catch (e) {
		failed.value = [{ fileId: 0, error: e instanceof Error ? e.message : String(e) }]
	} finally {
		busy.value = false
	}
}
</script>

<style scoped lang="scss">
.bulk-tag {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 320px;
}
</style>
