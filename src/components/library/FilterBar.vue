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
				<NcIconSvgWrapper :path="chip.state === 'include' ? mdiPlusCircleOutline : mdiMinusCircleOutline" :size="16" />
				<span :class="{ 'filter-bar__name--excluded': chip.state === 'exclude' }">{{ chip.label }}</span>
			</button>
			<button
				type="button"
				class="filter-bar__remove"
				:aria-label="t('ebookreader', 'Remove filter')"
				@click="store.setTermState(chip.term, null)">
				<NcIconSvgWrapper :path="mdiClose" :size="16" />
			</button>
		</span>

		<span v-if="store.filters.status" class="filter-bar__chip filter-bar__chip--neutral">
			<span class="filter-bar__toggle">{{ t('ebookreader', 'Status') }}: {{ statusLabels[store.filters.status] }}</span>
			<button
				type="button"
				class="filter-bar__remove"
				:aria-label="t('ebookreader', 'Remove filter')"
				@click="store.setStatus(null)">
				<NcIconSvgWrapper :path="mdiClose" :size="16" />
			</button>
		</span>

		<NcButton
			v-if="store.filters.include.length >= 2"
			variant="tertiary"
			:title="t('ebookreader', 'Whether books must match all or just one of the included filters')"
			@click="store.setMatch(store.filters.match === 'all' ? 'any' : 'all')">
			{{ store.filters.match === 'all' ? t('ebookreader', 'Match all') : t('ebookreader', 'Match any') }}
		</NcButton>

		<NcButton variant="tertiary" @click="store.resetFilters()">
			{{ t('ebookreader', 'Clear filters') }}
		</NcButton>
	</div>
</template>

<script setup lang="ts">
import type { FilterTerm } from '../../types.ts'

import { mdiClose, mdiMinusCircleOutline, mdiPlusCircleOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { termToString, useLibraryStore } from '../../stores/library.ts'

const store = useLibraryStore()

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
}))

const termChips = computed(() => {
	const make = (term: FilterTerm, state: 'include' | 'exclude') => ({
		key: state + ':' + termToString(term),
		term,
		state,
		label: `${typeLabels.value[term.type]}: ${term.type === 'format' ? term.name.toUpperCase() : term.name}`,
	})
	return [
		...store.filters.include.map((x) => make(x, 'include')),
		...store.filters.exclude.map((x) => make(x, 'exclude')),
	]
})
</script>

<style scoped lang="scss">
.filter-bar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;

	&__chip {
		display: inline-flex;
		align-items: center;
		border-radius: var(--border-radius-pill);

		&--include {
			background: color-mix(in srgb, var(--color-success) 25%, transparent);
		}

		&--exclude {
			background: color-mix(in srgb, var(--color-error) 25%, transparent);
		}

		&--neutral {
			background: var(--color-primary-element-light);
		}
	}

	&__toggle,
	&__remove {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		border: none;
		background: none;
		color: inherit;
		cursor: pointer;
		min-height: 0;
	}

	&__toggle {
		padding: 2px 4px 2px 10px;
	}

	&__remove {
		padding: 2px 8px 2px 2px;
	}

	&__name--excluded {
		text-decoration: line-through;
	}
}
</style>
