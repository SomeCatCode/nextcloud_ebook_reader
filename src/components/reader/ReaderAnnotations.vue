<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ebr-annots">
		<div v-if="!total" class="ebr-annots__empty">
			<p>{{ t('ebookreader', 'No highlights, notes or bookmarks yet.') }}</p>
			<p class="ebr-annots__hint">
				{{ t('ebookreader', 'Select text in the book to highlight it or add a note. Use the bookmark button to mark a page.') }}
			</p>
		</div>
		<template v-else>
			<div class="ebr-annots__tools">
				<button type="button" class="ebr-annots__export" @click="emit('export')">
					<ReaderIcon name="download" :size="16" />
					{{ t('ebookreader', 'Export as Markdown') }}
				</button>
			</div>
			<section v-for="group in groups" :key="group.kind" class="ebr-annots__group">
				<h3 class="ebr-annots__heading">
					{{ group.label }} ({{ group.items.length }})
				</h3>
				<ul class="ebr-annots__list">
					<li v-for="a in group.items" :key="a.uuid" class="ebr-annots__item">
						<button type="button" class="ebr-annots__jump" @click="emit('jump', a)">
							<span
								class="ebr-annots__swatch"
								:class="{ 'ebr-annots__swatch--bookmark': a.type === 'bookmark' }"
								:style="a.type === 'bookmark' ? undefined : { background: colorValue(a.color) }" />
							<span class="ebr-annots__body">
								<!-- excerpt and note are plain text, never HTML -->
								<span v-if="a.text" class="ebr-annots__text" :class="{ 'ebr-annots__text--quote': a.type !== 'bookmark' }">{{ a.text }}</span>
								<span v-if="a.note" class="ebr-annots__note">{{ a.note }}</span>
								<span class="ebr-annots__pos">{{ positionLabel(a) || t('ebookreader', 'Bookmark') }}</span>
							</span>
						</button>
						<span class="ebr-annots__actions">
							<button
								v-if="a.type !== 'bookmark'"
								type="button"
								class="ebr-annots__icon"
								:title="a.note ? t('ebookreader', 'Edit note') : t('ebookreader', 'Add note')"
								:aria-label="a.note ? t('ebookreader', 'Edit note') : t('ebookreader', 'Add note')"
								@click="emit('edit', a)">
								<ReaderIcon name="edit" :size="16" />
							</button>
							<button
								type="button"
								class="ebr-annots__icon"
								:title="t('ebookreader', 'Delete')"
								:aria-label="t('ebookreader', 'Delete')"
								@click="emit('delete', a)">
								<ReaderIcon name="delete" :size="16" />
							</button>
						</span>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { Annotation } from '../../types.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'
import ReaderIcon from './ReaderIcon.vue'
import { colorValue } from '../../../packages/reader-core/src/annotations.ts'
import { positionLabel } from '../../services/annotationExport.ts'

const props = defineProps<{ highlights: Annotation[], notes: Annotation[], bookmarks: Annotation[] }>()
const emit = defineEmits<{ jump: [a: Annotation], edit: [a: Annotation], delete: [a: Annotation], export: [] }>()

const total = computed(() => props.highlights.length + props.notes.length + props.bookmarks.length)
const groups = computed(() => [
	{ kind: 'highlight', label: t('ebookreader', 'Highlights'), items: props.highlights },
	{ kind: 'note', label: t('ebookreader', 'Notes'), items: props.notes },
	{ kind: 'bookmark', label: t('ebookreader', 'Bookmarks'), items: props.bookmarks },
].filter((g) => g.items.length > 0))
</script>

<style scoped>
.ebr-annots__empty { padding: 12px; opacity: .8; }
.ebr-annots__hint { font-size: .9em; opacity: .8; }
.ebr-annots__tools { padding: 4px; }
.ebr-annots__export {
	display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; cursor: pointer; color: inherit;
	background: none; border: 1px solid var(--color-border); border-radius: var(--border-radius-element, 8px);
}
.ebr-annots__export:hover { background: var(--color-background-hover); }
.ebr-annots__heading { margin: 12px 4px 4px; font-size: 1em; font-weight: 600; }
.ebr-annots__list { list-style: none; margin: 0; padding: 0; }
.ebr-annots__item { display: flex; align-items: flex-start; gap: 2px; border-radius: var(--border-radius-element, 8px); }
.ebr-annots__item:hover { background: var(--color-background-hover); }
.ebr-annots__jump {
	flex: 1 1 auto; min-width: 0; display: flex; gap: 8px; text-align: start; padding: 8px 4px;
	background: none; border: 0; cursor: pointer; color: inherit;
}
.ebr-annots__swatch { flex: 0 0 6px; align-self: stretch; border-radius: 3px; min-height: 20px; }
.ebr-annots__swatch--bookmark { background: var(--color-primary-element); }
.ebr-annots__body { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.ebr-annots__text { overflow: hidden; display: -webkit-box; -webkit-line-clamp: 4; line-clamp: 4; -webkit-box-orient: vertical; white-space: pre-wrap; overflow-wrap: anywhere; }
.ebr-annots__text--quote { font-style: italic; }
.ebr-annots__note { white-space: pre-wrap; overflow-wrap: anywhere; font-weight: 500; }
.ebr-annots__pos { font-size: .8em; opacity: .65; }
.ebr-annots__actions { display: flex; flex: 0 0 auto; }
.ebr-annots__icon {
	display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; padding: 0;
	background: none; border: 0; cursor: pointer; color: inherit; border-radius: var(--border-radius-element, 8px); opacity: .7;
}
.ebr-annots__icon:hover { opacity: 1; background: var(--color-background-dark); }
</style>
