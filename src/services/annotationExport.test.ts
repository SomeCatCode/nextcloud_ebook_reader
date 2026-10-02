/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Annotation } from '../types.ts'

import { describe, expect, it } from 'vitest'
import { annotationsToMarkdown, positionLabel } from './annotationExport.ts'

/**
 * @param uuid
 * @param extra
 */
function ann(uuid: string, extra: Partial<Annotation> = {}): Annotation {
	return {
		uuid,
		fileId: 1,
		type: 'highlight',
		locator: { href: 'a', title: 'Kapitel 1', locations: { totalProgression: 0.256, cfi: 'epubcfi(/6/2!/4/2/1:0)' } },
		text: 'Zeile eins\nZeile zwei',
		note: null,
		color: 'yellow',
		createdAt: 1,
		updatedAt: 1,
		clientUpdatedAt: 1,
		deleted: false,
		...extra,
	}
}

describe('annotation export', () => {
	it('labels the position', () => {
		expect(positionLabel(ann('a'))).toBe('Kapitel 1, 26 %')
		expect(positionLabel({ locator: { href: 'p' } })).toBe('')
	})

	it('writes sections in reading order and quotes the text', () => {
		const md = annotationsToMarkdown({ title: 'Buch', authors: ['A', 'B'] }, [
			ann('n', { type: 'note', note: 'Meine Notiz', locator: { href: 'a', locations: { cfi: 'epubcfi(/6/8!/4/2/1:0)' } } }),
			ann('h2', { text: 'spaeter', locator: { href: 'a', locations: { cfi: 'epubcfi(/6/6!/4/2/1:0)' } } }),
			ann('h1'),
			ann('b', { type: 'bookmark', text: null, locator: { href: 'p', title: 'Seite 3', locations: { position: 3 } } }),
		])
		expect(md.startsWith('# Buch\n\nA, B\n')).toBe(true)
		expect(md.indexOf('## Highlights')).toBeLessThan(md.indexOf('## Notes'))
		expect(md.indexOf('## Notes')).toBeLessThan(md.indexOf('## Bookmarks'))
		expect(md).toContain('> Zeile eins\n> Zeile zwei')
		expect(md.indexOf('Zeile eins')).toBeLessThan(md.indexOf('spaeter'))
		expect(md).toContain('Meine Notiz')
		expect(md).toContain('*Seite 3*')
		expect(md.endsWith('\n')).toBe(true)
		expect(md).not.toMatch(/\n{3,}/)
	})

	it('omits empty sections', () => {
		const md = annotationsToMarkdown({ title: 'T' }, [])
		expect(md).toBe('# T\n')
	})
})
