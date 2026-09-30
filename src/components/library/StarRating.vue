<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span class="star-rating" role="radiogroup" :aria-label="t('ebookreader', 'Rating')">
		<button
			v-for="n in 5"
			:key="n"
			type="button"
			class="star-rating__star"
			:class="{ 'star-rating__star--on': n <= (value ?? 0), 'star-rating__star--readonly': readonly }"
			role="radio"
			:aria-checked="n === value"
			:aria-label="starLabel(n)"
			:disabled="readonly"
			@click="$emit('update', n === value ? null : n)">
			<NcIconSvgWrapper :path="n <= (value ?? 0) ? mdiStar : mdiStarOutline" :size="size" />
		</button>
	</span>
</template>

<script setup lang="ts">
import { mdiStar, mdiStarOutline } from '@mdi/js'
import { n as nPlural, t } from '@nextcloud/l10n'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

withDefaults(defineProps<{
	value: number | null
	readonly?: boolean
	size?: number
}>(), { readonly: false, size: 22 })

defineEmits<{ update: [rating: number | null] }>()

/**
 * @param count
 */
function starLabel(count: number): string {
	return nPlural('ebookreader', '%n star', '%n stars', count)
}
</script>

<style scoped lang="scss">
.star-rating {
	display: inline-flex;

	&__star {
		display: inline-flex;
		padding: 0;
		border: none;
		background: none;
		color: var(--color-text-maxcontrast);
		cursor: pointer;
		min-height: 0;

		&--on {
			color: var(--color-warning-text, #eaa200);
		}

		&--readonly {
			cursor: default;
		}
	}
}
</style>
