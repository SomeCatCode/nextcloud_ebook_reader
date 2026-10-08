<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span
		v-if="state"
		class="share-badge"
		:class="`share-badge--${state}`"
		:title="title"
		role="img"
		:aria-label="title">
		<NcIconSvgWrapper :path="state === 'incoming' ? mdiAccountArrowLeftOutline : mdiShareVariant" :size="size" />
	</span>
</template>

<script setup lang="ts">
import { mdiAccountArrowLeftOutline, mdiShareVariant } from '@mdi/js'
import { n, t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { shareState } from './utils.ts'

const props = withDefaults(defineProps<{
	/** shared with the user by somebody else */
	shared?: boolean
	/** shared by the user with others */
	sharedOut?: boolean
	/** user id or display name of the owner (incoming) */
	owner?: string
	/** number of users an own item is shared with, when known */
	sharedWith?: number
	size?: number
}>(), { shared: false, sharedOut: false, owner: '', sharedWith: 0, size: 14 })

const state = computed(() => shareState({ shared: props.shared, sharedOut: props.sharedOut }))
const title = computed(() => {
	if (state.value === 'incoming') {
		return props.owner
			? t('ebookreader', 'Shared with you by {name}', { name: props.owner })
			: t('ebookreader', 'Shared with you')
	}
	return props.sharedWith > 0
		? n('ebookreader', 'Shared by you with %n user', 'Shared by you with %n users', props.sharedWith)
		: t('ebookreader', 'Shared by you')
})
</script>

<style scoped lang="scss">
.share-badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 2px;
	border-radius: var(--border-radius-small, 4px);
	background: rgba(0, 0, 0, 0.6);
	color: #fff;
	line-height: 1;
}
</style>
