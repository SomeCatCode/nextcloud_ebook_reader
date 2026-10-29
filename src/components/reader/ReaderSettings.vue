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
				<span>{{ t('ebookreader', 'Display') }}</span>
				<div class="reader-settings__group">
					<NcButton :variant="!longstrip ? 'primary' : 'secondary'" @click="setLongstrip(false)">
						{{ t('ebookreader', 'Pages') }}
					</NcButton>
					<NcButton :variant="longstrip ? 'primary' : 'secondary'" @click="setLongstrip(true)">
						{{ t('ebookreader', 'Long strip') }}
					</NcButton>
				</div>
				<span class="reader-settings__hint">{{ t('ebookreader', 'Long strip: all pages in one continuous vertical scroll (webtoons). Saved for this book.') }}</span>
			</div>
			<div v-if="!longstrip" class="reader-settings__row">
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
			<div v-if="!longstrip" class="reader-settings__row">
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
						{{ longstrip ? t('ebookreader', 'Original width') : t('ebookreader', 'Fit page') }}
					</NcButton>
					<NcButton :variant="model.comicZoom === 'fit-width' ? 'primary' : 'secondary'" @click="set('comicZoom', 'fit-width')">
						{{ t('ebookreader', 'Fit width') }}
					</NcButton>
				</div>
			</div>
			<label class="reader-settings__row">
				<span>{{ t('ebookreader', 'Brightness') }}: {{ model.comicBrightness }}%</span>
				<input
					type="range"
					min="50"
					max="200"
					step="5"
					:value="model.comicBrightness"
					@input="set('comicBrightness', Number(($event.target as HTMLInputElement).value))">
			</label>
			<label class="reader-settings__row">
				<span>{{ t('ebookreader', 'Contrast') }}: {{ model.comicContrast }}%</span>
				<input
					type="range"
					min="50"
					max="200"
					step="5"
					:value="model.comicContrast"
					@input="set('comicContrast', Number(($event.target as HTMLInputElement).value))">
			</label>
			<div class="reader-settings__row">
				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="model.comicGrayscale"
					@update:modelValue="set('comicGrayscale', $event)">
					{{ t('ebookreader', 'Grayscale') }}
				</NcCheckboxRadioSwitch>
				<NcButton
					v-if="model.comicBrightness !== 100 || model.comicContrast !== 100 || model.comicGrayscale"
					variant="tertiary"
					@click="resetImage">
					{{ t('ebookreader', 'Reset image') }}
				</NcButton>
				<span class="reader-settings__hint">{{ t('ebookreader', 'Brightness, contrast and grayscale apply to the page images of all comics.') }}</span>
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'

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
	/** brightness of comic pages in percent (50-200) */
	comicBrightness: number
	/** contrast of comic pages in percent (50-200) */
	comicContrast: number
	comicGrayscale: boolean
}

const props = defineProps<{ model: ViewSettings, isComic: boolean, longstrip?: boolean }>()
const emit = defineEmits<{ change: [patch: Partial<ViewSettings>], longstrip: [value: boolean] }>()

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

/**
 * Sets brightness, contrast and grayscale back to the original image.
 */
function resetImage(): void {
	emit('change', { comicBrightness: 100, comicContrast: 100, comicGrayscale: false })
}

/**
 * Long strip is a property of the book (not a reader setting for all comics).
 *
 * @param value
 */
function setLongstrip(value: boolean): void {
	if (!!props.longstrip !== value) {
		emit('longstrip', value)
	}
}
</script>

<style scoped>
.reader-settings { display: flex; flex-direction: column; gap: 12px; padding: 16px; min-width: 280px; }
.reader-settings__row { display: flex; flex-direction: column; gap: 4px; }
.reader-settings__group { display: flex; flex-wrap: wrap; gap: 4px; }
.reader-settings__hint { color: var(--color-text-maxcontrast); font-size: 0.9em; max-width: 280px; }
</style>
