<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="library-fields">
		<div class="library-fields__row">
			<label>{{ t('ebookreader', 'Rating') }}</label>
			<StarRating :value="book.rating" @update="setRating" />
		</div>

		<div class="library-fields__row">
			<NcSelect
				:modelValue="currentStatus"
				:options="statusOptions"
				:inputLabel="t('ebookreader', 'Reading status')"
				:clearable="false"
				:searchable="false"
				label="label"
				@update:modelValue="setStatus" />
		</div>

		<slot name="afterStatus" />

		<div class="library-fields__row">
			<NcSelect
				:modelValue="currentCompletion"
				:options="completionOptions"
				:inputLabel="t('ebookreader', 'Completion status')"
				:clearable="false"
				:searchable="false"
				label="label"
				@update:modelValue="setCompletion" />
		</div>

		<div class="library-fields__row">
			<NcSelect
				:modelValue="currentAge"
				:options="ageOptions"
				:inputLabel="t('ebookreader', 'Age rating')"
				:clearable="false"
				:searchable="false"
				label="label"
				@update:modelValue="setAge" />
			<p v-if="book.ageRatingManual" class="library-fields__hint library-fields__inline">
				<span>{{ t('ebookreader', 'Set in the app') }}</span>
				<NcButton
					variant="tertiary"
					size="small"
					:disabled="savingAge"
					@click="resetAge">
					{{ t('ebookreader', 'Use value from file') }}
				</NcButton>
			</p>
			<p v-else-if="book.ageRating !== null" class="library-fields__hint">
				{{ t('ebookreader', 'From the book file') }}
			</p>
		</div>

		<div v-if="isComicFormat" class="library-fields__row">
			<NcCheckboxRadioSwitch
				type="switch"
				:modelValue="book.longstrip === true"
				:disabled="savingLongstrip"
				@update:modelValue="setLongstrip">
				{{ t('ebookreader', 'Long strip (scroll down instead of turning pages)') }}
			</NcCheckboxRadioSwitch>
			<p v-if="book.longstripManual" class="library-fields__hint library-fields__inline">
				<span>{{ t('ebookreader', 'Set in the app') }}</span>
				<NcButton
					variant="tertiary"
					size="small"
					:disabled="savingLongstrip"
					@click="resetLongstrip">
					{{ t('ebookreader', 'Use value from file') }}
				</NcButton>
			</p>
			<p v-else-if="book.longstrip" class="library-fields__hint">
				{{ t('ebookreader', 'From the book file') }}
			</p>
		</div>
	</div>
</template>

<script setup lang="ts">
/**
 * Library data of a book that lives in the app, not in the file: rating, reading status, completion status, age
 * rating and the long strip flag. Every change is saved at once. Shared by the details sidebar and the editor, so
 * both always offer the same fields.
 */
import type { AgeRating, Book, Completion, ReadStatus } from '../../types.ts'

import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import StarRating from './StarRating.vue'
import { useLibraryStore } from '../../stores/library.ts'
import { AGE_RATINGS, ageBadge, completionLabel, LONGSTRIP_FORMATS } from './bookFlags.ts'

const props = defineProps<{ book: Book }>()

const store = useLibraryStore()

const statusOptions = computed<{ id: ReadStatus, label: string }[]>(() => [
	{ id: 'unread', label: t('ebookreader', 'Unread') },
	{ id: 'reading', label: t('ebookreader', 'Reading') },
	{ id: 'finished', label: t('ebookreader', 'Finished') },
])
const currentStatus = computed(() => statusOptions.value.find((o) => o.id === props.book.readStatus) ?? statusOptions.value[0])

const completionOptions = computed<{ id: Completion | null, label: string }[]>(() => [
	{ id: null, label: completionLabel(null) },
	{ id: 'ongoing', label: completionLabel('ongoing') },
	{ id: 'completed', label: completionLabel('completed') },
])
const currentCompletion = computed(() => completionOptions.value.find((o) => o.id === (props.book.completion ?? null)) ?? completionOptions.value[0])

const ageOptions = computed<{ id: AgeRating | null, label: string }[]>(() => [
	{ id: null, label: t('ebookreader', 'No age rating') },
	...AGE_RATINGS.map((a) => ({ id: a, label: ageBadge(a) })),
])
const currentAge = computed(() => ageOptions.value.find((o) => o.id === (props.book.ageRating ?? null)) ?? ageOptions.value[0])
const savingAge = ref(false)
const isComicFormat = computed(() => LONGSTRIP_FORMATS.includes(props.book.format))
const savingLongstrip = ref(false)

/**
 * @param rating
 */
async function setRating(rating: number | null): Promise<void> {
	try {
		await store.setRating(props.book.fileId, rating)
	} catch {
		showError(t('ebookreader', 'Could not save the rating'))
	}
}

/**
 * @param option
 * @param option.id
 */
async function setStatus(option: { id: ReadStatus } | null): Promise<void> {
	if (!option) {
		return
	}
	try {
		await store.setReadStatus(props.book.fileId, option.id)
	} catch {
		showError(t('ebookreader', 'Could not save the reading status'))
	}
}

/**
 * @param option
 * @param option.id
 */
async function setCompletion(option: { id: Completion | null } | null): Promise<void> {
	if (!option || option.id === (props.book.completion ?? null)) {
		return
	}
	try {
		await store.setCompletion(props.book.fileId, option.id)
	} catch {
		showError(t('ebookreader', 'Could not save the completion status'))
	}
}

/**
 * @param option
 * @param option.id
 */
async function setAge(option: { id: AgeRating | null } | null): Promise<void> {
	if (!option || (option.id === (props.book.ageRating ?? null) && props.book.ageRatingManual)) {
		return
	}
	savingAge.value = true
	try {
		await store.setAgeRating(props.book.fileId, option.id)
	} catch {
		showError(t('ebookreader', 'Could not save the age rating'))
	} finally {
		savingAge.value = false
	}
}

/**
 * Drops the age rating set in the app: the value from the file applies again.
 */
async function resetAge(): Promise<void> {
	savingAge.value = true
	try {
		await store.resetAgeRating(props.book.fileId)
	} catch {
		showError(t('ebookreader', 'Could not read the value from the file'))
	} finally {
		savingAge.value = false
	}
}

/**
 * @param value
 */
async function setLongstrip(value: boolean): Promise<void> {
	savingLongstrip.value = true
	try {
		await store.setLongstrip(props.book.fileId, value)
	} catch {
		showError(t('ebookreader', 'Could not save the setting'))
	} finally {
		savingLongstrip.value = false
	}
}

/**
 * Drops the long strip flag set in the app: the value recognised in the file applies again.
 */
async function resetLongstrip(): Promise<void> {
	savingLongstrip.value = true
	try {
		await store.resetLongstrip(props.book.fileId)
	} catch {
		showError(t('ebookreader', 'Could not read the value from the file'))
	} finally {
		savingLongstrip.value = false
	}
}
</script>

<style scoped lang="scss">
.library-fields {
	display: flex;
	flex-direction: column;
	gap: 14px;

	&__row {
		display: flex;
		flex-direction: column;
		gap: 4px;

		label {
			color: var(--color-text-maxcontrast);
		}
	}

	&__inline {
		display: flex;
		align-items: center;
		gap: 4px;
	}

	&__hint {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
	}
}
</style>
