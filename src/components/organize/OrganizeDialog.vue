<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('ebookreader', 'Rename / organise')"
		:open="true"
		size="large"
		:buttons="buttons"
		closeOnClickOutside
		@update:open="(open: boolean) => !open && $emit('close')">
		<div class="organize">
			<p>{{ n('ebookreader', '%n book selected', '%n books selected', fileIds.length) }}</p>

			<NcSelect
				:modelValue="null"
				:options="presets"
				:inputLabel="t('ebookreader', 'Presets')"
				:clearable="false"
				:searchable="false"
				@update:modelValue="(p: string | null) => p && (pattern = p)" />

			<NcTextField
				v-model="pattern"
				:label="t('ebookreader', 'Pattern')"
				:helperText="t('ebookreader', 'A “/” creates subfolders. The file extension is added automatically.')" />

			<div class="organize__placeholders">
				<span class="organize__hint">{{ t('ebookreader', 'Placeholders') }}:</span>
				<button
					v-for="p in placeholders"
					:key="p"
					type="button"
					class="organize__placeholder"
					@click="pattern += p">
					{{ p }}
				</button>
			</div>

			<div class="organize__folder">
				<NcTextField
					v-model="targetFolder"
					:label="t('ebookreader', 'Target folder')" />
				<NcButton @click="chooseFolder">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFolderOutline" />
					</template>
					{{ t('ebookreader', 'Choose folder') }}
				</NcButton>
			</div>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<div class="organize__preview">
				<div v-if="loading" class="organize__center">
					<NcLoadingIcon :size="28" />
				</div>
				<table v-if="items.length" class="organize__table">
					<thead>
						<tr>
							<th>{{ t('ebookreader', 'From') }}</th>
							<th>{{ t('ebookreader', 'To') }}</th>
							<th>{{ t('ebookreader', 'Status') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="item in items" :key="item.fileId" :class="'organize__row--' + item.status">
							<td>{{ item.from }}</td>
							<td>{{ item.to }}</td>
							<td>
								<span class="organize__badge" :class="'organize__badge--' + item.status" :title="item.message">
									{{ statusLabel(item.status) }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	</NcDialog>
</template>

<script setup lang="ts">
import type { OrganizeItem, OrganizeStatus } from '../../types.ts'

import { mdiFolderOutline } from '@mdi/js'
import { getFilePickerBuilder, showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { organizeApply, organizePreview } from '../../services/api.ts'
import { useLibraryStore } from '../../stores/library.ts'
import { useSettingsStore } from '../../stores/settings.ts'

const props = defineProps<{ fileIds: number[] }>()
const emit = defineEmits<{ close: [], done: [] }>()

const PREVIEW_DEBOUNCE_MS = 400

const library = useLibraryStore()
const settings = useSettingsStore()

const presets = [
	'{author}/{series}/{series_index:2} - {title}',
	'{author} - {title}',
	'{author}/{title}',
	'{genre}/{author} - {title}',
]
const placeholders = [
	'{author}',
	'{authors}',
	'{title}',
	'{series}',
	'{series_index}',
	'{series_index:2}',
	'{year}',
	'{publisher}',
	'{language}',
	'{genre}',
	'{format}',
]

const pattern = ref(presets[1])
const targetFolder = ref(settings.settings.libraryFolders[0] ?? '/Books')
const items = ref<OrganizeItem[]>([])
const loading = ref(false)
const applying = ref(false)
const error = ref<string | null>(null)

let timer: ReturnType<typeof setTimeout> | null = null
let previewId = 0

const movable = computed(() => items.value.filter((i) => i.status === 'move' || i.status === 'conflict').length)

const buttons = computed(() => [
	{
		label: t('ebookreader', 'Cancel'),
		variant: 'tertiary' as const,
		callback: (): void => {
			emit('close')
		},
	},
	{
		label: t('ebookreader', 'Apply'),
		variant: 'primary' as const,
		disabled: applying.value || loading.value || movable.value === 0 || pattern.value.trim() === '',
		callback: (): false => {
			void apply()
			return false
		},
	},
])

/**
 * @param status
 */
function statusLabel(status: OrganizeStatus): string {
	switch (status) {
		case 'move': return t('ebookreader', 'Will move')
		case 'moved': return t('ebookreader', 'Moved')
		case 'unchanged': return t('ebookreader', 'Unchanged')
		case 'conflict': return t('ebookreader', 'Conflict (renamed)')
		case 'failed': return t('ebookreader', 'Failed')
		default: return t('ebookreader', 'Error')
	}
}

/**
 *
 */
async function refreshPreview(): Promise<void> {
	if (pattern.value.trim() === '') {
		items.value = []
		return
	}
	const id = ++previewId
	loading.value = true
	error.value = null
	try {
		const res = await organizePreview({
			fileIds: props.fileIds,
			pattern: pattern.value,
			targetFolder: targetFolder.value || undefined,
		})
		if (id === previewId) {
			items.value = res.items
		}
	} catch (e) {
		if (id === previewId) {
			items.value = []
			error.value = e instanceof Error ? e.message : String(e)
		}
	} finally {
		if (id === previewId) {
			loading.value = false
		}
	}
}

watch([pattern, targetFolder], () => {
	if (timer !== null) {
		clearTimeout(timer)
	}
	timer = setTimeout(() => {
		timer = null
		void refreshPreview()
	}, PREVIEW_DEBOUNCE_MS)
}, { immediate: true })

onBeforeUnmount(() => {
	if (timer !== null) {
		clearTimeout(timer)
	}
})

/**
 *
 */
async function chooseFolder(): Promise<void> {
	let chosen: string | null = null
	const picker = getFilePickerBuilder(t('ebookreader', 'Choose a target folder'))
		.allowDirectories(true)
		.setMimeTypeFilter(['httpd/unix-directory'])
		.setMultiSelect(false)
		.setButtonFactory((nodes, currentPath) => [{
			label: nodes.length ? t('ebookreader', 'Choose {name}', { name: nodes[0].basename }) : t('ebookreader', 'Choose current folder'),
			variant: 'primary',
			callback: () => {
				chosen = nodes[0]?.path ?? currentPath
			},
		}])
		.startAt(targetFolder.value || '/')
		.build()
	try {
		await picker.pick()
	} catch {
		return
	}
	if (chosen) {
		targetFolder.value = '/' + String(chosen).replace(/^\/+|\/+$/g, '')
	}
}

/**
 *
 */
async function apply(): Promise<void> {
	applying.value = true
	try {
		const res = await organizeApply({
			fileIds: props.fileIds,
			pattern: pattern.value,
			targetFolder: targetFolder.value || undefined,
		})
		if (res.failed > 0) {
			showWarning(t('ebookreader', '{moved} moved, {failed} failed', { moved: res.moved, failed: res.failed }))
			items.value = res.items
		} else {
			showSuccess(n('ebookreader', '%n book moved', '%n books moved', res.moved))
		}
		await Promise.all([library.reload(), library.loadFacets()])
		if (res.failed === 0) {
			emit('done')
			emit('close')
		}
	} catch (e) {
		showError(t('ebookreader', 'Could not organise the books'))
		error.value = e instanceof Error ? e.message : String(e)
	} finally {
		applying.value = false
	}
}
</script>

<style scoped lang="scss">
.organize {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 320px;

	&__placeholders {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__placeholder {
		padding: 0 8px;
		border: none;
		border-radius: var(--border-radius-pill);
		background: var(--color-background-dark);
		font-family: monospace;
		cursor: pointer;
		min-height: 0;
	}

	&__folder {
		display: flex;
		align-items: flex-end;
		gap: 8px;
	}

	&__center {
		display: flex;
		justify-content: center;
	}

	&__preview {
		max-height: 300px;
		overflow: auto;
	}

	&__table {
		width: 100%;
		border-collapse: collapse;

		th {
			text-align: start;
		}

		th,
		td {
			padding: 4px 8px;
			overflow-wrap: anywhere;
		}

		tbody tr:nth-child(odd) {
			background: var(--color-background-hover);
		}
	}

	&__row--conflict td {
		background: color-mix(in srgb, var(--color-warning) 20%, transparent);
	}

	&__row--error td,
	&__row--failed td {
		background: color-mix(in srgb, var(--color-error) 20%, transparent);
	}

	&__badge {
		padding: 0 8px;
		border-radius: var(--border-radius-pill);
		background: var(--color-background-dark);
		white-space: nowrap;

		&--move,
		&--moved {
			background: color-mix(in srgb, var(--color-success) 25%, transparent);
		}

		&--conflict {
			background: color-mix(in srgb, var(--color-warning) 35%, transparent);
		}

		&--error,
		&--failed {
			background: color-mix(in srgb, var(--color-error) 30%, transparent);
		}
	}
}
</style>
