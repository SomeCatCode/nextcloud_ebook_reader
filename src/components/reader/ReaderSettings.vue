<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="reader-settings">
		<div class="reader-settings__row">
			<span>{{ t('ebookreader', 'Theme') }}</span>
			<div class="reader-settings__group">
				<NcButton
					v-for="th in themes"
					:key="th.id"
					:variant="model.theme === th.id ? 'primary' : 'secondary'"
					@click="set('theme', th.id)">
					{{ th.label }}
				</NcButton>
			</div>
		</div>
		<template v-if="!isComic">
			<label class="reader-settings__row">
				<span>{{ t('ebookreader', 'Font size') }}: {{ model.fontSize }}%</span>
				<input
					type="range"
					min="70"
					max="220"
					step="5"
					:value="model.fontSize"
					@input="set('fontSize', Number(($event.target as HTMLInputElement).value))">
			</label>
			<label class="reader-settings__row">
				<span>{{ t('ebookreader', 'Line height') }}: {{ model.lineHeight }}</span>
				<input
					type="range"
					min="1.1"
					max="2.2"
					step="0.1"
					:value="model.lineHeight"
					@input="set('lineHeight', Number(($event.target as HTMLInputElement).value))">
			</label>
			<label class="reader-settings__row">
				<span>{{ t('ebookreader', 'Margin') }}: {{ model.margin }}px</span>
				<input
					type="range"
					min="0"
					max="160"
					step="8"
					:value="model.margin"
					@input="set('margin', Number(($event.target as HTMLInputElement).value))">
			</label>
			<label class="reader-settings__row">
				<span>{{ t('ebookreader', 'Font') }}</span>
				<select :value="model.fontFamily" @change="set('fontFamily', ($event.target as HTMLSelectElement).value)">
					<option v-for="f in fonts" :key="f.id" :value="f.id">
						{{ f.label }}
					</option>
				</select>
			</label>
			<div class="reader-settings__row">
				<span>{{ t('ebookreader', 'Layout') }}</span>
				<div class="reader-settings__group">
					<NcButton :variant="model.flow !== 'scrolled' ? 'primary' : 'secondary'" @click="set('flow', 'paginated')">
						{{ t('ebookreader', 'Pages') }}
					</NcButton>
					<NcButton :variant="model.flow === 'scrolled' ? 'primary' : 'secondary'" @click="set('flow', 'scrolled')">
						{{ t('ebookreader', 'Scroll') }}
					</NcButton>
				</div>
			</div>
			<div v-if="model.flow !== 'scrolled'" class="reader-settings__row">
				<span>{{ t('ebookreader', 'Columns') }}</span>
				<div class="reader-settings__group">
					<NcButton :variant="model.maxColumns === 1 ? 'primary' : 'secondary'" @click="set('maxColumns', 1)">
						1
					</NcButton>
					<NcButton :variant="model.maxColumns !== 1 ? 'primary' : 'secondary'" @click="set('maxColumns', 2)">
						2
					</NcButton>
				</div>
			</div>
		</template>
		<template v-else>
			<div class="reader-settings__row">
				<span>{{ t('ebookreader', 'Pages per view') }}</span>
				<div class="reader-settings__group">
					<NcButton :variant="model.comicSpread !== 'double' ? 'primary' : 'secondary'" @click="set('comicSpread', 'single')">
						{{ t('ebookreader', 'Single') }}
					</NcButton>
					<NcButton :variant="model.comicSpread === 'double' ? 'primary' : 'secondary'" @click="set('comicSpread', 'double')">
						{{ t('ebookreader', 'Double') }}
					</NcButton>
				</div>
			</div>
			<div class="reader-settings__row">
				<span>{{ t('ebookreader', 'Reading direction') }}</span>
				<div class="reader-settings__group">
					<NcButton :variant="!model.comicRtl ? 'primary' : 'secondary'" @click="set('comicRtl', false)">
						{{ t('ebookreader', 'Left to right') }}
					</NcButton>
					<NcButton :variant="model.comicRtl ? 'primary' : 'secondary'" @click="set('comicRtl', true)">
						{{ t('ebookreader', 'Right to left') }}
					</NcButton>
				</div>
			</div>
			<div class="reader-settings__row">
				<span>{{ t('ebookreader', 'Zoom') }}</span>
				<div class="reader-settings__group">
					<NcButton :variant="model.comicZoom !== 'fit-width' ? 'primary' : 'secondary'" @click="set('comicZoom', 'fit-page')">
						{{ t('ebookreader', 'Fit page') }}
					</NcButton>
					<NcButton :variant="model.comicZoom === 'fit-width' ? 'primary' : 'secondary'" @click="set('comicZoom', 'fit-width')">
						{{ t('ebookreader', 'Fit width') }}
					</NcButton>
				</div>
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'

export interface ViewSettings {
	theme: 'auto' | 'light' | 'dark' | 'sepia'
	fontSize: number
	fontFamily: string
	lineHeight: number
	margin: number
	flow: 'paginated' | 'scrolled'
	maxColumns: number
	comicSpread: 'single' | 'double'
	comicRtl: boolean
	comicZoom: 'fit-page' | 'fit-width'
}

const props = defineProps<{ model: ViewSettings, isComic: boolean }>()
const emit = defineEmits<{ change: [patch: Partial<ViewSettings>] }>()

const themes = [
	{ id: 'auto', label: t('ebookreader', 'Auto') },
	{ id: 'light', label: t('ebookreader', 'Light') },
	{ id: 'sepia', label: t('ebookreader', 'Sepia') },
	{ id: 'dark', label: t('ebookreader', 'Dark') },
] as const

const fonts = [
	{ id: 'default', label: t('ebookreader', 'Book default') },
	{ id: 'Georgia, serif', label: 'Georgia' },
	{ id: 'Palatino, "Palatino Linotype", serif', label: 'Palatino' },
	{ id: 'system-ui, sans-serif', label: 'Sans-serif' },
	{ id: '"Courier New", monospace', label: 'Monospace' },
]

/**
 * @param key
 * @param value
 */
function set<K extends keyof ViewSettings>(key: K, value: ViewSettings[K]): void {
	if (props.model[key] !== value) {
		emit('change', { [key]: value } as Partial<ViewSettings>)
	}
}
</script>

<style scoped>
.reader-settings { display: flex; flex-direction: column; gap: 12px; padding: 16px; min-width: 280px; }
.reader-settings__row { display: flex; flex-direction: column; gap: 4px; }
.reader-settings__group { display: flex; flex-wrap: wrap; gap: 4px; }
</style>
