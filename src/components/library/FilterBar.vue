<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="store.hasFilters" class="filter-bar">
		<span
			v-for="chip in termChips"
			:key="chip.key"
			class="filter-bar__chip"
			:class="chip.state === 'include' ? 'filter-bar__chip--include' : 'filter-bar__chip--exclude'">
			<button
				type="button"
				class="filter-bar__toggle"
				:title="t('ebookreader', 'Click to switch between include and exclude')"
				@click="store.setTermState(chip.term, chip.state === 'include' ? 'exclude' : 'include')">
				<NcIconSvgWrapper :path="chip.state === 'include' ? mdiPlusCircleOutline : mdiMinusCircleOutline" :size="16" inline />
				<span :class="{ 'filter-bar__name--excluded': chip.state === 'exclude' }">{{ chip.label }}</span>
			</button>
			<button
				type="button"
				class="filter-bar__remove"
				:aria-label="t('ebookreader', 'Remove filter')"
				@click="store.setTermState(chip.term, null)">
				<NcIconSvgWrapper :path="mdiClose" :size="14" inline />
			</button>
		</span>

		<span v-if="store.filters.status" class="filter-bar__chip filter-bar__chip--neutral">
			<span class="filter-bar__toggle">{{ t('ebookreader', 'Status') }}: {{ statusLabels[store.filters.status] }}</span>
			<button
				type="button"
				class="filter-bar__remove"
				:aria-label="t('ebookreader', 'Remove filter')"
				@click="store.setStatus(null)">
				<NcIconSvgWrapper :path="mdiClose" :size="14" inline />
			</button>
		</span>

		<NcButton
			v-if="store.filters.include.length >= 2"
			size="small"
			variant="tertiary"
			:title="t('ebookreader', 'Whether books must match all or just one of the included filters')"
			@click="store.setMatch(store.filters.match === 'all' ? 'any' : 'all')">
			{{ store.filters.match === 'all' ? t('ebookreader', 'Match all') : t('ebookreader', 'Match any') }}
		</NcButton>

		<NcButton size="small" variant="tertiary" @click="store.resetFilters()">
			{{ t('ebookreader', 'Clear filters') }}
		</NcButton>

		<NcButton
			v-if="store.smartShelfDirty"
			variant="secondary"
			:disabled="updating"
			@click="updateShelf">
			<template #icon>
				<NcIconSvgWrapper :path="mdiContentSaveOutline" />
			</template>
			{{ t('ebookreader', 'Update shelf') }}
		</NcButton>
		<NcButton
			v-if="canSaveShelf"
			variant="tertiary"
			@click="showSave = true">
			<template #icon>
				<NcIconSvgWrapper :path="mdiFilterPlusOutline" />
			</template>
			{{ t('ebookreader', 'Save as smart shelf…') }}
		</NcButton>
	</div>

	<ShelfNameDialog
		v-if="showSave"
		:title="t('ebookreader', 'Save as smart shelf')"
		:confirmLabel="t('ebookreader', 'Save')"
		:onSubmit="saveShelf"
		@close="showSave = false" />
</template>

<script setup lang="ts">
import type { FilterTerm } from '../../types.ts'

import { mdiClose, mdiContentSaveOutline, mdiFilterPlusOutline, mdiMinusCircleOutline, mdiPlusCircleOutline } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import ShelfNameDialog from './ShelfNameDialog.vue'
import { displayTermName } from '../../services/hierarchy.ts'
import { termToString, useLibraryStore } from '../../stores/library.ts'
import { useShelvesStore } from '../../stores/shelves.ts'

const store = useLibraryStore()
const shelves = useShelvesStore()
const showSave = ref(false)
const updating = ref(false)

/** a smart shelf needs real filters, a manual shelf view is no filter to save */
const canSaveShelf = computed(() => store.filters.include.some((x) => x.type !== 'shelf')
	|| store.filters.exclude.length > 0
	|| store.filters.status !== null
	|| store.filters.search.trim() !== '')

