<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('ebookreader', 'Add to shelf')"
		:open="true"
		size="small"
		:buttons="buttons"
		closeOnClickOutside
		@update:open="(open: boolean) => !open && $emit('close')">
		<div class="add-to-shelf">
			<p>{{ n('ebookreader', '%n book selected', '%n books selected', fileIds.length) }}</p>
			<p v-if="shelves.manual.length === 0" class="add-to-shelf__hint">
				{{ t('ebookreader', 'You have no shelves yet. Create one to collect books.') }}
			</p>
			<ul v-else class="add-to-shelf__list">
				<li v-for="shelf in shelves.manual" :key="shelf.id">
					<NcCheckboxRadioSwitch
						:modelValue="chosen.includes(shelf.id)"
						@update:modelValue="(v: boolean) => toggle(shelf.id, v)">
						{{ shelf.name }}
					</NcCheckboxRadioSwitch>
				</li>
			</ul>
			<NcButton variant="tertiary" @click="showNew = true">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" />
				</template>
				{{ t('ebookreader', 'New shelf') }}
			</NcButton>
		</div>
	</NcDialog>

	<ShelfNameDialog
		v-if="showNew"
		:title="t('ebookreader', 'New shelf')"
		:confirmLabel="t('ebookreader', 'Create')"
		:onSubmit="createShelf"
		@close="showNew = false" />
</template>

<script setup lang="ts">
import { mdiPlus } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import ShelfNameDialog from './ShelfNameDialog.vue'
import { useLibraryStore } from '../../stores/library.ts'
import { useShelvesStore } from '../../stores/shelves.ts'

const props = defineProps<{ fileIds: number[] }>()
const emit = defineEmits<{ close: [], done: [] }>()

const shelves = useShelvesStore()
const library = useLibraryStore()
const chosen = ref<number[]>([])
const busy = ref(false)
const showNew = ref(false)

const buttons = computed(() => [
	{
		label: t('ebookreader', 'Cancel'),
		variant: 'tertiary' as const,
		callback: (): void => {
			emit('close')
		},
	},
	{
		label: t('ebookreader', 'Add'),
		variant: 'primary' as const,
		disabled: busy.value || chosen.value.length === 0 || props.fileIds.length === 0,
		callback: (): false => {
			void apply()
			return false
		},
	},
])

/**
 * @param id
 * @param on
 */
function toggle(id: number, on: boolean): void {
	chosen.value = on ? [...chosen.value, id] : chosen.value.filter((x) => x !== id)
}

/**
 * @param name
 */
async function createShelf(name: string): Promise<void> {
	const shelf = await shelves.create(name, 'manual')
	chosen.value = [...chosen.value, shelf.id]
}

/**
 *
 */
async function apply(): Promise<void> {
	busy.value = true
	try {
		const res = await shelves.addBooks(chosen.value, props.fileIds)
		showSuccess(res.skipped > 0
			? n('ebookreader', '%n book added to the shelf (some were already on it)', '%n books added to the shelf (some were already on it)', res.added)
			: n('ebookreader', '%n book added to the shelf', '%n books added to the shelf', res.added))
		// the open shelf may have changed
		if (library.activeManualShelfId !== null && chosen.value.includes(library.activeManualShelfId)) {
			void library.reload()
		}
		emit('done')
		emit('close')
	} catch {
		showError(t('ebookreader', 'Could not add the books to the shelf'))
	} finally {
		busy.value = false
	}
}
</script>

<style scoped lang="scss">
.add-to-shelf {
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-height: 160px;

	&__list {
		margin: 0;
		padding: 0;
		list-style: none;
		max-height: 280px;
		overflow-y: auto;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
	}
}
</style>
