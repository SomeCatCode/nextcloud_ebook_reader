<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<svg
		viewBox="0 0 24 24"
		:width="size"
		:height="size"
		fill="currentColor"
		aria-hidden="true">
		<path :d="PATHS[name]" />
	</svg>
</template>

<script setup lang="ts">
import { mdiBookmark, mdiBookmarkOutline, mdiContentCopy, mdiDelete, mdiDownload, mdiFullscreen, mdiFullscreenExit, mdiMarker, mdiNoteTextOutline } from '@mdi/js'

withDefaults(defineProps<{ name: 'menu' | 'search' | 'close' | 'edit' | 'text' | 'bookmark' | 'bookmark-outline' | 'notes' | 'highlight' | 'copy' | 'delete' | 'download' | 'fullscreen' | 'fullscreen-exit', size?: number }>(), { size: 20 })

const PATHS = {
	menu: 'M3,6H21V8H3V6M3,11H21V13H3V11M3,16H21V18H3V16Z',
	search: 'M9.5,3A6.5,6.5 0 0,1 16,9.5C16,11.11 15.41,12.59 14.44,13.73L14.71,14H15.5L20.5,19L19,20.5L14,15.5V14.71L13.73,14.44C12.59,15.41 11.11,16 9.5,16A6.5,6.5 0 0,1 3,9.5A6.5,6.5 0 0,1 9.5,3M9.5,5C7,5 5,7 5,9.5C5,12 7,14 9.5,14C12,14 14,12 14,9.5C14,7 12,5 9.5,5Z',
	close: 'M19,6.41L17.59,5L12,10.59L6.41,5L5,6.41L10.59,12L5,17.59L6.41,19L12,13.41L17.59,19L19,17.59L13.41,12L19,6.41Z',
	edit: 'M20.71,7.04C21.1,6.65 21.1,6 20.71,5.63L18.37,3.29C18,2.9 17.35,2.9 16.96,3.29L15.12,5.12L18.87,8.87M3,17.25V21H6.75L17.81,9.93L14.06,6.18L3,17.25Z',
	bookmark: mdiBookmark,
	'bookmark-outline': mdiBookmarkOutline,
	notes: mdiNoteTextOutline,
	highlight: mdiMarker,
	copy: mdiContentCopy,
	delete: mdiDelete,
	download: mdiDownload,
	fullscreen: mdiFullscreen,
	'fullscreen-exit': mdiFullscreenExit,
	text: 'M9.6,14L12,7.7L14.4,14M11,5L5.5,19H7.7L8.8,16H15.2L16.3,19H18.5L13,5H11Z',
}
</script>
