/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { ReaderAnnotation } from '../../packages/reader-core/src/types.ts'
import type { Annotation, AnnotationColor, Locator } from '../types.ts'

import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { compareLocators } from '../../packages/reader-core/src/annotations.ts'
import { kindOf, newUuid } from '../services/annotationUtils.ts'
import * as api from '../services/api.ts'
import { ConflictError } from '../services/api.ts'

export { kindOf, newUuid }

export const MAX_TEXT = 2000
export const MAX_NOTE = 10000

export const useAnnotationsStore = defineStore('annotations', () => {
	const fileId = ref(0)
	const items = ref<Annotation[]>([])
	const loaded = ref(false)

	const sorted = computed(() => [...items.value].sort((a, b) => compareLocators(a.locator, b.locator) || a.createdAt - b.createdAt))
	const highlights = computed(() => sorted.value.filter((a) => kindOf(a) === 'highlight'))
	const notes = computed(() => sorted.value.filter((a) => kindOf(a) === 'note'))
	const bookmarks = computed(() => sorted.value.filter((a) => kindOf(a) === 'bookmark'))
	/** What the reader draws into the book: highlights and notes that have a CFI. */
	const drawable = computed<ReaderAnnotation[]>(() => items.value
		.filter((a) => a.type !== 'bookmark' && !!a.locator.locations?.cfi)
		.map((a) => ({ id: a.uuid, cfi: a.locator.locations!.cfi!, color: a.color, hasNote: !!a.note })))

	/**
	 * @param uuid
	 */
	function byUuid(uuid: string): Annotation | undefined {
		return items.value.find((a) => a.uuid === uuid)
	}

	/**
	 * @param uuid
	 * @param next
	 */
	function replace(uuid: string, next: Annotation): void {
		items.value = items.value.map((a) => a.uuid === uuid ? next : a)
	}

	/**
	 * @param id
	 */
	async function load(id: number): Promise<void> {
		fileId.value = id
		items.value = []
		loaded.value = false
		try {
			const list = await api.listAnnotations(id)
			// a change made while loading wins over the list
			if (fileId.value === id) {
				const local = items.value
				items.value = [...list.filter((a) => !local.some((l) => l.uuid === a.uuid)), ...local]
				loaded.value = true
			}
		} catch {
			showError(t('ebookreader', 'Highlights and bookmarks could not be loaded'))
		}
	}

	/**
	 * Adds an annotation right away and stores it in the background.
	 *
	 * @param input
	 * @param input.type
	 * @param input.locator
	 * @param input.text selected text (cut to 2000 characters)
	 * @param input.note
	 * @param input.color
	 */
	async function create(input: { type: Annotation['type'], locator: Locator, text?: string | null, note?: string | null, color?: AnnotationColor | null }): Promise<Annotation> {
		const now = Date.now()
		const draft: Annotation = {
			uuid: newUuid(),
			fileId: fileId.value,
			type: input.type,
			locator: input.locator,
			text: input.text ? input.text.slice(0, MAX_TEXT) : null,
			note: input.note ? input.note.slice(0, MAX_NOTE) : null,
			color: input.type === 'bookmark' ? null : (input.color ?? 'yellow'),
			createdAt: now,
			updatedAt: now,
			clientUpdatedAt: now,
			deleted: false,
		}
		items.value = [...items.value, draft]
		try {
			const saved = await api.createAnnotation(draft.fileId, {
				uuid: draft.uuid,
				type: draft.type,
				locator: draft.locator,
				text: draft.text,
				note: draft.note,
				color: draft.color,
				clientUpdatedAt: now,
				createdAt: now,
			})
			replace(draft.uuid, saved)
			return saved
		} catch (e) {
			if (e instanceof ConflictError) {
				replace(draft.uuid, e.current as Annotation)
				return e.current as Annotation
			}
			items.value = items.value.filter((a) => a.uuid !== draft.uuid)
			showError(t('ebookreader', 'The annotation could not be saved'))
			throw e
		}
	}

	/**
	 * Changes note/color; reverts and shows an error when the server rejects it.
	 *
	 * @param uuid
	 * @param patch
	 * @param patch.note empty string removes the note
	 * @param patch.color
	 */
	async function update(uuid: string, patch: { note?: string, color?: AnnotationColor }): Promise<void> {
		const before = byUuid(uuid)
		if (!before) {
			return
		}
		const now = Date.now()
		const optimistic: Annotation = {
			...before,
			note: patch.note === undefined ? before.note : (patch.note === '' ? null : patch.note.slice(0, MAX_NOTE)),
			color: patch.color ?? before.color,
			clientUpdatedAt: now,
		}
		replace(uuid, optimistic)
		try {
			const saved = await api.patchAnnotation(uuid, {
				note: patch.note === undefined ? undefined : optimistic.note ?? '',
				color: patch.color,
				clientUpdatedAt: now,
			})
			replace(uuid, saved)
		} catch (e) {
			if (e instanceof ConflictError) {
				replace(uuid, e.current as Annotation)
				return
			}
			replace(uuid, before)
			showError(t('ebookreader', 'The annotation could not be saved'))
		}
	}

	/**
	 * @param uuid
	 */
	async function remove(uuid: string): Promise<void> {
		const before = byUuid(uuid)
		if (!before) {
			return
		}
		items.value = items.value.filter((a) => a.uuid !== uuid)
		try {
			await api.deleteAnnotation(uuid, Date.now())
		} catch (e) {
			if (e instanceof api.ApiError && e.status === 404) {
				return
			}
			if (!byUuid(uuid)) {
				items.value = [...items.value, before]
			}
			showError(t('ebookreader', 'The annotation could not be deleted'))
		}
	}

	/**
	 * Adds a bookmark, or removes the given ones when the location already has one.
	 *
	 * @param locator
	 * @param onPage predicate: is a stored bookmark on the visible page?
	 * @param label text excerpt/title shown in the list
	 */
	async function toggleBookmark(locator: Locator, onPage: (l: Locator) => boolean, label?: string): Promise<'added' | 'removed'> {
		const existing = bookmarks.value.filter((b) => onPage(b.locator))
		if (existing.length) {
			await Promise.all(existing.map((b) => remove(b.uuid)))
			return 'removed'
		}
		await create({ type: 'bookmark', locator, text: label ?? locator.title ?? null }).catch(() => undefined)
		return 'added'
	}

	/**
	 * Forgets the book (reader closed).
	 */
	function reset(): void {
		fileId.value = 0
		items.value = []
		loaded.value = false
	}

	return { fileId, items, loaded, sorted, highlights, notes, bookmarks, drawable, byUuid, load, create, update, remove, toggleBookmark, reset }
})
