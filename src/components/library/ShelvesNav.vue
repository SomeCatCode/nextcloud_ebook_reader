<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppNavigationCaption :name="t('ebookreader', 'Shelves')">
		<template #actions>
			<NcActionButton @click="showNew = true">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" />
				</template>
				{{ t('ebookreader', 'New shelf') }}
			</NcActionButton>
		</template>
	</NcAppNavigationCaption>

	<NcAppNavigationItem
		v-for="(shelf, index) in shelves.own"
		:key="shelf.id"
		:name="shelf.name"
		:active="isActive(shelf)"
		:editable="true"
		:editLabel="t('ebookreader', 'Rename')"
		:editPlaceholder="t('ebookreader', 'Shelf name')"
		@click="library.viewShelf(shelf)"
		@update:name="(name: string) => rename(shelf, name)">
		<template #icon>
			<NcIconSvgWrapper :path="shelf.type === 'smart' ? mdiFilterVariant : mdiBookshelf" />
		</template>
		<template #counter>
			<NcIconSvgWrapper
				v-if="(shelf.shareCount ?? 0) > 0"
				class="shelves-nav__shared"
				:path="mdiShareVariant"
				:size="16"
				:title="n('ebookreader', 'Shared with %n user', 'Shared with %n users', shelf.shareCount ?? 0)" />
			<NcCounterBubble :count="shelf.count" />
		</template>
		<template #actions>
			<NcActionButton @click="sharing = { type: 'shelf', id: shelf.id, name: shelf.name }">
				<template #icon>
					<NcIconSvgWrapper :path="mdiShareVariant" />
				</template>
				{{ t('ebookreader', 'Share…') }}
			</NcActionButton>
			<NcActionButton :disabled="index === 0" @click="move(shelf, -1)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiArrowUp" />
				</template>
				{{ t('ebookreader', 'Move up') }}
			</NcActionButton>
			<NcActionButton :disabled="index === shelves.own.length - 1" @click="move(shelf, 1)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiArrowDown" />
				</template>
				{{ t('ebookreader', 'Move down') }}
			</NcActionButton>
			<NcActionButton @click="deleting = shelf">
				<template #icon>
					<NcIconSvgWrapper :path="mdiDeleteOutline" />
				</template>
				{{ t('ebookreader', 'Delete shelf…') }}
			</NcActionButton>
		</template>
	</NcAppNavigationItem>
	<NcAppNavigationItem
		v-for="shelf in shelves.incoming"
		:key="`in-${shelf.id}`"
		:name="shelf.name"
		:title="t('ebookreader', 'Shared by {name} (read-only)', { name: shelf.ownerDisplayName ?? shelf.owner ?? '' })"
		:active="library.activeManualShelfId === shelf.id"
		@click="library.viewShelf(shelf)">
		<template #icon>
			<NcIconSvgWrapper :path="mdiBookshelf" />
		</template>
		<template #counter>
			<NcIconSvgWrapper
				class="shelves-nav__shared"
				:path="mdiAccountArrowLeftOutline"
				:size="16"
				:title="t('ebookreader', 'Shared by {name}', { name: shelf.ownerDisplayName ?? shelf.owner ?? '' })" />
			<NcCounterBubble :count="shelf.count" />
		</template>
		<template #actions>
			<NcActionText>
				<template #icon>
					<NcIconSvgWrapper :path="mdiAccountArrowLeftOutline" />
				</template>
				{{ t('ebookreader', 'Shared by {name}', { name: shelf.ownerDisplayName ?? shelf.owner ?? '' }) }}
			</NcActionText>
			<NcActionButton @click="leave(shelf)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiClose" />
				</template>
				{{ t('ebookreader', 'Remove shared shelf') }}
			</NcActionButton>
		</template>
	</NcAppNavigationItem>
	<NcAppNavigationItem
		v-if="shelves.loaded && shelves.shelves.length === 0"
		:name="t('ebookreader', 'No shelves yet')"
		class="shelves-nav__empty" />

	<ShareDialog v-if="sharing" :target="sharing" @close="sharing = null" />

	<ShelfNameDialog
		v-if="showNew"
		:title="t('ebookreader', 'New shelf')"
		:confirmLabel="t('ebookreader', 'Create')"
		:onSubmit="create"
		@close="showNew = false" />

	<NcDialog
		v-if="deleting"
		:name="t('ebookreader', 'Delete shelf')"
		:message="t('ebookreader', 'Delete the shelf “{name}”? The books stay in your library.', { name: deleting.name })"
		:open="true"
		size="small"
		:buttons="deleteButtons"
		@update:open="(open: boolean) => !open && (deleting = null)" />
