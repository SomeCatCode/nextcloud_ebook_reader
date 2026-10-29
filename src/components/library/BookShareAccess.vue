<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="book-access">
		<template v-if="!embedded">
			<p class="book-access__hint">
				{{ t('ebookreader', 'Everybody who can read this book, and the share that gives the access.') }}
			</p>

			<NcButton @click="$emit('share')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiShareVariant" />
				</template>
				{{ t('ebookreader', 'Share…') }}
			</NcButton>
		</template>

		<div v-if="loading && !loaded" class="book-access__center">
			<NcLoadingIcon :size="28" />
		</div>
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<p v-else-if="entries.length === 0" class="book-access__hint">
			{{ t('ebookreader', 'Nobody else can read this book.') }}
		</p>

		<ul v-else class="book-access__list">
			<li v-for="entry in entries" :key="keyOf(entry)" class="book-access__entry">
				<div class="book-access__row">
					<NcAvatar
						v-if="entry.shareType === 'user'"
						:user="entry.recipient"
						:displayName="entry.recipientDisplayName"
						:size="32" />
					<span v-else class="book-access__icon">
						<NcIconSvgWrapper :path="typeIcon(entry)" />
					</span>
					<span class="book-access__who">
						<strong>{{ recipientName(entry) }}</strong>
						<span class="book-access__sub">{{ viaText(entry) }}</span>
						<span v-if="entry.expiresAt" class="book-access__sub">
							{{ t('ebookreader', 'Expires on {date}', { date: formatDate(entry.expiresAt) }) }}
						</span>
					</span>
					<NcButton
						variant="tertiary"
						:disabled="busy"
						:aria-label="t('ebookreader', 'Remove access')"
						:title="t('ebookreader', 'Remove access')"
						@click="confirming = keyOf(entry)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiClose" />
						</template>
					</NcButton>
				</div>
				<div v-if="confirming === keyOf(entry)" class="book-access__confirm">
					<p>{{ consequence(entry) }}</p>
					<div class="book-access__buttons">
						<NcButton variant="error" :disabled="busy" @click="revoke(entry)">
							{{ t('ebookreader', 'Remove') }}
						</NcButton>
						<NcButton variant="tertiary" :disabled="busy" @click="confirming = null">
							{{ t('ebookreader', 'Cancel') }}
						</NcButton>
					</div>
				</div>
			</li>
		</ul>
	</div>
</template>

<script setup lang="ts">
import type { BookAccess } from '../../types.ts'

import { mdiAccountGroup, mdiClose, mdiEmailOutline, mdiLink, mdiShareVariant, mdiWeb } from '@mdi/js'
import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { onMounted, ref, watch } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ApiError, getBookAccess, revokeBookAccess } from '../../services/api.ts'
import { useSharesStore } from '../../stores/shares.ts'

const props = defineProps<{
	fileId: number
	/** inside the share dialog: without hint and share button */
	embedded?: boolean
}>()
defineEmits<{ share: [] }>()

const shares = useSharesStore()
const entries = ref<BookAccess[]>([])
const loading = ref(false)
const loaded = ref(false)
const busy = ref(false)
const error = ref('')
/** key of the entry whose removal waits for the confirmation */
const confirming = ref<string | null>(null)

onMounted(load)
watch(() => props.fileId, () => {
	loaded.value = false
	confirming.value = null
	void load()
})

/**
 * Loads the access list (again, e.g. after the share dialog closed).
 */
async function load(): Promise<void> {
	const fileId = props.fileId
	loading.value = true
	error.value = ''
	try {
		const res = await getBookAccess(fileId)
		if (fileId === props.fileId) {
			entries.value = res
			loaded.value = true
		}
	} catch (e) {
		if (fileId !== props.fileId) {
			return
		}
		if (e instanceof ApiError && e.status === 404) {
			// a book of another user (shared with this user): only its owner sees the shares
			entries.value = []
			loaded.value = true
		} else {
			error.value = t('ebookreader', 'Could not load the shares of this book')
		}
	} finally {
		loading.value = false
	}
}

defineExpose({ load })

/**
 * @param entry
 */
function keyOf(entry: BookAccess): string {
	return [entry.via, entry.shareId ?? '', entry.shelfId ?? '', entry.series ?? '', entry.path ?? '', entry.shareType, entry.recipient].join('|')
}

/**
 * @param entry
 */
function typeIcon(entry: BookAccess): string {
	switch (entry.shareType) {
		case 'group':
		case 'team': return mdiAccountGroup
		case 'link': return mdiLink
		case 'email': return mdiEmailOutline
		default: return mdiWeb
	}
}

