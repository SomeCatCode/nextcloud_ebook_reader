<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="target.type === 'book' ? t('ebookreader', 'Share book') : t('ebookreader', 'Share shelf')"
		:open="true"
		size="normal"
		closeOnClickOutside
		@update:open="(open: boolean) => !open && $emit('close')">
		<div class="share-dialog">
			<p class="share-dialog__name">
				{{ target.name }}
			</p>
			<p class="share-dialog__hint">
				{{ target.type === 'book'
					? t('ebookreader', 'The book is shared read-only. Everybody keeps their own reading progress, rating and notes.')
					: t('ebookreader', 'The shelf is shared read-only and stays up to date: books you add to or remove from it are shared or unshared automatically. Everybody keeps their own reading progress.') }}
			</p>

			<NcSelectUsers
				:key="selectKey"
				:options="options"
				:loading="searching"
				:disabled="busy"
				:inputLabel="t('ebookreader', 'Share with')"
				:placeholder="t('ebookreader', 'Search for a user…')"
				@search="onSearch"
				@update:modelValue="onPick" />

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<h3 class="share-dialog__heading">
				{{ t('ebookreader', 'Shared with') }}
			</h3>
			<div v-if="!shares.loaded && shares.loading" class="share-dialog__center">
				<NcLoadingIcon :size="28" />
			</div>
			<p v-else-if="recipients.length === 0" class="share-dialog__hint">
				{{ t('ebookreader', 'Not shared with anybody yet.') }}
			</p>
			<ul v-else class="share-dialog__list">
				<li v-for="entry in recipients" :key="entry.recipient" class="share-dialog__entry">
					<NcAvatar :user="entry.recipient" :displayName="entry.recipientDisplayName" :size="32" />
					<span class="share-dialog__who">
						<strong>{{ entry.recipientDisplayName }}</strong>
						<span v-if="entry.type === 'shelf'" class="share-dialog__sub">
							{{ n('ebookreader', '%n book shared', '%n books shared', entry.bookCount) }}
						</span>
					</span>
					<NcButton
						variant="tertiary"
						:disabled="busy"
						:aria-label="t('ebookreader', 'Stop sharing with {name}', { name: entry.recipientDisplayName })"
						:title="t('ebookreader', 'Stop sharing with {name}', { name: entry.recipientDisplayName })"
						@click="remove(entry.recipient)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiClose" />
						</template>
					</NcButton>
				</li>
			</ul>
		</div>
	</NcDialog>
</template>

<script setup lang="ts">
import type { ShareTarget } from '../../stores/shares.ts'
import type { Sharee } from '../../types.ts'

import { mdiClose } from '@mdi/js'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelectUsers from '@nextcloud/vue/components/NcSelectUsers'
import { ApiError, searchSharees } from '../../services/api.ts'
import { useSharesStore } from '../../stores/shares.ts'

const props = defineProps<{ target: ShareTarget }>()
defineEmits<{ close: [] }>()

const shares = useSharesStore()
const found = ref<Sharee[]>([])
const searching = ref(false)
const busy = ref(false)
const error = ref('')
/** re-mounts the user picker after a pick so it is empty again */
const selectKey = ref(0)
let searchSeq = 0
let searchTimer: ReturnType<typeof setTimeout> | null = null

const recipients = computed(() => shares.recipientsOf(props.target))

/** search results without the users it is already shared with */
const options = computed(() => {
	const taken = new Set(recipients.value.map((r) => r.recipient))
	return found.value.filter((u) => !taken.has(u.id)).map((u) => ({ id: u.id, user: u.id, displayName: u.displayName, subname: u.subname }))
})

onMounted(async () => {
	try {
		await shares.load()
	} catch {
		error.value = t('ebookreader', 'Could not load the current shares')
	}
})

/**
 * Debounced user search (the sharee API applies the admin's enumeration settings).
 *
 * @param query
 */
function onSearch(query: string): void {
	if (searchTimer !== null) {
		clearTimeout(searchTimer)
	}
	const q = query.trim()
	if (q.length < 1) {
		found.value = []
		return
	}
	searchTimer = setTimeout(async () => {
		const seq = ++searchSeq
		searching.value = true
		try {
			const res = await searchSharees(q)
			if (seq === searchSeq) {
				found.value = res
			}
		} catch {
			if (seq === searchSeq) {
				found.value = []
				error.value = t('ebookreader', 'User search is not available')
			}
		} finally {
			if (seq === searchSeq) {
				searching.value = false
			}
		}
	}, 250)
}

/**
 * @param picked
 */
async function onPick(picked: unknown): Promise<void> {
	const user = (Array.isArray(picked) ? picked[0] : picked) as { id?: string } | null | undefined
	if (!user?.id) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		const res = await shares.share(props.target, user.id)
		if (res.skipped > 0) {
			showWarning(n('ebookreader', 'Shared, but %n book could not be shared (no permission to share it)', 'Shared, but %n books could not be shared (no permission to share them)', res.skipped))
		} else {
			showSuccess(t('ebookreader', 'Shared with {name}', { name: res.share.recipientDisplayName }))
		}
		found.value = []
		selectKey.value++
	} catch (e) {
		error.value = e instanceof ApiError && e.message ? e.message : t('ebookreader', 'Could not share')
	} finally {
		busy.value = false
	}
}

/**
 * @param userId
 */
async function remove(userId: string): Promise<void> {
	busy.value = true
	try {
		await shares.unshare(props.target, userId)
	} catch {
		showError(t('ebookreader', 'Could not stop sharing'))
	} finally {
		busy.value = false
	}
}
</script>

<style scoped lang="scss">
.share-dialog {
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-height: 240px;
	padding-bottom: 12px;

	&__name {
		margin: 0;
		font-weight: bold;
	}

	&__hint {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__heading {
		margin: 8px 0 0;
		font-size: 1em;
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
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 4px 0;
	}

	&__who {
		display: flex;
		flex: 1 1 auto;
		flex-direction: column;
		min-width: 0;
	}

	&__sub {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}
}
</style>
