<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="books.length === 1 ? t('ebookreader', 'Delete book') : n('ebookreader', 'Delete %n book', 'Delete %n books', books.length)"
		:noClose="busy"
		:closeOnClickOutside="!busy"
		@update:open="(open: boolean) => !open && !busy && $emit('close')">
		<p v-if="own.length === 1 && !shared.length">
			{{ t('ebookreader', 'Delete "{title}"?', { title: label(own[0]) }) }}
		</p>
		<template v-else-if="own.length">
			<p>{{ n('ebookreader', 'Delete the selected book?', 'Delete these %n books?', own.length) }}</p>
			<ul class="delete-books__list">
				<li v-for="b in own.slice(0, 8)" :key="b.fileId">
					{{ label(b) }}
				</li>
				<li v-if="own.length > 8" class="delete-books__muted">
					{{ n('ebookreader', 'and %n more', 'and %n more', own.length - 8) }}
				</li>
			</ul>
		</template>
		<NcNoteCard v-if="shared.length" type="warning">
			<p>{{ n('ebookreader', 'This book was shared with you and is not deleted, as that would delete the file of its owner. Remove the share in the Files app instead.', 'These %n books were shared with you and are not deleted, as that would delete the files of their owners. Remove the shares in the Files app instead.', shared.length) }}</p>
			<ul class="delete-books__list">
				<li v-for="b in shared.slice(0, 8)" :key="b.fileId">
					{{ t('ebookreader', '{title} (shared by {owner})', { title: label(b), owner: b.owner }) }}
				</li>
				<li v-if="shared.length > 8" class="delete-books__muted">
					{{ n('ebookreader', 'and %n more', 'and %n more', shared.length - 8) }}
				</li>
			</ul>
		</NcNoteCard>
		<NcNoteCard v-if="own.length" type="info">
			{{ t('ebookreader', 'The files are moved to the Nextcloud trash bin and can be restored under Files → Deleted files. Reading progress, rating and app-only tags are removed.') }}
		</NcNoteCard>
		<NcNoteCard v-if="failed.length" type="error">
			{{ n('ebookreader', '%n book could not be deleted (missing permission or already gone).', '%n books could not be deleted (missing permission or already gone).', failed.length) }}
		</NcNoteCard>

		<template #actions>
			<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
				{{ own.length ? t('ebookreader', 'Cancel') : t('ebookreader', 'Close') }}
			</NcButton>
			<NcButton
				v-if="own.length"
				variant="error"
				:disabled="busy"
				@click="run">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
					<NcIconSvgWrapper v-else :path="mdiDeleteOutline" />
				</template>
				{{ t('ebookreader', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import type { Book } from '../../types.ts'

import { mdiDeleteOutline } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { splitDeletable } from '../../deletable.ts'
import { deleteBooks } from '../../services/api.ts'

const props = defineProps<{ books: Book[] }>()
const emit = defineEmits<{ close: [], deleted: [fileIds: number[]] }>()

const own = computed(() => splitDeletable(props.books).own)
const shared = computed(() => splitDeletable(props.books).shared)
const busy = ref(false)
const failed = ref<number[]>([])

/**
 * @param b
 */
function label(b: Book): string {
	return b.title || b.path.split('/').pop() || String(b.fileId)
}

/**
 * Deletes in chunks of 100 (API limit) and reports partial failures.
 */
async function run(): Promise<void> {
	busy.value = true
	failed.value = []
	const deleted: number[] = []
	try {
		const ids = own.value.map((b) => b.fileId)
		for (let i = 0; i < ids.length; i += 100) {
			const res = await deleteBooks(ids.slice(i, i + 100))
			deleted.push(...res.deleted)
			failed.value.push(...res.failed.map((f) => f.fileId))
		}
	} catch (e) {
		showError(t('ebookreader', 'Deleting failed: {message}', { message: (e as Error).message }))
	} finally {
		busy.value = false
	}
	if (deleted.length) {
		emit('deleted', deleted)
		showSuccess(n('ebookreader', '%n book moved to the trash bin', '%n books moved to the trash bin', deleted.length))
	}
	if (!failed.value.length && deleted.length) {
		emit('close')
	}
}
</script>

<style scoped>
.delete-books__list { margin: 8px 0 8px 20px; list-style: disc; }
.delete-books__muted { opacity: .7; list-style: none; }
</style>