/**
 * @param entry
 */
function recipientName(entry: BookAccess): string {
	switch (entry.shareType) {
		case 'link': return entry.recipientDisplayName !== ''
			? t('ebookreader', 'Public link "{label}"', { label: entry.recipientDisplayName })
			: t('ebookreader', 'Public link')
		case 'group': return t('ebookreader', '{name} (group)', { name: entry.recipientDisplayName })
		case 'team': return t('ebookreader', '{name} (team)', { name: entry.recipientDisplayName })
		case 'talk': return t('ebookreader', '{name} (Talk conversation)', { name: entry.recipientDisplayName })
		default: return entry.recipientDisplayName
	}
}

/**
 * @param entry
 */
function viaText(entry: BookAccess): string {
	switch (entry.via) {
		case 'book': return t('ebookreader', 'Book shared in the e-book reader')
		case 'shelf': return t('ebookreader', 'Through the shelf "{name}"', { name: entry.viaName })
		case 'series': return t('ebookreader', 'Through the series "{name}"', { name: entry.viaName })
		case 'folder': return t('ebookreader', 'Through the folder "{path}"', { path: entry.viaName })
		default: {
			const base = entry.folder
				? t('ebookreader', 'Nextcloud share of the folder "{path}"', { path: entry.viaName })
				: t('ebookreader', 'Nextcloud share of the file')
			return entry.sharedBy !== '' && entry.sharedBy !== getCurrentUser()?.uid
				? base + ' · ' + t('ebookreader', 'shared by {name}', { name: entry.sharedByDisplayName })
				: base
		}
	}
}

/**
 * What the removal does, shown before it happens.
 *
 * @param entry
 */
function consequence(entry: BookAccess): string {
	const name = recipientName(entry)
	switch (entry.via) {
		case 'book': return t('ebookreader', '{name} can no longer read this book. A shelf, series or folder share with {name} stays.', { name })
		case 'shelf': return t('ebookreader', 'This stops sharing the whole shelf "{shelf}" with {name}. {name} loses all books of the shelf.', { shelf: entry.viaName, name })
		case 'series': return t('ebookreader', 'This stops sharing the whole series "{series}" with {name}. {name} loses all books of the series.', { series: entry.viaName, name })
		case 'folder': return t('ebookreader', 'This stops sharing the folder "{path}" with {name}. {name} loses all files in the folder.', { path: entry.viaName, name })
		default: return entry.folder
			? t('ebookreader', 'This deletes the Nextcloud share of the folder "{path}". The share ends for all files in the folder.', { path: entry.viaName })
			: t('ebookreader', 'This deletes the Nextcloud share of the file.')
	}
}

/**
 * @param ms
 */
function formatDate(ms: number): string {
	return new Date(ms).toLocaleDateString()
}

/**
 * @param entry
 */
async function revoke(entry: BookAccess): Promise<void> {
	busy.value = true
	try {
		switch (entry.via) {
			case 'book':
				await shares.unshare({ type: 'book', id: props.fileId }, entry.recipient)
				break
			case 'shelf':
				await shares.unshare({ type: 'shelf', id: entry.shelfId ?? 0 }, entry.recipient)
				break
			case 'series':
				await shares.unshare({ type: 'series', id: entry.series ?? '' }, entry.recipient)
				break
			case 'folder':
				await shares.unshare({ type: 'folder', id: entry.path ?? '' }, entry.recipient)
				break
			default:
				await revokeBookAccess(props.fileId, entry.shareId ?? '')
		}
		confirming.value = null
		showSuccess(t('ebookreader', 'Access removed'))
	} catch (e) {
		showError(e instanceof ApiError && e.message ? e.message : t('ebookreader', 'Could not remove the access'))
	} finally {
		busy.value = false
	}
	await load()
}
</script>

<style scoped lang="scss">
.book-access {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px 0;

	&__hint {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__center {
		display: flex;
		justify-content: center;
	}

	&__list {
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__entry {
		padding: 4px 0;
	}

	&__row {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	&__icon {
		display: flex;
		flex: 0 0 32px;
		align-items: center;
		justify-content: center;
		width: 32px;
		height: 32px;
		border-radius: 50%;
		background: var(--color-background-dark);
	}

	&__who {
		display: flex;
		flex: 1 1 auto;
		flex-direction: column;
		min-width: 0;
		overflow-wrap: anywhere;
	}

	&__sub {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	&__confirm {
		margin: 4px 0 0 40px;

		p {
			margin: 0 0 4px;
		}
	}

	&__buttons {
		display: flex;
		gap: 8px;
	}
}
</style>
