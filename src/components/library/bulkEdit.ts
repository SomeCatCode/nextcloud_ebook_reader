/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { BulkAuthorsMode, BulkIndexMode, BulkMetadataRequest } from '../../types.ts'

export const MAX_NAME_LENGTH = 512
export const MAX_AUTHORS = 50
export const MAX_PUBLISHER_LENGTH = 255
export const MAX_LANGUAGE_LENGTH = 32
export const MAX_FILES = 500
/** the preview shows this many books */
export const PREVIEW_LIMIT = 10

/** State of the "Edit selected books" form. A section is only sent when its `change` flag is set. */
export interface BulkEditForm {
	authors: { change: boolean, mode: BulkAuthorsMode, values: string[] }
	series: { change: boolean, mode: 'set' | 'clear', name: string, index: BulkIndexMode, start: number, step: number }
	publisher: { change: boolean, mode: 'set' | 'clear', value: string }
	language: { change: boolean, mode: 'set' | 'clear', value: string }
	tags: { change: boolean, addGenres: string[], removeGenres: string[], addTags: string[], removeTags: string[] }
}

export type BulkEditError
	= | 'nothing'
		| 'noBooks'
		| 'tooManyBooks'
		| 'authorsEmpty'
		| 'tooManyAuthors'
		| 'nameTooLong'
		| 'seriesNameEmpty'
		| 'startInvalid'
		| 'stepInvalid'
		| 'publisherEmpty'
		| 'publisherTooLong'
		| 'languageEmpty'
		| 'languageTooLong'
		| 'tagsEmpty'

/** Minimal book data for numbering previews. */
export interface PreviewBook {
	fileId: number
	title: string
}

/**
 *
 */
export function emptyForm(): BulkEditForm {
	return {
		authors: { change: false, mode: 'replace', values: [] },
		series: { change: false, mode: 'set', name: '', index: 'keep', start: 1, step: 1 },
		publisher: { change: false, mode: 'set', value: '' },
		language: { change: false, mode: 'set', value: '' },
		tags: { change: false, addGenres: [], removeGenres: [], addTags: [], removeTags: [] },
	}
}

/**
 * Selected file ids in the order of the loaded list; selected books that are not loaded go last.
 *
 * @param listOrder file ids of the loaded list, in display order
 * @param selected selection (any order, e.g. insertion order)
 */
export function orderSelection(listOrder: readonly number[], selected: readonly number[]): number[] {
	const wanted = new Set(selected)
	const out: number[] = []
	const seen = new Set<number>()
	for (const id of listOrder) {
		if (wanted.has(id) && !seen.has(id)) {
			seen.add(id)
			out.push(id)
		}
	}
	for (const id of selected) {
		if (!seen.has(id)) {
			seen.add(id)
			out.push(id)
		}
	}
	return out
}

/**
 * @param values
 */
function clean(values: readonly string[]): string[] {
	const out: string[] = []
	const seen = new Set<string>()
	for (const v of values) {
		const s = v.trim()
		if (s !== '' && !seen.has(s.toLowerCase())) {
			seen.add(s.toLowerCase())
			out.push(s)
		}
	}
	return out
}

/**
 * Problems that would make the server refuse the request (first problem per section).
 *
 * @param form
 * @param bookCount number of selected books
 */
