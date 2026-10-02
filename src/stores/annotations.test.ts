/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Annotation, Locator } from '../types.ts'

import { showError } from '@nextcloud/dialogs'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as api from '../services/api.ts'
import { kindOf, newUuid, useAnnotationsStore } from './annotations.ts'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../services/api.ts', async () => {
	class ConflictError extends Error {
		current: unknown
		constructor(current: unknown) {
			super('Conflict')
			this.current = current
		}
	}
	class ApiError extends Error {
		status: number
		constructor(status: number) {
			super('x')
			this.status = status
		}
	}
	return {
		ConflictError,
		ApiError,
		listAnnotations: vi.fn(),
		createAnnotation: vi.fn(),
		patchAnnotation: vi.fn(),
		deleteAnnotation: vi.fn(),
	}
})

const mocked = vi.mocked(api)

/**
 * @param uuid
 * @param total
 * @param extra
 */
function ann(uuid: string, total: number, extra: Partial<Annotation> = {}): Annotation {
	return {
		uuid,
		fileId: 7,
		type: 'highlight',
		locator: { href: 'ch1.xhtml', locations: { totalProgression: total, cfi: `epubcfi(/6/2!/4/2/${2 * Math.round(total * 100)}/1:0)` } },
		text: 'x',
		note: null,
		color: 'yellow',
		createdAt: 1,
		updatedAt: 1,
		clientUpdatedAt: 1,
		deleted: false,
		...extra,
	}
}

