<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="folder-browser">
		<nav class="folder-browser__crumbs" :aria-label="t('ebookreader', 'Folder path')">
			<NcButton
				:variant="library.folderPath === null ? 'secondary' : 'tertiary'"
				:disabled="library.folderPath === null"
				@click="library.openFolder(null)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiFolderMultipleOutline" />
				</template>
				{{ t('ebookreader', 'Folders') }}
			</NcButton>
			<template v-for="(crumb, i) in crumbs" :key="crumb.path">
				<NcIconSvgWrapper class="folder-browser__sep" :path="mdiChevronRight" :size="18" />
				<NcButton
					:variant="i === crumbs.length - 1 ? 'secondary' : 'tertiary'"
					:disabled="i === crumbs.length - 1"
					@click="library.openFolder(crumb.path)">
					{{ crumb.name }}
				</NcButton>
			</template>
		</nav>

		<div v-if="current" class="folder-browser__actions">
			<NcCheckboxRadioSwitch
				type="switch"
				:modelValue="library.folderRecursive"
				@update:modelValue="(v: boolean) => library.setFolderRecursive(v)">
				{{ t('ebookreader', 'Include subfolders') }}
			</NcCheckboxRadioSwitch>
			<span class="folder-browser__spacer" />
			<NcButton v-if="canShare(current)" variant="tertiary" @click="sharing = current">
				<template #icon>
					<NcIconSvgWrapper :path="mdiShareVariant" />
				</template>
				{{ t('ebookreader', 'Share folder…') }}
			</NcButton>
			<NcButton
				variant="tertiary"
				:href="filesLink(current.path)"
				target="_blank"
				rel="noopener">
				<template #icon>
					<NcIconSvgWrapper :path="mdiFolderOpenOutline" />
				</template>
				{{ t('ebookreader', 'Open in Files') }}
			</NcButton>
		</div>

		<div v-if="!folders.loaded && folders.loading" class="folder-browser__center">
			<NcLoadingIcon :size="32" />
		</div>
		<NcNoteCard v-else-if="folders.failed && !folders.loaded" type="error">
			{{ t('ebookreader', 'Could not load the folders. The folder view needs a newer version of the e-book reader app on the server.') }}
		</NcNoteCard>
		<NcEmptyContent
			v-else-if="library.folderPath === null && children.length === 0"
			:name="t('ebookreader', 'No folders')"
			:description="t('ebookreader', 'Folders with e-books appear here once your library has been scanned.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiFolderOutline" :size="64" />
			</template>
		</NcEmptyContent>
		<ul v-else-if="children.length > 0" class="folder-browser__grid">
			<li v-for="folder in children" :key="folder.path" class="folder-browser__tile">
				<button type="button" class="folder-browser__open" @click="library.openFolder(folder.path)">
					<NcIconSvgWrapper :path="mdiFolderOutline" :size="28" />
					<span class="folder-browser__name">{{ folder.name }}</span>
					<span class="folder-browser__count">{{ n('ebookreader', '%n book', '%n books', folder.totalCount) }}</span>
					<ShareBadge
						:shared="folder.shared"
						:sharedOut="folder.sharedWith > 0"
						:sharedWith="folder.sharedWith" />
				</button>
				<NcActions :aria-label="t('ebookreader', 'Actions for the folder “{name}”', { name: folder.name })">
					<NcActionButton v-if="canShare(folder)" closeAfterClick @click="sharing = folder">
						<template #icon>
							<NcIconSvgWrapper :path="mdiShareVariant" />
						</template>
						{{ t('ebookreader', 'Share…') }}
					</NcActionButton>
					<NcActionLink :href="filesLink(folder.path)" target="_blank" rel="noopener">
						<template #icon>
							<NcIconSvgWrapper :path="mdiFolderOpenOutline" />
						</template>
						{{ t('ebookreader', 'Open in Files') }}
					</NcActionLink>
				</NcActions>
			</li>
		</ul>

		<h3 v-if="current && children.length > 0 && !library.isEmpty" class="folder-browser__heading">
			{{ t('ebookreader', 'Books in this folder') }}
		</h3>

		<ShareDialog
			v-if="sharing"
			:target="{ type: 'folder', id: sharing.path, name: sharing.path }"
			@close="sharing = null" />
	</div>
</template>

<script setup lang="ts">
import type { FolderEntry } from '../../types.ts'

import { mdiChevronRight, mdiFolderMultipleOutline, mdiFolderOpenOutline, mdiFolderOutline, mdiShareVariant } from '@mdi/js'
import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, onMounted, ref } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionLink from '@nextcloud/vue/components/NcActionLink'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ShareBadge from './ShareBadge.vue'
import ShareDialog from './ShareDialog.vue'
import { hasFeature } from '../../services/features.ts'
import { filesAppUrl } from '../../services/folders.ts'
import { useFoldersStore } from '../../stores/folders.ts'
import { useLibraryStore } from '../../stores/library.ts'

const library = useLibraryStore()
const folders = useFoldersStore()
const sharing = ref<FolderEntry | null>(null)

const crumbs = computed(() => folders.crumbsOf(library.folderPath))
const current = computed(() => (library.folderPath === null ? null : crumbs.value[crumbs.value.length - 1] ?? null))
const children = computed(() => folders.childrenOf(library.folderPath))
const canShareFolders = hasFeature('folder-shares')

onMounted(() => {
	void folders.load()
})

/**
 * Folders of other users can not be shared on by the recipient.
 *
 * @param folder
 */
function canShare(folder: FolderEntry): boolean {
	return canShareFolders && !folder.shared
}

/**
 * @param path
 */
function filesLink(path: string): string {
	return generateUrl(filesAppUrl(path))
}
</script>

<style scoped lang="scss">
.folder-browser {
	display: flex;
	flex-direction: column;
	gap: 8px;

	&__crumbs {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 2px;
	}

	&__sep {
		color: var(--color-text-maxcontrast);
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 4px 8px;
	}

	&__spacer {
		flex: 1 1 auto;
	}

	&__center {
		display: flex;
		justify-content: center;
		padding: 32px 0;
	}

	&__grid {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
		gap: 8px;
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__tile {
		display: flex;
		align-items: center;
		min-width: 0;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);

		&:hover,
		&:focus-within {
			background: var(--color-background-hover);
		}
	}

	&__open {
		all: unset;
		box-sizing: border-box;
		display: grid;
		flex: 1 1 auto;
		grid-template-columns: auto 1fr auto;
		grid-template-areas: "icon name badge" "icon count badge";
		align-items: center;
		column-gap: 10px;
		min-width: 0;
		padding: 8px 10px;
		cursor: pointer;

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			border-radius: var(--border-radius-large);
		}

		> :nth-child(1) { grid-area: icon; }
		> :nth-child(4) { grid-area: badge; }
	}

	&__name {
		grid-area: name;
		overflow: hidden;
		font-weight: bold;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__count {
		grid-area: count;
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	&__heading {
		margin: 8px 0 0;
		color: var(--color-text-maxcontrast);
		font-size: 1em;
	}
}
</style>
