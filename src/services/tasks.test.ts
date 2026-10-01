/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Task } from '../types.ts'

import { describe, expect, it, vi } from 'vitest'
import { ApiError, ConflictError } from './api.ts'
import { pollInterval, pollTask, TaskFailedError } from './tasks.ts'

vi.mock('@nextcloud/axios', () => ({ default: {} }))
vi.mock('@nextcloud/files/dav', () => ({ defaultRemoteURL: '', defaultRootPath: '' }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (u: string) => u, generateUrl: (u: string) => u }))

/**
 * @param status
 * @param extra
 */
function task(status: Task['status'], extra: Partial<Task> = {}): Task {
	return { id: 1, fileId: 2, type: 'edit', status, progress: 0, step: '', result: null, error: null, createdAt: 0, updatedAt: 0, ...extra }
}

async function noSleep(): Promise<void> {}

describe('pollTask', () => {
	it('polls queued, running, done and reports every update', async () => {
		const seq = [task('queued'), task('running', { progress: 0.5 }), task('done', { progress: 1, result: { warnings: [] } })]
		const fetchTask = vi.fn(async () => seq.shift()!)
		const sleep = vi.fn(noSleep)
		const updates: string[] = []
		const done = await pollTask(1, { fetchTask, sleep, onUpdate: (tk) => updates.push(tk.status) })
		expect(done.status).toBe('done')
		expect(updates).toEqual(['queued', 'running', 'done'])
		expect(sleep).toHaveBeenCalledTimes(2)
		expect(sleep).toHaveBeenCalledWith(1000)
	})

	it('slows down after 30 s', () => {
		expect(pollInterval(0)).toBe(1000)
		expect(pollInterval(29_999)).toBe(1000)
		expect(pollInterval(30_000)).toBe(2000)
	})

	it('maps failed with code 409 to a ConflictError', async () => {
		const fetchTask = vi.fn(async () => task('failed', { error: 'changed', result: { code: 409 } }))
		await expect(pollTask(1, { fetchTask, sleep: noSleep })).rejects.toBeInstanceOf(ConflictError)
	})

	it('maps other failures to TaskFailedError with code and message', async () => {
		const fetchTask = vi.fn(async () => task('failed', { error: 'too large', result: { code: 413 } }))
		const err = await pollTask(1, { fetchTask, sleep: noSleep }).catch((e: unknown) => e)
		expect(err).toBeInstanceOf(TaskFailedError)
		expect((err as TaskFailedError).code).toBe(413)
		expect((err as TaskFailedError).message).toBe('too large')
	})

	it('tolerates transient errors', async () => {
		let n = 0
		const flaky = vi.fn(async () => {
			if (n++ < 2) {
				throw new Error('network')
			}
			return task('done')
		})
		await expect(pollTask(1, { fetchTask: flaky, sleep: noSleep })).resolves.toMatchObject({ status: 'done' })
	})

	it('gives up on 404 at once and after repeated network errors', async () => {
		const gone = vi.fn(async (): Promise<Task> => {
			throw new ApiError(404, 'nope')
		})
		await expect(pollTask(1, { fetchTask: gone, sleep: noSleep })).rejects.toBeInstanceOf(ApiError)
		expect(gone).toHaveBeenCalledTimes(1)
		const down = vi.fn(async (): Promise<Task> => {
			throw new Error('network')
		})
		await expect(pollTask(1, { fetchTask: down, sleep: noSleep })).rejects.toThrow('network')
		expect(down).toHaveBeenCalledTimes(6)
	})

	it('stops when aborted', async () => {
		const ctl = new AbortController()
		const fetchTask = vi.fn(async () => task('running'))
		const sleep = vi.fn(async () => {
			ctl.abort()
		})
		await expect(pollTask(1, { fetchTask, sleep, signal: ctl.signal })).rejects.toMatchObject({ name: 'AbortError' })
	})
})
