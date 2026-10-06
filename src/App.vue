<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcContent appName="ebookreader">
		<RouterView v-slot="{ Component, route }">
			<!-- The library brings its own NcAppNavigation + NcAppContent (+ sidebar) -->
			<component :is="Component" v-if="route.name === 'library'" />
			<NcAppContent v-else>
				<!-- keyed by path: "next volume" in the reader goes from /read/1 to /read/2 and needs a fresh reader -->
				<component :is="Component" :key="route.path" />
			</NcAppContent>
		</RouterView>
	</NcContent>
</template>

<script setup lang="ts">
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
</script>
