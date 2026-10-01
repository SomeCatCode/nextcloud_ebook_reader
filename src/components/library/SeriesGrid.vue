<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="series-grid">
		<SeriesCard
			v-for="s in series"
			:key="s.name"
			:series="s"
			@click="$emit('click', $event)" />
	</div>
</template>

<script setup lang="ts">
import type { SeriesEntry } from '../../types.ts'

import SeriesCard from './SeriesCard.vue'

defineProps<{ series: SeriesEntry[] }>()

defineEmits<{ click: [series: SeriesEntry] }>()
</script>

<style scoped lang="scss">
.series-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
	gap: 8px;

	@media (min-width: 768px) {
		grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
		gap: 12px;
	}
}
</style>
