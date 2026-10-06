/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { buildAppDataRequest, buildRequest, emptyForm, hasMetadataChanges, mergeBulkResults, orderSelection, previewNumbering, validateForm } from './bulkEdit.ts'

describe('orderSelection', () => {
	it('follows the list order and appends selected books that are not loaded', () => {
		expect(orderSelection([5, 3, 9, 1], [1, 99, 5, 98])).toEqual([5, 1, 99, 98])
	})

	it('ignores duplicates', () => {
		expect(orderSelection([2, 1], [1, 1, 2])).toEqual([2, 1])
	})
})

describe('buildRequest', () => {
	it('sends only the sections that are checked', () => {
		const form = emptyForm()
		form.authors.values = ['Ignored']
		form.publisher = { change: true, mode: 'set', value: ' Tor ' }
		expect(buildRequest(form, [3, 1, 2])).toEqual({ fileIds: [3, 1, 2], publisher: { mode: 'set', value: 'Tor' } })
	})

	it('builds authors, clear modes and trims values', () => {
		const form = emptyForm()
		form.authors = { change: true, mode: 'add', values: [' A ', 'a', '', 'B'] }
		form.language = { change: true, mode: 'clear', value: 'de' }
		form.series = { change: true, mode: 'clear', name: 'ignored', index: 'sequence', start: 5, step: 2 }
		expect(buildRequest(form, [1])).toEqual({
			fileIds: [1],
			authors: { mode: 'add', values: ['A', 'B'] },
			language: { mode: 'clear' },
			series: { mode: 'clear' },
		})
	})

	it('adds numbering only when it is not "keep"', () => {
		const form = emptyForm()
		form.series = { change: true, mode: 'set', name: ' Saga ', index: 'keep', start: 4, step: 3 }
		expect(buildRequest(form, [1]).series).toEqual({ mode: 'set', name: 'Saga', index: { mode: 'keep' } })
		form.series.index = 'sortTitle'
		expect(buildRequest(form, [1]).series).toEqual({ mode: 'set', name: 'Saga', index: { mode: 'sortTitle', start: 4, step: 3 } })
	})

	it('keeps the bulk-tags semantics for genres and tags and skips empty lists', () => {
		const form = emptyForm()
		form.tags = { change: true, addGenres: ['Fantasy'], removeGenres: [], addTags: [], removeTags: ['old'] }
		expect(buildRequest(form, [1])).toEqual({
			fileIds: [1],
			genres: { add: ['Fantasy'], remove: [] },
			tags: { add: [], remove: ['old'] },
		})
	})
})

describe('previewNumbering', () => {
	const books = [
		{ fileId: 1, title: 'Vol 10' },
		{ fileId: 2, title: 'Vol 2' },
		{ fileId: 3, title: 'Vol 1' },
	]

	it('numbers in list order with start and step', () => {
		expect(previewNumbering(books, 'sequence', 2, 0.5).map((b) => b.index)).toEqual([2, 2.5, 3])
	})

	it('numbers by natural title order', () => {
		const byId = Object.fromEntries(previewNumbering(books, 'sortTitle', 1, 1).map((b) => [b.fileId, b.index]))
		expect(byId).toEqual({ 3: 1, 2: 2, 1: 3 })
	})

	it('has no numbers for "keep"', () => {
		expect(previewNumbering(books, 'keep', 1, 1).every((b) => b.index === null)).toBe(true)
	})

	it('avoids floating point noise', () => {
		expect(previewNumbering(books, 'sequence', 0, 0.1).map((b) => b.index)).toEqual([0, 0.1, 0.2])
	})
})

