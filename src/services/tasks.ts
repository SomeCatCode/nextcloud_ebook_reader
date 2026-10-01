/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Task } from '../types.ts'

import { ApiError, ConflictError, getTask } from './api.ts'

/** A task ended with status `failed` (other than a conflict). `code` = 403/413/422 if the server sent one. */
export class TaskFailedError extends Error {
	public readonly code: number | undefined
	public readonly task: Task

	constructor(task: Task) {
		super(task.error || 'The task failed')
		this.name = 'TaskFailedError'
		this.code = task.result?.code
		this.task = task
	}
}

export interface PollOptions {
	onUpdate?: (task: Task) => void
	signal?: AbortSignal
	/** test seams */
	fetchTask?: (id: number) => Promise<Task>
	sleep?: (ms: number) => Promise<void>
	/** monotonic clock in ms */
	now?: () => number
}

const FAST_MS = 1000
const SLOW_MS = 2000
/** after this long the polling interval grows */
const FAST_PHASE_MS = 30_000
const MAX_TRANSIENT_ERRORS = 5

/**
 * Interval until the next poll.
 *
 * @param elapsedMs time since polling started
 */
export function pollInterval(elapsedMs: number): number {
	return elapsedMs < FAST_PHASE_MS ? FAST_MS : SLOW_MS
}

/**
 * @param ms
 * @param signal
 */
function defaultSleep(ms: number, signal?: AbortSignal): Promise<void> {
	return new Promise((resolve, reject) => {
		if (signal?.aborted) {
			reject(new DOMException('Aborted', 'AbortError'))
			return
		}
		let onAbort: () => void = () => {}
		const timer = setTimeout(() => {
			signal?.removeEventListener('abort', onAbort)
			resolve()
		}, ms)
		onAbort = (): void => {
			clearTimeout(timer)
			reject(new DOMException('Aborted', 'AbortError'))
		}
		signal?.addEventListener('abort', onAbort, { once: true })
	})
}

/**
 * Polls a task until it is `done` (resolves with the task). `failed` rejects with ConflictError
 * (result.code 409) or TaskFailedError; an unknown task (404) rejects at once; a few transient
 * network errors in a row are tolerated.
 *
 * @param taskId
 * @param options
 */
export async function pollTask(taskId: number, options: PollOptions = {}): Promise<Task> {
	const fetchTask = options.fetchTask ?? getTask
	const sleep = options.sleep ?? ((ms: number) => defaultSleep(ms, options.signal))
	const now = options.now ?? (() => Date.now())
	const started = now()
	let errors = 0
	for (;;) {
		if (options.signal?.aborted) {
			throw new DOMException('Aborted', 'AbortError')
		}
		let task: Task | null = null
		try {
			task = await fetchTask(taskId)
			errors = 0
		} catch (e) {
			if (e instanceof ApiError && e.status >= 400 && e.status < 500 && e.status !== 429) {
				throw e
			}
			if (++errors > MAX_TRANSIENT_ERRORS) {
				throw e
			}
		}
		if (task) {
			options.onUpdate?.(task)
			if (task.status === 'done') {
				return task
			}
			if (task.status === 'failed') {
				if (task.result?.code === 409) {
					throw new ConflictError(task.result, task.error ?? 'Conflict')
				}
				throw new TaskFailedError(task)
			}
		}
		await sleep(pollInterval(now() - started))
	}
}