describe('annotations store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.resetAllMocks()
	})

	it('generates RFC 4122 v4 uuids', () => {
		expect(newUuid()).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
		expect(newUuid()).not.toBe(newUuid())
	})

	it('classifies by kind', () => {
		expect(kindOf({ type: 'bookmark', note: 'x' })).toBe('bookmark')
		expect(kindOf({ type: 'note', note: 'x' })).toBe('note')
		expect(kindOf({ type: 'note', note: null })).toBe('highlight')
		expect(kindOf({ type: 'highlight', note: 'x' })).toBe('note')
	})

	it('loads, groups and sorts by position', async () => {
		mocked.listAnnotations.mockResolvedValue([
			ann('b', 0.5),
			ann('a', 0.1),
			ann('n', 0.3, { type: 'note', note: 'hi' }),
			ann('m', 0.2, { type: 'bookmark', color: null }),
		])
		const s = useAnnotationsStore()
		await s.load(7)
		expect(s.highlights.map((a) => a.uuid)).toEqual(['a', 'b'])
		expect(s.notes.map((a) => a.uuid)).toEqual(['n'])
		expect(s.bookmarks.map((a) => a.uuid)).toEqual(['m'])
		expect(s.drawable.map((d) => d.id)).toEqual(['a', 'n', 'b'].sort((x, y) => ['b', 'a', 'n'].indexOf(x) - ['b', 'a', 'n'].indexOf(y)))
		expect(s.drawable.find((d) => d.id === 'n')?.hasNote).toBe(true)
	})

	it('shows an error toast when loading fails', async () => {
		mocked.listAnnotations.mockRejectedValue(new Error('x'))
		await useAnnotationsStore().load(7)
		expect(showError).toHaveBeenCalled()
	})

	it('creates optimistically and replaces the draft with the server row', async () => {
		const s = useAnnotationsStore()
		await s.load(7).catch(() => undefined)
		mocked.createAnnotation.mockImplementation(async (_id, body) => ann(body.uuid ?? '', 0.4, { updatedAt: 99 }))
		const loc: Locator = { href: 'ch1.xhtml', locations: { cfi: 'epubcfi(/6/2!/4/2,/1:0,/1:5)', totalProgression: 0.4 } }
		const p = s.create({ type: 'highlight', locator: loc, text: 'hello', color: 'green' })
		expect(s.items).toHaveLength(1)
		expect(s.items[0].color).toBe('green')
		const saved = await p
		expect(s.items).toHaveLength(1)
		expect(s.items[0].updatedAt).toBe(99)
		expect(saved.uuid).toBe(s.items[0].uuid)
		expect(mocked.createAnnotation.mock.calls[0][1].text).toBe('hello')
	})

	it('cuts text and note to the server limits', async () => {
		const s = useAnnotationsStore()
		mocked.createAnnotation.mockImplementation(async (_id, body) => ann(body.uuid ?? '', 0.1))
		await s.create({ type: 'note', locator: { href: 'a' }, text: 'x'.repeat(3000), note: 'y'.repeat(20000) })
		const body = mocked.createAnnotation.mock.calls[0][1]
		expect(body.text).toHaveLength(2000)
		expect(body.note).toHaveLength(10000)
	})

	it('removes the draft and toasts when creating fails', async () => {
		const s = useAnnotationsStore()
		mocked.createAnnotation.mockRejectedValue(new Error('boom'))
		await expect(s.create({ type: 'bookmark', locator: { href: 'a' } })).rejects.toThrow('boom')
		expect(s.items).toHaveLength(0)
		expect(showError).toHaveBeenCalledTimes(1)
	})

	it('takes the server version on a conflict', async () => {
		const s = useAnnotationsStore()
		const current = ann('srv', 0.9, { note: 'server' })
		mocked.createAnnotation.mockRejectedValue(new api.ConflictError(current))
		await s.create({ type: 'highlight', locator: { href: 'a' } })
		expect(s.items).toEqual([current])
		expect(showError).not.toHaveBeenCalled()
	})

	it('updates optimistically and reverts on failure', async () => {
		mocked.listAnnotations.mockResolvedValue([ann('a', 0.1)])
		const s = useAnnotationsStore()
		await s.load(7)
		mocked.patchAnnotation.mockRejectedValue(new Error('boom'))
		const p = s.update('a', { note: 'my note', color: 'blue' })
		expect(s.byUuid('a')?.note).toBe('my note')
		expect(s.notes).toHaveLength(1)
		await p
		expect(s.byUuid('a')?.note).toBeNull()
		expect(s.byUuid('a')?.color).toBe('yellow')
		expect(showError).toHaveBeenCalled()
	})

	it('clears a note with an empty string', async () => {
		mocked.listAnnotations.mockResolvedValue([ann('a', 0.1, { note: 'x', type: 'note' })])
		const s = useAnnotationsStore()
		await s.load(7)
		mocked.patchAnnotation.mockImplementation(async (uuid, body) => ann(uuid, 0.1, { note: body.note || null }))
		await s.update('a', { note: '' })
		expect(mocked.patchAnnotation.mock.calls[0][1].note).toBe('')
		expect(s.byUuid('a')?.note).toBeNull()
	})

	it('removes optimistically, restores on failure, ignores 404', async () => {
		mocked.listAnnotations.mockResolvedValue([ann('a', 0.1), ann('b', 0.2)])
		const s = useAnnotationsStore()
		await s.load(7)
		mocked.deleteAnnotation.mockRejectedValueOnce(new Error('boom'))
		const p = s.remove('a')
		expect(s.items.map((x) => x.uuid)).toEqual(['b'])
		await p
		expect(s.items.map((x) => x.uuid).sort()).toEqual(['a', 'b'])
		expect(showError).toHaveBeenCalledTimes(1)

		mocked.deleteAnnotation.mockRejectedValueOnce(new api.ApiError(404, 'gone'))
		await s.remove('b')
		expect(s.byUuid('b')).toBeUndefined()
		expect(showError).toHaveBeenCalledTimes(1)
	})

	it('toggles a bookmark on and off', async () => {
		const s = useAnnotationsStore()
		mocked.createAnnotation.mockImplementation(async (_id, body) => ann(body.uuid ?? '', 0.1, { type: 'bookmark', color: null }))
		mocked.deleteAnnotation.mockResolvedValue(ann('x', 0.1))
		const loc: Locator = { href: 'a', locations: { position: 3 } }
		expect(await s.toggleBookmark(loc, () => false)).toBe('added')
		expect(s.bookmarks).toHaveLength(1)
		expect(await s.toggleBookmark(loc, () => true)).toBe('removed')
		expect(s.bookmarks).toHaveLength(0)
	})

	it('bookmarks are not drawn in the book', async () => {
		mocked.listAnnotations.mockResolvedValue([ann('m', 0.2, { type: 'bookmark', color: null })])
		const s = useAnnotationsStore()
		await s.load(7)
		expect(s.drawable).toEqual([])
	})
})