describe('validateForm', () => {
	it('requires at least one checked section and one book', () => {
		expect(validateForm(emptyForm(), 2)).toEqual(['nothing'])
		expect(validateForm(emptyForm(), 0)).toContain('noBooks')
		const form = emptyForm()
		form.publisher.change = true
		form.publisher.value = 'x'
		expect(validateForm(form, 501)).toEqual(['tooManyBooks'])
		expect(validateForm(form, 500)).toEqual([])
	})

	it('checks authors', () => {
		const form = emptyForm()
		form.authors = { change: true, mode: 'remove', values: [] }
		expect(validateForm(form, 1)).toEqual(['authorsEmpty'])
		form.authors = { change: true, mode: 'replace', values: [] }
		expect(validateForm(form, 1)).toEqual([])
		form.authors.values = Array.from({ length: 51 }, (_, i) => `A${i}`)
		expect(validateForm(form, 1)).toEqual(['tooManyAuthors'])
		form.authors.values = ['x'.repeat(513)]
		expect(validateForm(form, 1)).toEqual(['nameTooLong'])
	})

	it('checks the series name and numbering', () => {
		const form = emptyForm()
		form.series = { change: true, mode: 'set', name: ' ', index: 'sequence', start: -1, step: 0 }
		expect(validateForm(form, 1)).toEqual(['seriesNameEmpty', 'startInvalid', 'stepInvalid'])
		form.series = { change: true, mode: 'set', name: 'S', index: 'keep', start: -1, step: 0 }
		expect(validateForm(form, 1)).toEqual([])
		form.series = { change: true, mode: 'set', name: 's'.repeat(513), index: 'keep', start: 1, step: 1 }
		expect(validateForm(form, 1)).toEqual(['nameTooLong'])
		form.series = { change: true, mode: 'clear', name: '', index: 'sequence', start: -1, step: 0 }
		expect(validateForm(form, 1)).toEqual([])
	})

	it('checks publisher and language limits', () => {
		const form = emptyForm()
		form.publisher = { change: true, mode: 'set', value: 'p'.repeat(256) }
		form.language = { change: true, mode: 'set', value: 'l'.repeat(33) }
		expect(validateForm(form, 1)).toEqual(['publisherTooLong', 'languageTooLong'])
		form.publisher.value = ''
		form.language.value = ''
		expect(validateForm(form, 1)).toEqual(['publisherEmpty', 'languageEmpty'])
	})

	it('needs at least one genre or tag entry', () => {
		const form = emptyForm()
		form.tags.change = true
		expect(validateForm(form, 1)).toEqual(['tagsEmpty'])
	})
})

describe('completion and age rating sections', () => {
	it('count as a change on their own and are not part of the metadata request', () => {
		const form = emptyForm()
		form.completion = { change: true, value: 'completed' }
		expect(validateForm(form, 2)).toEqual([])
		expect(hasMetadataChanges(form)).toBe(false)
		expect(buildRequest(form, [1, 2])).toEqual({ fileIds: [1, 2] })
		expect(buildAppDataRequest(form)).toEqual({ completion: 'completed' })
	})

	it('builds set, explicit none and reset of the age rating', () => {
		const form = emptyForm()
		expect(buildAppDataRequest(form)).toBeNull()
		form.age = { change: true, mode: 'set', value: 16 }
		expect(buildAppDataRequest(form)).toEqual({ ageRating: 16 })
		form.age = { change: true, mode: 'set', value: null }
		expect(buildAppDataRequest(form)).toEqual({ ageRating: null })
		form.age = { change: true, mode: 'reset', value: 12 }
		form.completion = { change: true, value: null }
		expect(buildAppDataRequest(form)).toEqual({ completion: null, resetAgeRating: true })
	})

	it('merges the results of both requests', () => {
		expect(mergeBulkResults(null, { updated: 2, unchanged: 1, failed: [{ fileId: 3, error: 'not_found' }] }))
			.toEqual({ updated: 2, unchanged: 1, failed: [{ fileId: 3, error: 'not_found' }], writeQueued: false })
		expect(mergeBulkResults(
			{ updated: 1, unchanged: 2, failed: [{ fileId: 3, error: 'x' }], writeQueued: true },
			{ updated: 3, unchanged: 0, failed: [{ fileId: 3, error: 'not_found' }, { fileId: 4, error: 'failed' }] },
		)).toEqual({ updated: 3, unchanged: 0, failed: [{ fileId: 3, error: 'x' }, { fileId: 4, error: 'failed' }], writeQueued: true })
	})
})
