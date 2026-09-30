<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('ebookreader', 'E-book library settings')"
		:open="true"
		size="normal"
		:buttons="buttons"
		closeOnClickOutside
		@update:open="(open: boolean) => !open && $emit('close')">
		<div class="library-settings">
			<section>
				<h3>{{ t('ebookreader', 'Library folders') }}</h3>
				<p class="hint">
					{{ t('ebookreader', 'Books in these folders (including subfolders) appear in your library.') }}
				</p>
				<ul class="library-settings__folders">
					<li v-for="folder in folders" :key="folder">
						<span class="library-settings__path">{{ folder }}</span>
						<NcButton
							variant="tertiary"
							:aria-label="t('ebookreader', 'Remove folder {folder}', { folder })"
							@click="removeFolder(folder)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiClose" />
							</template>
						</NcButton>
					</li>
					<li v-if="folders.length === 0" class="hint">
						{{ t('ebookreader', 'No folders configured.') }}
					</li>
				</ul>
				<NcButton @click="addFolder">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFolderPlusOutline" />
					</template>
					{{ t('ebookreader', 'Add folder') }}
				</NcButton>
			</section>

			<section>
				<h3>{{ t('ebookreader', 'File names') }}</h3>
				<NcTextField
					v-model="pattern"
					:label="t('ebookreader', 'File name pattern')"
					placeholder="{author} - {title}"
					:helperText="t('ebookreader', 'Used when renaming books. Placeholders: {author}, {title}, {series}, {index}, {year}')" />
			</section>

			<section>
				<h3>{{ t('ebookreader', 'Genres') }}</h3>
				<NcTextArea
					v-model="genreText"
					:label="t('ebookreader', 'Genre list')"
					:helperText="t('ebookreader', 'One genre per line. Leave empty to use the default list.')"
					rows="8" />
			</section>
		</div>
	</NcDialog>
</template>

<script setup lang="ts">
import { mdiClose, mdiFolderPlusOutline } from '@mdi/js'
import { getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { useSettingsStore } from '../../stores/settings.ts'

const emit = defineEmits<{ close: [], saved: [] }>()

const settingsStore = useSettingsStore()
const initial = settingsStore.settings

const folders = ref<string[]>([...initial.libraryFolders])
const pattern = ref(initial.filenamePattern)
const genreText = ref((initial.genreList ?? []).join('\n'))

const buttons = computed(() => [
	{
		label: t('ebookreader', 'Cancel'),
		variant: 'tertiary' as const,
		callback: (): void => {
			emit('close')
		},
	},
	{
		label: t('ebookreader', 'Save'),
		variant: 'primary' as const,
		disabled: settingsStore.saving,
		callback: (): false => {
			void save()
			return false
		},
	},
])

/**
 *
 */
async function addFolder(): Promise<void> {
	let chosen: string | null = null
	const picker = getFilePickerBuilder(t('ebookreader', 'Choose a library folder'))
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
		.startAt('/')
		.build()
	try {
		await picker.pick()
	} catch {
		// picker closed without choosing
		return
	}
	if (chosen) {
		const path = '/' + String(chosen).replace(/^\/+|\/+$/g, '')
		if (!folders.value.includes(path)) {
			folders.value = [...folders.value, path]
		}
	}
}

/**
 * @param folder
 */
function removeFolder(folder: string): void {
	folders.value = folders.value.filter((f) => f !== folder)
}

/**
 *
 */
async function save(): Promise<void> {
	const genres = [...new Set(genreText.value.split('\n').map((g) => g.trim()).filter(Boolean))]
	try {
		await settingsStore.save({
			libraryFolders: folders.value,
			filenamePattern: pattern.value.trim() || '{author} - {title}',
			genreList: genres.length ? genres : null,
		})
		showSuccess(t('ebookreader', 'Settings saved'))
		emit('saved')
		emit('close')
	} catch {
		showError(t('ebookreader', 'Could not save the settings'))
	}
}
</script>

<style scoped lang="scss">
.library-settings {
	display: flex;
	flex-direction: column;
	gap: 16px;

	h3 {
		margin: 0 0 4px;
	}

	.hint {
		color: var(--color-text-maxcontrast);
	}

	&__folders {
		list-style: none;
		margin: 8px 0;
		padding: 0;

		li {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 8px;
		}
	}

	&__path {
		overflow-wrap: anywhere;
	}
}
</style>
