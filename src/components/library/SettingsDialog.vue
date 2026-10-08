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
				<h3>{{ t('ebookreader', 'Where to store metadata changes') }}</h3>
				<p class="hint">
					{{ t('ebookreader', 'Applies when you change e.g. tags or the title of a book.') }}
				</p>
				<div v-for="option in targets" :key="option.value">
					<NcCheckboxRadioSwitch
						v-model="target"
						type="radio"
						name="metadata-target"
						:value="option.value">
						{{ option.label }}
					</NcCheckboxRadioSwitch>
					<p class="hint library-settings__option-help">
						{{ option.help }}
					</p>
				</div>
			</section>

			<section v-if="sidecarSupported && usesSidecar">
				<h3>{{ t('ebookreader', 'Where to store companion files') }}</h3>
				<p class="hint">
					{{ t('ebookreader', 'The sidecar files with the metadata of your books. Changing this moves the existing files in the background.') }}
				</p>
				<div v-for="option in sidecarOptions" :key="option.value">
					<NcCheckboxRadioSwitch
						v-model="sidecarLocation"
						type="radio"
						name="sidecar-location"
						:value="option.value">
						{{ option.label }}
					</NcCheckboxRadioSwitch>
					<p class="hint library-settings__option-help">
						{{ option.help }}
					</p>
				</div>
			</section>

			<section v-if="usesBookFile">
				<h3>{{ t('ebookreader', 'Writing metadata into the book file') }}</h3>
				<p class="hint">
					{{ t('ebookreader', 'Large files take a while to rewrite.') }}
				</p>
				<div v-for="mode in writeModes" :key="mode.value">
					<NcCheckboxRadioSwitch
						v-model="writeMode"
						type="radio"
						name="metadata-write-mode"
						:value="mode.value">
						{{ mode.label }}
					</NcCheckboxRadioSwitch>
					<p class="hint library-settings__option-help">
						{{ mode.help }}
					</p>
				</div>
			</section>

			<section v-if="opds && opds.allowed">
				<h3>{{ t('ebookreader', 'OPDS catalog') }}</h3>
				<p class="hint">
					{{ t('ebookreader', 'Lets e-reader apps such as KOReader, Moon+ Reader, Librera, Thorium or Panels browse and download your library.') }}
				</p>
				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="opds.enabled"
					:disabled="opdsBusy"
					@update:modelValue="setOpdsEnabled">
					{{ t('ebookreader', 'Enable the OPDS catalog for my account') }}
				</NcCheckboxRadioSwitch>
				<template v-if="opds.enabled">
					<div class="library-settings__url">
						<NcTextField
							:modelValue="opds.url"
							readonly
							:label="t('ebookreader', 'Catalog URL')" />
						<NcButton @click="copyOpdsUrl">
							<template #icon>
								<NcIconSvgWrapper :path="mdiContentCopy" />
							</template>
							{{ t('ebookreader', 'Copy') }}
						</NcButton>
					</div>
					<p class="hint">
						{{ t('ebookreader', 'Create an app password under Settings → Security and enter your user name and that app password in the reader app. Do not use your normal password.') }}
					</p>
				</template>
			</section>

			<section v-if="opds && opds.isAdmin">
				<h3>{{ t('ebookreader', 'OPDS catalog (administration)') }}</h3>
				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="opds.allowed"
					:disabled="opdsBusy"
					@update:modelValue="setOpdsAllowed">
					{{ t('ebookreader', 'Allow the OPDS catalog on this server') }}
				</NcCheckboxRadioSwitch>
				<p class="hint">
					{{ t('ebookreader', 'When switched off, nobody can use the catalog, regardless of their own setting.') }}
				</p>
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
import type { OpdsState } from '../../services/opdsApi.ts'
import type { MetadataTarget, MetadataWriteMode, SidecarLocation } from '../../types.ts'

