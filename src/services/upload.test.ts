/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it, vi } from 'vitest'
import { assignNames, extensionOf, isAllowedName, partitionFiles, uniqueName } from './upload.ts'

vi.mock('@nextcloud/upload', () => ({ getUploader: vi.fn() }))
vi.mock('@nextcloud/files/dav', () => ({ defaultRootPath: '/files/user', getClient: vi.fn() }))

describe('upload extension filter', () => {
	it('reads the extension case-insensitively', () => {
		expect(extensionOf('Book.EPUB')).toBe('epub')
		expect(extensionOf('a.b.cbz')).toBe('cbz')
		expect(extensionOf('noext')).toBe('')
		expect(extensionOf('.hidden')).toBe('')
		expect(extensionOf('trailing.')).toBe('')
	})

	it('allows only e-book formats', () => {
		for (const ext of ['epub', 'mobi', 'azw3', 'fb2', 'fbz', 'cbz', 'cbr', 'cb7', 'cbt']) {
			expect(isAllowedName(`x.${ext}`)).toBe(true)
		}
		expect(isAllowedName('x.pdf')).toBe(false)
		expect(isAllowedName('x.exe')).toBe(false)
		expect(isAllowedName('epub')).toBe(false)
	})

	it('splits accepted and rejected files', () => {
		const { accepted, rejected } = partitionFiles([{ name: 'a.epub' }, { name: 'b.pdf' }, { name: 'c.CBZ' }])
		expect(accepted.map((f) => f.name)).toEqual(['a.epub', 'c.CBZ'])
		expect(rejected.map((f) => f.name)).toEqual(['b.pdf'])
	})
})

describe('upload conflict rules', () => {
	it('keeps free names', () => {
		expect(uniqueName('Book.epub', new Set())).toBe('Book.epub')
	})

	it('numbers conflicting names without overwriting', () => {
		const taken = new Set(['book.epub', 'book (2).epub'])
		expect(uniqueName('Book.epub', taken)).toBe('Book (3).epub')
		expect(taken.has('book (3).epub')).toBe(true)
	})

	it('handles names without extension', () => {
		expect(uniqueName('Book', new Set(['book']))).toBe('Book (2)')
	})

	it('avoids clashes inside one batch and with existing files', () => {
		expect(assignNames(['A.epub', 'A.epub', 'B.epub'], ['a.epub'])).toEqual(['A (2).epub', 'A (3).epub', 'B.epub'])
	})
})
