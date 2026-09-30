/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Structure } from '../types.ts'

import { describe, expect, it } from 'vitest'
import { useEditorState } from './useEditorState.ts'

/**
 *
 */
function fixture(): Structure {
	return {
		fileId: 1,
		format: 'epub',
		etag: 'abc',
		editable: true,
		capabilities: { metadata: true, cover: true, content: true, toc: true, writesFile: true },
		metadata: {
			title: 'T',
			authors: ['A'],
			series: null,
			seriesIndex: null,
			description: null,
			language: 'en',
			publisher: null,
			isbn: null,
			publishedAt: null,
			genres: [],
			tags: [],
		},
		items: ['c1', 'c2', 'c3', 'c4'].map((id) => ({ id, label: id, href: id + '.xhtml', kind: 'chapter' as const, linear: true, size: 10 })),
		toc: [
			{ id: 't1', label: 'One', itemId: 'c1', fragment: null, children: [{ id: 't2', label: 'Two', itemId: 'c2', fragment: null, children: [] }] },
			{ id: 't3', label: 'Three', itemId: 'c3', fragment: null, children: [] },
		],
		warnings: [],
	}
}

describe('useEditorState', () => {
	it('is clean after load and produces a minimal request', () => {
		const s = useEditorState()
		s.load(fixture())
		expect(s.dirty.value).toBe(false)
		expect(s.buildEditRequest(false)).toEqual({ etag: 'abc', saveAsCopy: false })
		expect(s.computeChangeSummary()).toEqual([])
	})

	it('reorders items and undoes', () => {
		const s = useEditorState()
		s.load(fixture())
		s.moveItem(0, 2)
		expect(s.order.value).toEqual(['c2', 'c3', 'c1', 'c4'])
		expect(s.dirty.value).toBe(true)
		expect(s.buildEditRequest(false).order).toEqual(['c2', 'c3', 'c1', 'c4'])
		expect(s.computeChangeSummary()).toContain('Chapters reordered')
		expect(s.undo()).toBe(true)
		expect(s.dirty.value).toBe(false)
	})

	it('removes and restores items', () => {
		const s = useEditorState()
		s.load(fixture())
		s.removeItems(['c2', 'c4', 'nope'])
		const req = s.buildEditRequest(true)
		expect(req.saveAsCopy).toBe(true)
		expect(req.removed).toEqual(['c2', 'c4'])
		expect(req.order).toEqual(['c1', 'c3'])
		expect(s.activeItems.value.map((i) => i.id)).toEqual(['c1', 'c3'])
		expect(s.computeChangeSummary()).toContain('2 chapters removed')
		s.restoreItems(['c2'])
		expect(s.buildEditRequest(false).removed).toEqual(['c4'])
	})

	it('flags toc entries pointing at removed items', () => {
		const s = useEditorState()
		s.load(fixture())
		s.removeItems(['c2', 'c3'])
		expect([...s.brokenTocIds.value].sort()).toEqual(['t2', 't3'])
		s.restoreItems(['c3'])
		expect([...s.brokenTocIds.value]).toEqual(['t2'])
	})

	it('edits toc: rename, indent, outdent, delete, add', () => {
		const s = useEditorState()
		s.load(fixture())
		s.renameToc('t3', 'Renamed')
		expect(s.toc.value[1].label).toBe('Renamed')
		expect(s.indentToc('t3')).toBe(true)
		expect(s.toc.value).toHaveLength(1)
		expect(s.toc.value[0].children.map((c) => c.id)).toEqual(['t2', 't3'])
		expect(s.outdentToc('t3')).toBe(true)
		expect(s.toc.value.map((n) => n.id)).toEqual(['t1', 't3'])
		expect(s.indentToc('t1')).toBe(false)
		s.deleteToc('t1')
		expect(s.toc.value.map((n) => n.id)).toEqual(['t2', 't3'])
		s.addTocEntry('New', 'c4')
		expect(s.toc.value).toHaveLength(3)
		expect(s.toc.value[2].itemId).toBe('c4')
		expect(s.buildEditRequest(false).toc).toHaveLength(3)
		expect(s.computeChangeSummary()).toContain('Table of contents changed')
	})

	it('builds a metadata patch with only changed fields and summary', () => {
		const s = useEditorState()
		s.load(fixture())
		s.metadata.value.title = 'New'
		s.metadata.value.tags = ['x']
		s.setCover({ source: 'item', itemId: 'c1' })
		const req = s.buildEditRequest(false)
		expect(req.metadata).toEqual({ title: 'New', tags: ['x'] })
		expect(req.cover).toEqual({ source: 'item', itemId: 'c1' })
		expect(s.computeChangeSummary()).toEqual(expect.arrayContaining(['Title changed', 'Tags changed', 'Cover changed']))
	})

	it('uses page wording for comics', () => {
		const s = useEditorState()
		const f = fixture()
		f.format = 'cbz'
		s.load(f)
		s.removeItems(['c1'])
		expect(s.computeChangeSummary()).toContain('1 page removed')
	})
})
