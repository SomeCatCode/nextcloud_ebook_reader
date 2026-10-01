<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('ebookreader', 'Edit selected books')"
		:open="true"
		size="normal"
		:buttons="buttons"
		:closeOnClickOutside="!busy"
		@update:open="(open: boolean) => !open && !busy && $emit('close')">
		<div v-if="phase === 'running'" class="bulk-edit">
			<TaskProgress
				:progress="task?.progress ?? 0"
				:step="task?.step"
				:status="task?.status"
				:hint="t('ebookreader', 'The task keeps running on the server if you close this page.')" />
		</div>

		<div v-else-if="phase === 'done' && result" class="bulk-edit">
			<NcNoteCard :type="result.failed.length ? 'warning' : 'success'">
				<p>
					{{ n('ebookreader', '%n book updated', '%n books updated', result.updated) }}<template v-if="result.unchanged > 0">
						, {{ n('ebookreader', '%n book was already up to date', '%n books were already up to date', result.unchanged) }}
					</template>
				</p>
				<p v-if="result.writeQueued">
					{{ t('ebookreader', 'The changes will be written into the files in the background.') }}
				</p>
			</NcNoteCard>
			<NcNoteCard v-if="result.failed.length" type="error">
				<p>{{ t('ebookreader', 'Some books could not be updated:') }}</p>
				<ul>
					<li v-for="f in result.failed" :key="f.fileId">
						{{ titleOf(f.fileId) }}: {{ f.error }}
					</li>
				</ul>
			</NcNoteCard>
		</div>

		<div v-else class="bulk-edit">
			<p>{{ n('ebookreader', '%n book selected', '%n books selected', count) }}</p>
			<p class="bulk-edit__hint">
				{{ t('ebookreader', 'Only the sections you tick are changed. Everything else stays as it is for each book.') }}
			</p>

			<section class="bulk-edit__section">
				<NcCheckboxRadioSwitch v-model="form.authors.change" :disabled="busy">
					{{ t('ebookreader', 'Change authors') }}
				</NcCheckboxRadioSwitch>
				<div v-if="form.authors.change" class="bulk-edit__body">
					<NcSelect
						v-model="authorsMode"
						:options="authorsModes"
						:inputLabel="t('ebookreader', 'Mode')"
						:clearable="false" />
					<NcSelect
						v-model="form.authors.values"
						:options="authorOptions"
						:inputLabel="t('ebookreader', 'Authors')"
						multiple
						taggable
						keepOpen />
				</div>
			</section>

			<section class="bulk-edit__section">
				<NcCheckboxRadioSwitch v-model="form.series.change" :disabled="busy">
					{{ t('ebookreader', 'Change series') }}
				</NcCheckboxRadioSwitch>
				<div v-if="form.series.change" class="bulk-edit__body">
					<NcCheckboxRadioSwitch
						v-model="form.series.mode"
						type="radio"
						name="bulk-series-mode"
						value="set">
						{{ t('ebookreader', 'Set series') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="form.series.mode"
						type="radio"
						name="bulk-series-mode"
						value="clear">
						{{ t('ebookreader', 'Remove from series') }}
					</NcCheckboxRadioSwitch>
					<template v-if="form.series.mode === 'set'">
						<NcSelect
							v-model="seriesName"
							:options="seriesOptions"
							:inputLabel="t('ebookreader', 'Series name')"
							taggable />
						<fieldset class="bulk-edit__numbering">
							<legend>{{ t('ebookreader', 'Volume numbering') }}</legend>
							<NcCheckboxRadioSwitch
								v-model="form.series.index"
								type="radio"
								name="bulk-series-index"
								value="keep">
								{{ t('ebookreader', 'Keep volume numbers') }}
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
								v-model="form.series.index"
								type="radio"
								name="bulk-series-index"
								value="sequence">
								{{ t('ebookreader', 'Number in the current list order') }}
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
								v-model="form.series.index"
								type="radio"
								name="bulk-series-index"
								value="sortTitle">
								{{ t('ebookreader', 'Number by title') }}
							</NcCheckboxRadioSwitch>
							<div v-if="form.series.index !== 'keep'" class="bulk-edit__row">
								<NcTextField
									v-model="startText"
									type="number"
									step="any"
									min="0"
									:label="t('ebookreader', 'Start at')"
									:error="startInvalid" />
								<NcTextField
									v-model="stepText"
									type="number"
									step="any"
									min="0"
									:label="t('ebookreader', 'Step')"
									:error="stepInvalid" />
							</div>
						</fieldset>
						<div v-if="preview.length" class="bulk-edit__preview">
							<p class="bulk-edit__hint">
								{{ t('ebookreader', 'Preview') }}
							</p>
							<ul>
								<li v-for="p in preview" :key="p.fileId">
									{{ p.title }} &rarr; {{ form.series.name.trim() || t('ebookreader', 'Series') }} #{{ p.index }}
								</li>
							</ul>
							<p v-if="previewMore > 0" class="bulk-edit__hint">
								{{ n('ebookreader', '… and %n more book', '… and %n more books', previewMore) }}
							</p>
						</div>
					</template>
				</div>
			</section>

			<section class="bulk-edit__section">
				<NcCheckboxRadioSwitch v-model="form.publisher.change" :disabled="busy">
					{{ t('ebookreader', 'Change publisher') }}
				</NcCheckboxRadioSwitch>
				<div v-if="form.publisher.change" class="bulk-edit__body">
					<NcCheckboxRadioSwitch
						v-model="form.publisher.mode"
						type="radio"
						name="bulk-publisher-mode"
						value="set">
						{{ t('ebookreader', 'Set publisher') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="form.publisher.mode"
						type="radio"
						name="bulk-publisher-mode"
						value="clear">
						{{ t('ebookreader', 'Clear publisher') }}
					</NcCheckboxRadioSwitch>
					<NcTextField v-if="form.publisher.mode === 'set'" v-model="form.publisher.value" :label="t('ebookreader', 'Publisher')" />
				</div>
			</section>

			<section class="bulk-edit__section">
				<NcCheckboxRadioSwitch v-model="form.language.change" :disabled="busy">
					{{ t('ebookreader', 'Change language') }}
				</NcCheckboxRadioSwitch>
				<div v-if="form.language.change" class="bulk-edit__body">
					<NcCheckboxRadioSwitch
						v-model="form.language.mode"
						type="radio"
						name="bulk-language-mode"
						value="set">
						{{ t('ebookreader', 'Set language') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="form.language.mode"
						type="radio"
						name="bulk-language-mode"
						value="clear">
						{{ t('ebookreader', 'Clear language') }}
					</NcCheckboxRadioSwitch>
					<NcTextField v-if="form.language.mode === 'set'" v-model="form.language.value" :label="t('ebookreader', 'Language (e.g. en, de)')" />
				</div>
			</section>

			<section class="bulk-edit__section">
				<NcCheckboxRadioSwitch v-model="form.tags.change" :disabled="busy">
					{{ t('ebookreader', 'Change genres and tags') }}
				</NcCheckboxRadioSwitch>
				<div v-if="form.tags.change" class="bulk-edit__body">
					<NcSelect
						v-model="form.tags.addGenres"
						:options="genreOptions"
						:inputLabel="t('ebookreader', 'Add genres')"
						multiple
						taggable
						keepOpen />
					<NcSelect
						v-model="form.tags.removeGenres"
						:options="genreOptions"
						:inputLabel="t('ebookreader', 'Remove genres')"
						multiple
						keepOpen />
					<NcSelect
						v-model="form.tags.addTags"
						:options="tagOptions"
						:inputLabel="t('ebookreader', 'Add tags')"
						multiple
						taggable
						keepOpen />
					<NcSelect
						v-model="form.tags.removeTags"
						:options="tagOptions"
						:inputLabel="t('ebookreader', 'Remove tags')"
						multiple
						keepOpen />
				</div>
			</section>

			<NcNoteCard v-if="visibleProblems.length" type="warning">
				<ul>
					<li v-for="p in visibleProblems" :key="p">
						{{ problemText(p) }}
					</li>
				</ul>
			</NcNoteCard>
			<NcNoteCard v-if="errorMessage" type="error">
				<p>{{ errorMessage }}</p>
			</NcNoteCard>
		</div>
	</NcDialog>
</template>

<script setup lang="ts">
import type { BulkAuthorsMode, BulkMetadataResult, Task } from '../../types.ts'
import type { BulkEditError } from './bulkEdit.ts'

import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, reactive, ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import TaskProgress from '../common/TaskProgress.vue'
import { useLibraryStore } from '../../stores/library.ts'
import { useSettingsStore } from '../../stores/settings.ts'
import { buildRequest, emptyForm, PREVIEW_LIMIT, previewNumbering, validateForm } from './bulkEdit.ts'
import { bookTitle } from './utils.ts'

const emit = defineEmits<{ close: [] }>()

const store = useLibraryStore()
const settings = useSettingsStore()

const form = reactive(emptyForm())
const phase = ref<'edit' | 'running' | 'done'>('edit')
const task = ref<Task | null>(null)
const result = ref<BulkMetadataResult | null>(null)
const errorMessage = ref('')
const busy = computed(() => phase.value === 'running')

/** frozen when the dialog opens: the selection and its order do not change while it is open */
const fileIds = store.orderedSelectedIds.slice()
const count = fileIds.length

const authorsModes = computed<{ id: BulkAuthorsMode, label: string }[]>(() => [
	{ id: 'replace', label: t('ebookreader', 'Replace authors') },
	{ id: 'add', label: t('ebookreader', 'Add authors') },
	{ id: 'remove', label: t('ebookreader', 'Remove authors') },
])
const authorsMode = computed({
	get: () => authorsModes.value.find((m) => m.id === form.authors.mode),
	set: (m: { id: BulkAuthorsMode } | null | undefined) => {
		if (m) {
			form.authors.mode = m.id
		}
	},
})
const seriesName = computed({
	get: () => form.series.name || null,
	set: (v: string | null) => {
		form.series.name = v ?? ''
	},
})
const startText = computed({
	get: () => String(form.series.start),
	set: (v: string) => {
		form.series.start = v.trim() === '' ? Number.NaN : Number(v)
	},
})
const stepText = computed({
	get: () => String(form.series.step),
	set: (v: string) => {
		form.series.step = v.trim() === '' ? Number.NaN : Number(v)
	},
})
const startInvalid = computed(() => !Number.isFinite(form.series.start) || form.series.start < 0)
const stepInvalid = computed(() => !Number.isFinite(form.series.step) || form.series.step <= 0)

const authorOptions = computed(() => store.facets.authors.map((a) => a.name))
const seriesOptions = computed(() => store.facets.series.map((s) => s.name))
const genreOptions = computed(() => [...new Set([
	...(settings.settings.genreList ?? []),
	...store.facets.genres.map((g) => g.name),
])])
const tagOptions = computed(() => store.facets.tags.map((x) => x.name))

const problems = computed(() => validateForm(form, count))
/** "nothing ticked" is not shown as a problem, the Apply button just stays disabled */
const visibleProblems = computed(() => problems.value.filter((p) => p !== 'nothing'))
const canApply = computed(() => phase.value === 'edit' && problems.value.length === 0)

const preview = computed(() => {
	if (!form.series.change || form.series.mode !== 'set' || form.series.index === 'keep' || startInvalid.value || stepInvalid.value) {
		return []
	}
	const byId = new Map(store.books.map((b) => [b.fileId, b]))
	const books = fileIds.map((id) => {
		const b = byId.get(id)
		return { fileId: id, title: b ? bookTitle(b) : t('ebookreader', 'Book {id}', { id: String(id) }) }
	})
	const numbered = previewNumbering(books, form.series.index, form.series.start, form.series.step)
	if (form.series.index === 'sortTitle') {
		numbered.sort((a, b) => (a.index ?? 0) - (b.index ?? 0))
	}
	return numbered.slice(0, PREVIEW_LIMIT)
})
const previewMore = computed(() => Math.max(0, count - preview.value.length))

const buttons = computed(() => {
	if (phase.value === 'done') {
		return [{
			label: t('ebookreader', 'Close'),
			variant: 'primary' as const,
			callback: (): void => {
				emit('close')
			},
		}]
	}
	return [
		{
			label: t('ebookreader', 'Cancel'),
			variant: 'tertiary' as const,
			disabled: busy.value,
			callback: (): void => {
				emit('close')
			},
		},
		{
			label: t('ebookreader', 'Apply'),
			variant: 'primary' as const,
			disabled: busy.value || !canApply.value,
			callback: (): false => {
				void apply()
				return false
			},
		},
	]
})

/**
 * @param fileId
 */
function titleOf(fileId: number): string {
	const b = store.books.find((x) => x.fileId === fileId)
	return b ? bookTitle(b) : t('ebookreader', 'Book {id}', { id: String(fileId) })
}

/**
 * @param error
 */
function problemText(error: BulkEditError): string {
	switch (error) {
		case 'nothing': return t('ebookreader', 'Tick at least one section to change.')
		case 'noBooks': return t('ebookreader', 'No books selected.')
		case 'tooManyBooks': return t('ebookreader', 'At most 500 books can be edited at once.')
		case 'authorsEmpty': return t('ebookreader', 'Enter at least one author.')
		case 'tooManyAuthors': return t('ebookreader', 'At most 50 authors.')
		case 'nameTooLong': return t('ebookreader', 'A name is too long (max. 512 characters).')
		case 'seriesNameEmpty': return t('ebookreader', 'Enter a series name.')
		case 'startInvalid': return t('ebookreader', 'The start number must be 0 or more.')
		case 'stepInvalid': return t('ebookreader', 'The step must be greater than 0.')
		case 'publisherEmpty': return t('ebookreader', 'Enter a publisher.')
		case 'publisherTooLong': return t('ebookreader', 'The publisher is too long (max. 255 characters).')
		case 'languageEmpty': return t('ebookreader', 'Enter a language.')
		case 'languageTooLong': return t('ebookreader', 'The language is too long (max. 32 characters).')
		case 'tagsEmpty': return t('ebookreader', 'Enter at least one genre or tag.')
	}
}

/**
 * Sends the request (only the ticked sections, in list order); large requests run as a task.
 */
async function apply(): Promise<void> {
	if (!canApply.value) {
		return
	}
	errorMessage.value = ''
	task.value = null
	phase.value = 'running'
	try {
		const res = await store.bulkMetadata(buildRequest(form, fileIds), (tk) => {
			task.value = tk
		})
		result.value = res
		if (res.failed.length === 0) {
			showSuccess(n('ebookreader', '%n book updated', '%n books updated', res.updated)
				+ (res.writeQueued ? ' – ' + t('ebookreader', 'will be written into the files in the background') : ''))
			emit('close')
			return
		}
		phase.value = 'done'
	} catch (e) {
		errorMessage.value = e instanceof Error ? e.message : String(e)
		phase.value = 'edit'
	}
}
</script>

<style scoped lang="scss">
.bulk-edit {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 320px;

	p {
		margin: 0;
	}

	&__hint {
		opacity: 0.7;
		font-size: 0.9em;
	}

	&__section {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding-bottom: 8px;
		border-bottom: 1px solid var(--color-border);
	}

	&__body {
		display: flex;
		flex-direction: column;
		gap: 8px;
		padding-inline-start: 8px;
	}

	&__numbering {
		border: none;
		padding: 0;
		margin: 0;

		legend {
			font-weight: 600;
			padding: 0;
		}
	}

	&__row {
		display: flex;
		gap: 12px;
	}

	&__preview ul {
		margin: 0;
		padding-inline-start: 20px;
		list-style: disc;
	}
}
</style>
