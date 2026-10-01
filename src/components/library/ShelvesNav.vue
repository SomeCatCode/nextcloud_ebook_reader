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
		v-for="(shelf, index) in shelves.shelves"
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
			<NcCounterBubble :count="shelf.count" />
		</template>
		<template #actions>
			<NcActionButton :disabled="index === 0" @click="move(shelf, -1)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiArrowUp" />
				</template>
				{{ t('ebookreader', 'Move up') }}
			</NcActionButton>
			<NcActionButton :disabled="index === shelves.shelves.length - 1" @click="move(shelf, 1)">
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
		v-if="shelves.loaded && shelves.shelves.length === 0"
		:name="t('ebookreader', 'No shelves yet')"
		class="shelves-nav__empty" />

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
import type { Shelf } from '../../types.ts'

import { mdiArrowDown, mdiArrowUp, mdiBookshelf, mdiDeleteOutline, mdiFilterVariant, mdiPlus } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import ShelfNameDialog from './ShelfNameDialog.vue'
import { useLibraryStore } from '../../stores/library.ts'
import { useShelvesStore } from '../../stores/shelves.ts'

const library = useLibraryStore()
const shelves = useShelvesStore()
const showNew = ref(false)
const deleting = ref<Shelf | null>(null)

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
</style>
