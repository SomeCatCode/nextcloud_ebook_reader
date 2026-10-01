<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcNoteCard v-if="tasks.length" type="info" class="tasks-banner">
		<p class="tasks-banner__title">
			{{ n('ebookreader', '{count} task running on the server…', '{count} tasks running on the server…', tasks.length, { count: tasks.length }) }}
		</p>
		<TaskProgress :progress="averageProgress" :step="tasks[0]?.step" :status="tasks[0]?.status" />
	</NcNoteCard>
</template>

<script setup lang="ts">
import type { Task } from '../../types.ts'

import { n } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import TaskProgress from './TaskProgress.vue'
import { listActiveTasks } from '../../services/api.ts'

const emit = defineEmits<{ finished: [] }>()

const POLL_MS = 3000
const tasks = ref<Task[]>([])
const averageProgress = computed(() => tasks.value.length
	? tasks.value.reduce((sum, tk) => sum + tk.progress, 0) / tasks.value.length
	: 0)
let timer: number | undefined
let stopped = false

/** Polls every 3 s while tasks are active; checked once on mount (page reload hint). */
async function refresh(): Promise<void> {
	const before = tasks.value.length
	try {
		tasks.value = await listActiveTasks()
	} catch {
		// older server without tasks, or a hiccup: no banner
		tasks.value = []
	}
	if (stopped) {
		return
	}
	if (before > 0 && tasks.value.length === 0) {
		emit('finished')
	}
	if (tasks.value.length > 0) {
		timer = window.setTimeout(() => void refresh(), POLL_MS)
	}
}

onMounted(() => {
	void refresh()
})

onBeforeUnmount(() => {
	stopped = true
	window.clearTimeout(timer)
})
</script>

<style scoped lang="scss">
.tasks-banner__title {
	margin: 0 0 8px;
	font-weight: 600;
}
</style>