import { mdiClose, mdiContentCopy, mdiFolderPlusOutline } from '@mdi/js'
import { getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { hasFeature } from '../../services/features.ts'
import { getOpds, putOpds } from '../../services/opdsApi.ts'
import { useSettingsStore } from '../../stores/settings.ts'
import { resolveTarget, resolveWriteMode, writesBookFile } from './metadataStorage.ts'

const emit = defineEmits<{ close: [], saved: [] }>()

const settingsStore = useSettingsStore()
const initial = settingsStore.settings

const folders = ref<string[]>([...initial.libraryFolders])
const pattern = ref(initial.filenamePattern)
const target = ref<MetadataTarget>(resolveTarget(initial.metadataTarget, initial.metadataWriteMode))
const writeMode = ref<MetadataWriteMode>(resolveWriteMode(initial.metadataWriteMode))
const usesBookFile = computed(() => writesBookFile(target.value))
const targets = computed(() => [
	{ value: 'sidecar' as const, label: t('ebookreader', 'Sidecar file (recommended)'), help: t('ebookreader', 'A small hidden file ".<book>.opf" next to the book. Fast, works for all formats and travels with the files.') },
	{ value: 'file' as const, label: t('ebookreader', 'Inside the book'), help: t('ebookreader', 'Other readers see the changes, but the book file is rewritten.') },
	{ value: 'both' as const, label: t('ebookreader', 'Sidecar file and inside the book'), help: t('ebookreader', 'Both of the above.') },
	{ value: 'library' as const, label: t('ebookreader', 'Library only'), help: t('ebookreader', 'Only stored in this library, files and folders stay untouched.') },
])
const sidecarSupported = hasFeature('sidecar-meta')
const sidecarLocation = ref<SidecarLocation>(initial.sidecarLocation === 'meta' ? 'meta' : 'beside')
const usesSidecar = computed(() => target.value === 'sidecar' || target.value === 'both')
const sidecarOptions = computed(() => [
	{ value: 'beside' as const, label: t('ebookreader', 'Next to the book (recommended)'), help: t('ebookreader', 'A hidden file ".<book>.opf" in the same folder as the book.') },
	{ value: 'meta' as const, label: t('ebookreader', 'In a hidden .meta folder per directory'), help: t('ebookreader', 'The folders stay tidy: all companion files of a directory go into its hidden ".meta" folder.') },
])
const writeModes = computed(() => [
	{ value: 'background' as const, label: t('ebookreader', 'In the background (recommended)'), help: t('ebookreader', 'Saved instantly in the library and written into the file shortly afterwards in one go.') },
	{ value: 'immediate' as const, label: t('ebookreader', 'Immediately'), help: t('ebookreader', 'Written into the file right away (slow for large files).') },
])
const genreText = ref((initial.genreList ?? []).join('\n'))

const opds = ref<OpdsState | null>(null)
const opdsBusy = ref(false)
onMounted(async () => {
	try {
		opds.value = await getOpds()
	} catch {
		// the OPDS section stays hidden
	}
})

/**
 * @param patch
 * @param patch.enabled
 * @param patch.allowed
 */
async function updateOpds(patch: { enabled?: boolean, allowed?: boolean }): Promise<void> {
	opdsBusy.value = true
	try {
		opds.value = await putOpds(patch)
	} catch {
		showError(t('ebookreader', 'Could not save the settings'))
	} finally {
		opdsBusy.value = false
	}
}

/**
 * @param value
 */
function setOpdsEnabled(value: boolean): Promise<void> {
	return updateOpds({ enabled: value })
}

/**
 * @param value
 */
function setOpdsAllowed(value: boolean): Promise<void> {
	return updateOpds({ allowed: value })
}

/**
 *
 */
async function copyOpdsUrl(): Promise<void> {
	try {
		await navigator.clipboard.writeText(opds.value?.url ?? '')
		showSuccess(t('ebookreader', 'Copied to the clipboard'))
	} catch {
		showError(t('ebookreader', 'Could not copy the URL'))
	}
}

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
			metadataTarget: target.value,
			metadataWriteMode: writeMode.value,
			...(sidecarSupported ? { sidecarLocation: sidecarLocation.value } : {}),
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

	&__url {
		display: flex;
		align-items: flex-end;
		gap: 8px;
		margin: 8px 0;
	}

	&__option-help {
		margin: 0 0 8px 44px;
	}

	&__path {
		overflow-wrap: anywhere;
	}
}
</style>