export function validateForm(form: BulkEditForm, bookCount: number): BulkEditError[] {
	const errors: BulkEditError[] = []
	if (bookCount < 1) {
		errors.push('noBooks')
	}
	if (bookCount > MAX_FILES) {
		errors.push('tooManyBooks')
	}
	if (!form.authors.change && !form.series.change && !form.publisher.change && !form.language.change && !form.tags.change) {
		errors.push('nothing')
	}
	if (form.authors.change) {
		const values = clean(form.authors.values)
		if (form.authors.mode !== 'replace' && values.length === 0) {
			errors.push('authorsEmpty')
		}
		if (values.length > MAX_AUTHORS) {
			errors.push('tooManyAuthors')
		}
		if (values.some((v) => v.length > MAX_NAME_LENGTH)) {
			errors.push('nameTooLong')
		}
	}
	if (form.series.change && form.series.mode === 'set') {
		const name = form.series.name.trim()
		if (name === '') {
			errors.push('seriesNameEmpty')
		} else if (name.length > MAX_NAME_LENGTH) {
			errors.push('nameTooLong')
		}
		if (form.series.index !== 'keep') {
			if (!Number.isFinite(form.series.start) || form.series.start < 0) {
				errors.push('startInvalid')
			}
			if (!Number.isFinite(form.series.step) || form.series.step <= 0) {
				errors.push('stepInvalid')
			}
		}
	}
	if (form.publisher.change && form.publisher.mode === 'set') {
		const v = form.publisher.value.trim()
		if (v === '') {
			errors.push('publisherEmpty')
		} else if (v.length > MAX_PUBLISHER_LENGTH) {
			errors.push('publisherTooLong')
		}
	}
	if (form.language.change && form.language.mode === 'set') {
		const v = form.language.value.trim()
		if (v === '') {
			errors.push('languageEmpty')
		} else if (v.length > MAX_LANGUAGE_LENGTH) {
			errors.push('languageTooLong')
		}
	}
	if (form.tags.change) {
		const all = [...form.tags.addGenres, ...form.tags.removeGenres, ...form.tags.addTags, ...form.tags.removeTags]
		if (all.every((v) => v.trim() === '')) {
			errors.push('tagsEmpty')
		}
		if (all.some((v) => v.trim().length > MAX_NAME_LENGTH)) {
			errors.push('nameTooLong')
		}
	}
	return errors
}

/**
 * Builds the request body: only sections with "Change" checked are included.
 *
 * @param form
 * @param fileIds ids in the current list order (see orderSelection)
 */
export function buildRequest(form: BulkEditForm, fileIds: number[]): BulkMetadataRequest {
	const req: BulkMetadataRequest = { fileIds: [...fileIds] }
	if (form.authors.change) {
		req.authors = { mode: form.authors.mode, values: clean(form.authors.values) }
	}
	if (form.series.change) {
		if (form.series.mode === 'clear') {
			req.series = { mode: 'clear' }
		} else {
			req.series = { mode: 'set', name: form.series.name.trim() }
			if (form.series.index === 'keep') {
				req.series.index = { mode: 'keep' }
			} else {
				req.series.index = { mode: form.series.index, start: form.series.start, step: form.series.step }
			}
		}
	}
	if (form.publisher.change) {
		req.publisher = form.publisher.mode === 'clear' ? { mode: 'clear' } : { mode: 'set', value: form.publisher.value.trim() }
	}
	if (form.language.change) {
		req.language = form.language.mode === 'clear' ? { mode: 'clear' } : { mode: 'set', value: form.language.value.trim() }
	}
	if (form.tags.change) {
		const genres = { add: clean(form.tags.addGenres), remove: clean(form.tags.removeGenres) }
		const tags = { add: clean(form.tags.addTags), remove: clean(form.tags.removeTags) }
		if (genres.add.length || genres.remove.length) {
			req.genres = genres
		}
		if (tags.add.length || tags.remove.length) {
			req.tags = tags
		}
	}
	return req
}

const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })

/**
 * Volume number per book as the server will assign it: "sequence" follows the given order, "sortTitle" a natural
 * sort by title (ties keep the given order).
 *
 * @param books in list order
 * @param mode
 * @param start
 * @param step
 */
export function previewNumbering(books: readonly PreviewBook[], mode: BulkIndexMode, start: number, step: number): { fileId: number, title: string, index: number | null }[] {
	if (mode === 'keep') {
		return books.map((b) => ({ fileId: b.fileId, title: b.title, index: null }))
	}
	const position = new Map<number, number>()
	if (mode === 'sequence') {
		books.forEach((b, i) => position.set(b.fileId, i))
	} else {
		books
			.map((b, i) => ({ b, i }))
			.sort((x, y) => collator.compare(x.b.title, y.b.title) || x.i - y.i)
			.forEach((entry, i) => position.set(entry.b.fileId, i))
	}
	return books.map((b) => ({
		fileId: b.fileId,
		title: b.title,
		index: Math.round((start + (position.get(b.fileId) ?? 0) * step) * 10000) / 10000,
	}))
}