</template>

<script setup lang="ts">
import type { ShareTarget } from '../../stores/shares.ts'
import type { Shelf } from '../../types.ts'

import { mdiAccountArrowLeftOutline, mdiArrowDown, mdiArrowUp, mdiBookshelf, mdiClose, mdiDeleteOutline, mdiFilterVariant, mdiPlus, mdiShareVariant } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionText from '@nextcloud/vue/components/NcActionText'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import ShareDialog from './ShareDialog.vue'
import ShelfNameDialog from './ShelfNameDialog.vue'
import { useLibraryStore } from '../../stores/library.ts'
import { useSharesStore } from '../../stores/shares.ts'
import { useShelvesStore } from '../../stores/shelves.ts'

const library = useLibraryStore()
const shelves = useShelvesStore()
const shares = useSharesStore()
const showNew = ref(false)
const deleting = ref<Shelf | null>(null)
/** shelf in the share dialog */
const sharing = ref<ShareTarget | null>(null)

const deleteButtons = computed(() => [
	{
		label: t('ebookreader', 'Cancel'),
		variant: 'tertiary' as const,
		callback: (): void => {
			deleting.value = null
		},
	},
	{
		label: t('ebookreader', 'Delete'),
		variant: 'error' as const,
		callback: (): void => {
			void confirmDelete()
		},
	},
])

/**
 * @param shelf
 */
function isActive(shelf: Shelf): boolean {
	return shelf.type === 'smart'
		? library.smartShelfId === shelf.id
		: library.activeManualShelfId === shelf.id
}

/**
 * @param name
 */
async function create(name: string): Promise<void> {
	const shelf = await shelves.create(name, 'manual')
	library.viewShelf(shelf)
}

/**
 * @param shelf
 * @param name
 */
async function rename(shelf: Shelf, name: string): Promise<void> {
	const trimmed = name.trim()
	if (!trimmed || trimmed === shelf.name) {
		return
	}
	try {
		await shelves.rename(shelf.id, trimmed)
	} catch {
		showError(t('ebookreader', 'Could not rename the shelf (is the name already in use?)'))
	}
}

/**
 * @param shelf
 * @param delta
 */
async function move(shelf: Shelf, delta: -1 | 1): Promise<void> {
	try {
		await shelves.move(shelf.id, delta)
	} catch {
		showError(t('ebookreader', 'Could not reorder the shelves'))
	}
}

/**
 * Removes a shelf another user shares with the current user.
 *
 * @param shelf
 */
async function leave(shelf: Shelf): Promise<void> {
	try {
		const viewing = library.activeManualShelfId === shelf.id
		await shares.leave({
			type: 'shelf',
			fileId: null,
			shelfId: shelf.id,
			name: shelf.name,
			owner: shelf.owner ?? '',
			ownerDisplayName: shelf.ownerDisplayName ?? '',
			recipient: '',
			recipientDisplayName: '',
			createdAt: shelf.createdAt,
			bookCount: shelf.count,
		})
		if (viewing) {
			library.resetFilters()
		}
	} catch {
		showError(t('ebookreader', 'Could not remove the shared shelf'))
	}
}

/**
 *
 */
async function confirmDelete(): Promise<void> {
	const shelf = deleting.value
	deleting.value = null
	if (!shelf) {
		return
	}
	try {
		const viewing = isActive(shelf)
		await shelves.remove(shelf.id)
		if (viewing) {
			library.resetFilters()
		}
	} catch {
		showError(t('ebookreader', 'Could not delete the shelf'))
	}
}
</script>

<style scoped>
.shelves-nav__empty { opacity: .7; }
.shelves-nav__shared { color: var(--color-text-maxcontrast); }
</style>