/**
 * @param name
 */
async function saveShelf(name: string): Promise<void> {
	const shelf = await shelves.create(name, 'smart', store.currentSmartQuery())
	store.adoptSmartShelf(shelf.id)
	showSuccess(t('ebookreader', 'Smart shelf “{name}” saved', { name }))
}

/**
 *
 */
async function updateShelf(): Promise<void> {
	const id = store.smartShelfId
	if (id === null) {
		return
	}
	updating.value = true
	try {
		await shelves.updateQuery(id, store.currentSmartQuery())
		showSuccess(t('ebookreader', 'Shelf updated'))
	} catch {
		showError(t('ebookreader', 'Could not update the shelf'))
	} finally {
		updating.value = false
	}
}

const statusLabels = computed<Record<string, string>>(() => ({
	unread: t('ebookreader', 'Unread'),
	reading: t('ebookreader', 'Reading'),
	finished: t('ebookreader', 'Finished'),
}))

const typeLabels = computed<Record<string, string>>(() => ({
	genre: t('ebookreader', 'Genre'),
	tag: t('ebookreader', 'Tag'),
	author: t('ebookreader', 'Author'),
	series: t('ebookreader', 'Series'),
	format: t('ebookreader', 'Format'),
	shelf: t('ebookreader', 'Shelf'),
}))

/**
 * @param term
 */
function termLabel(term: FilterTerm): string {
	if (term.type === 'format') {
		return term.name.toUpperCase()
	}
	if (term.type === 'shelf') {
		return shelves.byId(Number.parseInt(term.name, 10))?.name ?? term.name
	}
	// "Fantasy/*" is shown as "Fantasy (+ sub)"
	return displayTermName(term.name, t('ebookreader', '+ sub'))
}

const termChips = computed(() => {
	const make = (term: FilterTerm, state: 'include' | 'exclude') => ({
		key: state + ':' + termToString(term),
		term,
		state,
		label: `${typeLabels.value[term.type]}: ${termLabel(term)}`,
	})
	return [
		...store.filters.include.map((x) => make(x, 'include')),
		...store.filters.exclude.map((x) => make(x, 'exclude')),
	]
})
</script>

<style scoped lang="scss">
$chip-height: 28px;

.filter-bar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px 8px;

	&__chip {
		display: inline-flex;
		align-items: center;
		height: $chip-height;
		max-width: 100%;
		border-radius: var(--border-radius-pill);

		&--include {
			background: color-mix(in srgb, var(--color-success) 22%, transparent);
		}

		&--exclude {
			background: color-mix(in srgb, var(--color-error) 22%, transparent);
		}

		&--neutral {
			background: var(--color-primary-element-light);
			color: var(--color-primary-element-light-text);
		}
	}

	// Reset Nextcloud's global <button> styles (min-height, margin, bold font, padding, border)
	&__toggle,
	&__remove {
		display: inline-flex;
		align-items: center;
		margin: 0;
		min-height: 0;
		height: $chip-height;
		border: none;
		background: transparent;
		color: inherit;
		font: inherit;
		font-weight: normal;
		line-height: $chip-height;
		cursor: pointer;
	}

	&__toggle {
		gap: 6px;
		padding: 0 4px 0 10px;
		overflow: hidden;
		white-space: nowrap;
		text-overflow: ellipsis;
		border-radius: var(--border-radius-pill) 0 0 var(--border-radius-pill);
	}

	span.filter-bar__toggle {
		cursor: default;
	}

	&__remove {
		justify-content: center;
		flex: 0 0 auto;
		width: 22px;
		height: 22px;
		margin-inline-end: 3px;
		padding: 0;
		border-radius: 50%;
		opacity: .6;

		&:hover,
		&:focus-visible {
			opacity: 1;
			background: var(--color-background-hover);
		}
	}

	&__toggle:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 1px;
	}

	&__name--excluded {
		text-decoration: line-through;
	}
}
</style>
