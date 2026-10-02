/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { parseTerm, queryToState, smartQueryToState, stateToQuery, stateToSmartQuery, termToString, useLibraryStore } from '../../stores/library.ts'
import { emptyMissing, isMissingField, MISSING_FIELDS, missingEntries, missingLabel } from './missing.ts'

vi.mock('../../services/api.ts', () => ({
	listBooks: vi.fn().mockResolvedValue({ books: [], total: 0 }),
	getFacets: vi.fn(),
	recentBooks: vi.fn(),
	listSeries: vi.fn(),
	listShelves: vi.fn(),
}))

describe('missing terms', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('validates the field name', () => {
		expect(parseTerm('missing:genre')).toEqual({ type: 'missing', name: 'genre' })
		expect(parseTerm('missing:bogus')).toBeNull()
		expect(isMissingField('cover')).toBe(true)
		expect(isMissingField('title')).toBe(false)
	})

	it('cycles include, exclude, off', () => {
		const store = useLibraryStore()
		const term = { type: 'missing' as const, name: 'tag' }
		expect(store.termState(term)).toBeNull()
		store.cycleTerm(term)
		expect(store.termState(term)).toBe('include')
		store.cycleTerm(term)
		expect(store.termState(term)).toBe('exclude')
		store.cycleTerm(term)
		expect(store.termState(term)).toBeNull()
	})

	it('survives the URL and smart shelf round trips', () => {
		const state = queryToState({ include: ['missing:series', 'tag:x'], exclude: 'missing:cover', match: 'any' })
		expect(state.filters.include.map(termToString)).toEqual(['missing:series', 'tag:x'])
		expect(state.filters.exclude.map(termToString)).toEqual(['missing:cover'])
		const query = stateToQuery(state.filters, state.sort, state.order)
		expect(query.include).toEqual(['missing:series', 'tag:x'])
		expect(query.exclude).toEqual(['missing:cover'])
		expect(queryToState(query).filters).toEqual(state.filters)
		const smart = smartQueryToState(stateToSmartQuery(state.filters, state.sort, state.order))
		expect(smart.filters).toEqual(state.filters)
		expect(queryToState({ include: 'missing:nope' }).filters.include).toEqual([])
	})

	it('labels and entries', () => {
		expect(missingLabel('genre')).toBe('Without genre')
		expect(missingLabel('tag', 'include')).toBe('Without tags')
		expect(missingLabel('genre', 'exclude')).toBe('Has genre')
		for (const f of MISSING_FIELDS) {
			expect(missingLabel(f)).not.toBe(f)
		}
		expect(missingEntries(emptyMissing())).toEqual([])
		expect(missingEntries({ ...emptyMissing(), cover: 3, genre: 1 })).toEqual([
			{ field: 'genre', count: 1 },
			{ field: 'cover', count: 3 },
		])
	})
})
