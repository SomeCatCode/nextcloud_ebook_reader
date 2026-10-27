<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="read-next" :aria-label="t('ebookreader', 'Finished reading')">
		<header class="read-next__head">
			<h3>{{ t('ebookreader', 'Finished reading') }}</h3>
			<NcButton
				variant="tertiary"
				size="small"
				:aria-label="t('ebookreader', 'Dismiss')"
				@click="$emit('dismiss')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiClose" :size="20" />
				</template>
			</NcButton>
		</header>

		<NcButton
			v-if="suggestions.next"
			class="read-next__continue"
			variant="primary"
			wide
			@click="$emit('read', suggestions.next)">
			<template #icon>
				<NcIconSvgWrapper :path="mdiBookArrowRightOutline" />
			</template>
			{{ t('ebookreader', 'Continue reading: {title}', { title: nextLabel }) }}
		</NcButton>

		<template v-if="suggestions.similar.length > 0">
			<h4 class="read-next__subhead">
				{{ t('ebookreader', 'Similar titles') }}
			</h4>
			<ul class="read-next__list">
				<li v-for="b in suggestions.similar" :key="b.fileId">
					<button
						type="button"
						class="read-next__item"
						:title="bookTitle(b)"
						@click="$emit('open', b)">
						<BookCover :book="b" />
						<span class="read-next__title">{{ bookTitle(b) }}</span>
					</button>
				</li>
			</ul>
		</template>
	</section>
</template>

<script setup lang="ts">
import type { Book, Suggestions } from '../../types.ts'

import { mdiBookArrowRightOutline, mdiClose } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import BookCover from './BookCover.vue'
import { volumeLabel } from './bookFlags.ts'
import { bookTitle } from './utils.ts'

const props = defineProps<{ suggestions: Suggestions }>()

defineEmits<{
	/** open the book in the reader */
	read: [book: Book]
	/** show the details of the book in the library */
	open: [book: Book]
	dismiss: []
}>()

const nextLabel = computed(() => (props.suggestions.next ? volumeLabel(props.suggestions.next, bookTitle(props.suggestions.next)) : ''))
</script>

<style scoped lang="scss">
.read-next {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);

	&__head {
		display: flex;
		align-items: center;
		justify-content: space-between;

		h3 {
			margin: 0;
		}
	}

	&__subhead {
		margin: 8px 0 0;
	}

	&__list {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
		gap: 8px;
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__item {
		display: flex;
		flex-direction: column;
		gap: 4px;
		width: 100%;
		padding: 0;
		border: 0;
		background: none;
		color: inherit;
		text-align: start;
		cursor: pointer;

		&:hover .read-next__title,
		&:focus-visible .read-next__title {
			text-decoration: underline;
		}
	}

	&__title {
		display: -webkit-box;
		-webkit-line-clamp: 2;
		line-clamp: 2;
		-webkit-box-orient: vertical;
		overflow: hidden;
		font-size: 12px;
	}
}
</style>
