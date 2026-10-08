<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('ebookreader', 'Shared')"
		:open="true"
		size="large"
		closeOnClickOutside
		@update:open="(open: boolean) => !open && $emit('close')">
		<div class="sharing-overview">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<div v-if="!shares.loaded && shares.loading" class="sharing-overview__center">
				<NcLoadingIcon :size="44" />
			</div>
			<template v-else>
				<section v-for="section in sections" :key="section.key">
					<h3 class="sharing-overview__heading">
						{{ section.title }}
					</h3>
					<p v-if="section.items.length === 0" class="sharing-overview__hint">
						{{ section.empty }}
					</p>
					<ul v-else class="sharing-overview__list">
						<li v-for="item in section.items" :key="key(section.key, item)" class="sharing-overview__entry">
							<NcIconSvgWrapper :path="iconOf(item)" />
							<span class="sharing-overview__what">
								<button type="button" class="sharing-overview__open" @click="open(item)">
									{{ item.name }}
								</button>
								<span class="sharing-overview__sub">
									{{ section.key === 'outgoing'
										? t('ebookreader', 'with {name}', { name: item.recipientDisplayName })
										: t('ebookreader', 'shared by {name}', { name: item.ownerDisplayName }) }}
									<template v-if="item.type === 'shelf' || item.type === 'series'">
										· {{ n('ebookreader', '%n book', '%n books', item.bookCount) }}
									</template>
									· {{ formatDate(item.createdAt) }}
								</span>
							</span>
							<NcButton
								v-if="section.key === 'outgoing' || item.type === 'book' || item.type === 'shelf'"
								variant="tertiary"
								:disabled="busy"
								:aria-label="section.key === 'outgoing' ? t('ebookreader', 'Stop sharing') : t('ebookreader', 'Remove')"
								:title="section.key === 'outgoing' ? t('ebookreader', 'Stop sharing') : t('ebookreader', 'Remove')"
								@click="remove(section.key, item)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiClose" />
								</template>
							</NcButton>
						</li>
					</ul>
				</section>
				<p class="sharing-overview__hint">
					{{ t('ebookreader', 'Only shares made in the e-book library are listed. Shared books are regular read-only Nextcloud shares and also appear in Files.') }}
				</p>
			</template>
		</div>
	</NcDialog>
</template>

<script setup lang="ts">
import type { Share } from '../../types.ts'

import { mdiBookMultipleOutline, mdiBookOutline, mdiBookshelf, mdiClose, mdiFolderOutline } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { useLibraryStore } from '../../stores/library.ts'
import { shareTargetId, useSharesStore } from '../../stores/shares.ts'
import { useShelvesStore } from '../../stores/shelves.ts'

const emit = defineEmits<{ close: [] }>()

const shares = useSharesStore()
const shelves = useShelvesStore()
const library = useLibraryStore()
const router = useRouter()
const busy = ref(false)
const error = ref('')

const sections = computed(() => [
	{ key: 'outgoing' as const, title: t('ebookreader', 'Shared by me'), empty: t('ebookreader', 'You have not shared any books, shelves, series or folders yet.'), items: shares.outgoing },
	{ key: 'incoming' as const, title: t('ebookreader', 'Shared with me'), empty: t('ebookreader', 'Nothing has been shared with you yet.'), items: shares.incoming },
])

onMounted(async () => {
	try {
		await shares.load()
	} catch {
		error.value = t('ebookreader', 'Could not load the shares')
	}
})

/**
 * @param section
 * @param item
 */
function key(section: string, item: Share): string {
	return [section, item.type, shareTargetId(item), item.owner, item.recipient].join('|')
}

/**
 * @param item
 */
function iconOf(item: Share): string {
	switch (item.type) {
		case 'shelf': return mdiBookshelf
		case 'series': return mdiBookMultipleOutline
		case 'folder': return mdiFolderOutline
		default: return mdiBookOutline
	}
}

/**
 * @param ms
 */
function formatDate(ms: number): string {
	return new Date(ms).toLocaleDateString()
}

/**
 * Shows the book or shelf in the library.
 *
 * @param item
 */
function open(item: Share): void {
	if (item.type === 'shelf' && item.shelfId !== null) {
		const shelf = shelves.byId(item.shelfId)
		if (shelf) {
			library.viewShelf(shelf)
			emit('close')
		}
		return
	}
	if (item.type === 'series' && item.series) {
		library.showView('series')
		library.openSeries(item.series)
		emit('close')
		return
	}
	if (item.type === 'folder' && item.path) {
		library.showView('folders')
		library.openFolder(item.path)
		emit('close')
		return
	}
	const fileId = item.fileId
	if (fileId !== null) {
		if ([...library.books, ...library.recent].some((b) => b.fileId === fileId)) {
			library.setActive(fileId)
		} else {
			void router.push(`/read/${fileId}`)
		}
		emit('close')
	}
}

/**
 * @param section
 * @param item
 */
async function remove(section: 'outgoing' | 'incoming', item: Share): Promise<void> {
	busy.value = true
	try {
		if (section === 'outgoing') {
			const id = shareTargetId(item)
			if (id !== null) {
				await shares.unshare({ type: item.type, id }, item.recipient)
			}
		} else {
			await shares.leave(item)
			void library.reload()
		}
	} catch {
		showError(t('ebookreader', 'Could not remove the share'))
	} finally {
		busy.value = false
	}
}
</script>

<style scoped lang="scss">
.sharing-overview {
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-height: 280px;
	padding-bottom: 12px;

	&__center {
		display: flex;
		justify-content: center;
		padding: 32px 0;
	}

	&__heading {
		margin: 8px 0 4px;
		font-size: 1.1em;
	}

	&__hint {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__list {
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__entry {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 4px 0;
	}

	&__what {
		display: flex;
		flex: 1 1 auto;
		flex-direction: column;
		min-width: 0;
	}

	&__open {
		all: unset;
		cursor: pointer;
		font-weight: bold;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;

		&:hover,
		&:focus-visible {
			text-decoration: underline;
		}
	}

	&__sub {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}
}
</style>
