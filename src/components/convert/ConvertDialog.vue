<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('ebookreader', 'Convert format')"
		:open="true"
		size="large"
		:buttons="buttons"
		:closeOnClickOutside="false"
		@update:open="(open: boolean) => !open && requestClose()">
		<div class="convert">
			<NcLoadingIcon v-if="phase === 'loading'" :size="32" class="convert__center" />

			<template v-else-if="phase === 'choose' || phase === 'error'">
				<section v-if="current" class="convert__current">
					<h3>{{ t('ebookreader', 'Current format: {format}', { format: current.name }) }}</h3>
					<p>{{ current.summary }}</p>
					<p v-if="current.cons.length" class="convert__muted">
						{{ current.compatibility }}
					</p>
				</section>

				<NcNoteCard v-if="phase === 'choose' && targets.length === 0" type="info">
					{{ t('ebookreader', 'Only comic books (CBZ, CBR, CB7, CBT) can be converted.') }}
				</NcNoteCard>

				<template v-else-if="targets.length > 0">
					<h3>{{ t('ebookreader', 'Convert to') }}</h3>
					<div class="convert__cards" role="radiogroup" :aria-label="t('ebookreader', 'Target format')">
						<label
							v-for="target in targets"
							:key="target.format"
							class="convert__card"
							:class="{
								'convert__card--selected': selected === target.format,
								'convert__card--disabled': target.mode === 'unavailable',
							}">
							<input
								v-model="selected"
								type="radio"
								name="convert-target"
								class="convert__radio"
								:value="target.format"
								:disabled="target.mode === 'unavailable' || busy">
							<span class="convert__card-head">
								<strong>{{ infoOf(target.format).name }}</strong>
								<span v-if="isRecommended(target.format, source)" class="convert__badge convert__badge--good">
									{{ t('ebookreader', 'Recommended') }}
								</span>
								<span v-if="target.mode === 'client'" class="convert__badge" :title="t('ebookreader', 'The server cannot convert this, your browser does it')">
									{{ t('ebookreader', 'In the browser') }}
								</span>
							</span>
							<span class="convert__summary">{{ infoOf(target.format).summary }}</span>
							<span v-if="target.mode === 'unavailable'" class="convert__reason">
								{{ translateReason(target.reason) }}
							</span>
							<template v-else>
								<ul class="convert__list convert__list--pros">
									<li v-for="pro in infoOf(target.format).pros" :key="pro">{{ pro }}</li>
								</ul>
								<ul class="convert__list convert__list--cons">
									<li v-for="con in infoOf(target.format).cons" :key="con">{{ con }}</li>
								</ul>
								<span class="convert__muted">{{ infoOf(target.format).compatibility }}</span>
							</template>
						</label>
					</div>

					<NcButton variant="tertiary" @click="showCompare = !showCompare">
						{{ showCompare ? t('ebookreader', 'Hide comparison') : t('ebookreader', 'Compare formats') }}
					</NcButton>
					<div v-if="showCompare" class="convert__table-wrap">
						<table class="convert__table">
							<thead>
								<tr>
									<th />
									<th v-for="key in FORMAT_KEYS" :key="key">
										{{ infoOf(key).name }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in rows" :key="row.key">
									<th scope="row">
										{{ row.label }}
									</th>
									<td v-for="key in FORMAT_KEYS" :key="key">
										{{ row.cells[key] }}
									</td>
								</tr>
							</tbody>
						</table>
					</div>

					<NcCheckboxRadioSwitch v-model="deleteOriginal" :disabled="busy">
						{{ t('ebookreader', 'Delete the original after a successful conversion') }}
					</NcCheckboxRadioSwitch>
				</template>

				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>
			</template>

			<template v-else-if="phase === 'running'">
				<p>
					{{ t('ebookreader', 'Converting “{title}” to {format}', { title: book.title ?? book.path, format: selectedName }) }}
				</p>
				<template v-if="mode === 'client'">
					<ol class="convert__steps">
						<li v-for="s in steps" :key="s.key" :class="'convert__step--' + stepState(s.key)">
							<NcIconSvgWrapper :path="stepState(s.key) === 'done' ? mdiCheckCircle : mdiCircleOutline" :size="20" />
							<span>{{ s.label }}</span>
							<span v-if="stepState(s.key) === 'active' && progress.total > 0" class="convert__muted">
								{{ progressLabel }}
							</span>
						</li>
					</ol>
					<NcProgressBar
						v-if="progress.total > 0"
						:value="Math.round(100 * progress.done / progress.total)"
						size="medium" />
					<NcLoadingIcon v-else :size="28" class="convert__center" />
				</template>
				<TaskProgress
					v-else
					:progress="task?.progress ?? 0"
					:step="task?.step"
					:status="task?.status"
					:hint="t('ebookreader', 'The server is converting the comic. You can close this dialog, the conversion keeps running on the server.')" />
			</template>
		</div>
	</NcDialog>
	<LargeDownloadDialog
		v-if="largeDownload.pending.value"
		:sizeBytes="largeDownload.pending.value.size"
		@confirm="largeDownload.answer(true)"
		@cancel="largeDownload.answer(false)" />
</template>

<script setup lang="ts">
import type { ConvertFormat, ConvertMode, ConvertStep, ConvertTarget } from '../../convert/types.ts'
import type { Book, Task } from '../../types.ts'

import { mdiCheckCircle, mdiCircleOutline } from '@mdi/js'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import LargeDownloadDialog from '../common/LargeDownloadDialog.vue'
import TaskProgress from '../common/TaskProgress.vue'
import { convertInBrowser } from '../../convert/clientConvert.ts'
import { ConvertError, getTargets } from '../../convert/convertApi.ts'
import { comparisonRows, FORMAT_KEYS, formatInfo, isConvertFormat, translateReason } from '../../convert/formats.ts'
import { defaultTarget, isRecommended } from '../../convert/targets.ts'
import { ApiError, ConflictError, convertAsync } from '../../services/api.ts'
import { DownloadDeclinedError, ensureDownloadConfirmed } from '../../services/largeDownload.ts'
import { pollTask, TaskFailedError } from '../../services/tasks.ts'
import { useLargeDownloadConfirm } from '../../services/useLargeDownloadConfirm.ts'

const props = defineProps<{ book: Book }>()
const emit = defineEmits<{
	close: []
	converted: [fileId: number]
}>()

const phase = ref<'loading' | 'choose' | 'running' | 'error'>('loading')
const source = ref<string>(props.book.format)
const targets = ref<ConvertTarget[]>([])
const selected = ref<ConvertFormat | null>(null)
const DELETE_ORIGINAL_KEY = 'ebookreader.convert.deleteOriginal'
/** Remembered per browser: whether the original should go to the trash after converting */
const deleteOriginal = ref(readDeleteOriginal())
watch(deleteOriginal, (v) => {
	try {
		localStorage.setItem(DELETE_ORIGINAL_KEY, v ? '1' : '0')
	} catch {
		// storage unavailable (private mode): the choice is just not remembered
	}
})

/**
 *
 */
function readDeleteOriginal(): boolean {
	try {
		return localStorage.getItem(DELETE_ORIGINAL_KEY) === '1'
	} catch {
		return false
	}
}
const showCompare = ref(false)
const error = ref('')
const mode = ref<ConvertMode>('server')
const step = ref<ConvertStep | null>(null)
const progress = ref({ done: 0, total: 0 })
const task = ref<Task | null>(null)
const largeDownload = useLargeDownloadConfirm()
let abort: AbortController | null = null

const busy = computed(() => phase.value === 'running')
const current = computed(() => isConvertFormat(source.value) ? formatInfo(source.value) : null)
const rows = computed(() => comparisonRows())
const selectedName = computed(() => selected.value ? formatInfo(selected.value).name : '')
const selectedTarget = computed(() => targets.value.find((x) => x.format === selected.value) ?? null)

const steps = computed<{ key: ConvertStep, label: string }[]>(() => [
	{ key: 'download', label: t('ebookreader', 'Downloading the comic') },
	{ key: 'convert', label: t('ebookreader', 'Converting the pages') },
	{ key: 'upload', label: t('ebookreader', 'Uploading the result') },
	{ key: 'index', label: t('ebookreader', 'Adding it to the library') },
])
const progressLabel = computed(() => {
	if (step.value === 'download' || step.value === 'upload') {
		return `${(progress.value.done / 1048576).toFixed(1)} / ${(progress.value.total / 1048576).toFixed(1)} MB`
	}
	return `${progress.value.done} / ${progress.value.total}`
})

const buttons = computed(() => [
	{
		label: busy.value && mode.value === 'client' ? t('ebookreader', 'Cancel conversion') : t('ebookreader', 'Close'),
		variant: 'tertiary' as const,
		callback: (): void => {
			requestClose()
		},
	},
	{
		label: t('ebookreader', 'Convert'),
		variant: 'primary' as const,
		disabled: busy.value || phase.value === 'loading' || selectedTarget.value === null || selectedTarget.value.mode === 'unavailable',
		callback: (): false => {
			void run()
			return false
		},
	},
])

/**
 * @param key
 */
function infoOf(key: ConvertFormat): ReturnType<typeof formatInfo> {
	return formatInfo(key)
}

/**
 * @param key
 */
function stepState(key: ConvertStep): 'done' | 'active' | 'todo' {
	const order: ConvertStep[] = ['download', 'convert', 'upload', 'index']
	const now = step.value ? order.indexOf(step.value) : -1
	const idx = order.indexOf(key)
	return idx < now ? 'done' : idx === now ? 'active' : 'todo'
}

/** Closing while a browser conversion runs cancels it; a server conversion keeps running on the server. */
function requestClose(): void {
	if (busy.value && mode.value === 'client') {
		abort?.abort()
		return
	}
	emit('close')
}

/**
 * @param e
 */
function describe(e: unknown): string {
	const status = e instanceof ConvertError || e instanceof ApiError
		? e.status
		: e instanceof ConflictError
			? 409
			: e instanceof TaskFailedError ? e.code : undefined
	if (status === 409) {
		return t('ebookreader', 'A file with the name of the converted comic already exists. Rename or remove it first.')
	}
	if (status === 403) {
		return t('ebookreader', 'You are not allowed to create the converted file in this folder.')
	}
	if (status === 413) {
		return t('ebookreader', 'The file is too large to convert on the server. Try again to use the browser.')
	}
	if (status === 422) {
		return t('ebookreader', 'The comic could not be read. The file may be damaged.')
	}
	return e instanceof Error && e.message ? e.message : t('ebookreader', 'The conversion failed.')
}

/** Converts on the server or in the browser, depending on the mode of the chosen target. */
async function run(): Promise<void> {
	const target = selectedTarget.value
	if (!target || target.mode === 'unavailable') {
		return
	}
	error.value = ''
	mode.value = target.mode
	step.value = target.mode === 'client' ? 'download' : null
	progress.value = { done: 0, total: 0 }
	phase.value = 'running'
	abort = new AbortController()
	try {
		let fileId: number
		if (target.mode === 'server') {
			task.value = null
			const started = await convertAsync<{ fileId: number }>(props.book.fileId, { target: target.format, deleteOriginal: deleteOriginal.value })
			if ('sync' in started) {
				// older server: converted synchronously
				fileId = started.sync.fileId
			} else {
				const done = await pollTask(started.taskId, {
					signal: abort.signal,
					onUpdate: (tk) => {
						task.value = tk
					},
				})
				fileId = done.result?.fileId ?? done.result?.book?.fileId ?? props.book.fileId
			}
		} else {
			await ensureDownloadConfirmed(props.book, largeDownload.ask)
			const res = await convertInBrowser(props.book, target.format, {
				deleteOriginal: deleteOriginal.value,
				signal: abort.signal,
				onStep: (s) => {
					step.value = s
					progress.value = { done: 0, total: 0 }
				},
				onProgress: (s, done, total) => {
					step.value = s
					progress.value = { done, total }
				},
			})
			fileId = res.fileId
			if (deleteOriginal.value && !res.originalDeleted) {
				showWarning(t('ebookreader', 'The converted file was created, but it could not be confirmed in the library. The original was kept.'))
			}
		}
		showSuccess(t('ebookreader', 'Converted to {format}', { format: selectedName.value }))
		emit('converted', fileId)
	} catch (e) {
		if ((e as Error)?.name === 'AbortError' || e instanceof DownloadDeclinedError) {
			phase.value = 'choose'
			return
		}
		error.value = describe(e)
		phase.value = 'error'
		showError(t('ebookreader', 'The conversion failed'))
	} finally {
		abort = null
	}
}

onMounted(async () => {
	try {
		const res = await getTargets(props.book.fileId)
		source.value = res.source
		targets.value = res.targets
		selected.value = defaultTarget(res.targets)
		phase.value = 'choose'
	} catch (e) {
		error.value = describe(e)
		phase.value = 'error'
	}
})

onBeforeUnmount(() => {
	largeDownload.answer(false)
	abort?.abort()
})
</script>

<style scoped lang="scss">
.convert {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 240px;

	h3 {
		margin: 0;
		font-size: 1.05em;
	}

	p {
		margin: 0;
	}

	&__center {
		align-self: center;
	}

	&__muted {
		color: var(--color-text-maxcontrast);
	}

	&__cards {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
		gap: 12px;
	}

	&__card {
		position: relative;
		display: flex;
		flex-direction: column;
		gap: 6px;
		padding: 12px;
		border: 2px solid var(--color-border);
		border-radius: var(--border-radius-large);
		background: var(--color-main-background);
		cursor: pointer;

		&:hover:not(&--disabled) {
			border-color: var(--color-primary-element);
		}

		&--selected {
			border-color: var(--color-primary-element);
			background: var(--color-primary-element-light);
		}

		&--disabled {
			cursor: not-allowed;
			opacity: 0.7;
			background: var(--color-background-dark);
		}

		&:focus-within {
			outline: 2px solid var(--color-main-text);
			outline-offset: 2px;
		}
	}

	&__radio {
		position: absolute;
		inset-block-start: 12px;
		inset-inline-end: 12px;
		margin: 0;
	}

	&__card-head {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
		padding-inline-end: 24px;
	}

	&__badge {
		padding: 0 8px;
		border-radius: var(--border-radius-pill);
		background: var(--color-background-dark);
		font-size: 0.85em;

		&--good {
			background: color-mix(in srgb, var(--color-success) 25%, transparent);
		}
	}

	&__summary {
		color: var(--color-text-maxcontrast);
	}

	&__reason {
		color: var(--color-warning-text, var(--color-text-maxcontrast));
		font-weight: 600;
	}

	&__list {
		margin: 0;
		padding: 0;
		list-style: none;

		li {
			position: relative;
			padding-inline-start: 18px;
			margin-block: 2px;

			&::before {
				position: absolute;
				inset-inline-start: 0;
				font-weight: 700;
			}
		}

		&--pros li::before {
			content: '+';
			color: var(--color-success-text, var(--color-success));
		}

		&--cons li::before {
			content: '−';
			color: var(--color-error-text, var(--color-error));
		}
	}

	&__table-wrap {
		overflow-x: auto;
	}

	&__table {
		width: 100%;
		border-collapse: collapse;

		th,
		td {
			padding: 4px 8px;
			text-align: start;
			border-bottom: 1px solid var(--color-border);
		}
	}

	&__steps {
		margin: 0;
		padding: 0;
		list-style: none;

		li {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 4px 0;
		}
	}

	&__step--todo {
		color: var(--color-text-maxcontrast);
	}

	&__step--active {
		font-weight: 600;
	}

	&__step--done {
		color: var(--color-success-text, var(--color-success));
	}
}
</style>
