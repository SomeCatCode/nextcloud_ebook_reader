<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section v-if="upload.items.length" class="upload-panel" :aria-label="t('ebookreader', 'Upload')">
		<header class="upload-panel__head">
			<strong>
				{{ upload.running
					? t('ebookreader', 'Uploading to {folder}', { folder: upload.folder })
					: t('ebookreader', 'Upload finished') }}
			</strong>
			<span class="upload-panel__spacer" />
			<NcButton v-if="upload.running" variant="tertiary" @click="upload.cancelAll()">
				{{ t('ebookreader', 'Cancel all') }}
			</NcButton>
			<NcButton v-else variant="tertiary" @click="upload.dismiss()">
				{{ t('ebookreader', 'Close') }}
			</NcButton>
		</header>
		<NcProgressBar :value="Math.round(upload.totalProgress * 100)" :error="false" />
		<ul class="upload-panel__list">
			<li v-for="item in upload.items" :key="item.id" class="upload-panel__item">
				<span class="upload-panel__name" :title="item.name">{{ item.name }}</span>
				<span v-if="item.originalName !== item.name" class="upload-panel__hint">
					{{ t('ebookreader', 'renamed, “{name}” already exists', { name: item.originalName }) }}
				</span>
				<span class="upload-panel__state" :class="{ 'upload-panel__state--error': item.status === 'failed' }">
					<template v-if="item.status === 'uploading'">{{ Math.round(item.progress * 100) }}%</template>
					<template v-else-if="item.status === 'done'">{{ t('ebookreader', 'Done') }}</template>
					<template v-else-if="item.status === 'cancelled'">{{ t('ebookreader', 'Cancelled') }}</template>
					<template v-else>{{ t('ebookreader', 'Failed') }}</template>
				</span>
				<NcButton
					v-if="item.status === 'uploading'"
					variant="tertiary"
					:aria-label="t('ebookreader', 'Cancel upload of “{name}”', { name: item.name })"
					@click="upload.cancel(item.id)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiClose" :size="18" />
					</template>
				</NcButton>
			</li>
		</ul>
	</section>
</template>

<script setup lang="ts">
import { mdiClose } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import { useUploadStore } from '../../stores/upload.ts'

const upload = useUploadStore()
</script>

<style scoped lang="scss">
.upload-panel {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 10px 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);

	&__head {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	&__spacer {
		flex: 1;
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: 4px;
		max-height: 220px;
		margin: 0;
		padding: 0;
		overflow-y: auto;
		list-style: none;
	}

	&__item {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	&__name {
		flex: 0 1 auto;
		min-width: 0;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__hint {
		flex: 1 1 auto;
		color: var(--color-text-maxcontrast);
		font-size: .9em;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__state {
		margin-inline-start: auto;
		color: var(--color-text-maxcontrast);

		&--error {
			color: var(--color-error-text);
		}
	}
}
</style>
