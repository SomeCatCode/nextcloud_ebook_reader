<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppNavigationItem
		:name="t('ebookreader', 'Shared')"
		:title="t('ebookreader', 'What you share with whom and what is shared with you')"
		@click="open = true">
		<template #icon>
			<NcIconSvgWrapper :path="mdiShareVariant" />
		</template>
	</NcAppNavigationItem>
	<SharingOverview v-if="open" @close="open = false" />
</template>

<script setup lang="ts">
import { mdiShareVariant } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { ref } from 'vue'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import SharingOverview from './SharingOverview.vue'

const open = ref(false)
</script>
