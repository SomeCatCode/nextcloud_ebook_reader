/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { canEmbed, EMBED_SYNC_MAX_BYTES, resolveTarget, resolveWriteMode, writesBookFile } from './metadataStorage.ts'

describe('metadata storage settings', () => {
	it('shows the write mode only for targets that write into the book', () => {
		expect(writesBookFile('sidecar')).toBe(false)
		expect(writesBookFile('library')).toBe(false)
		expect(writesBookFile('file')).toBe(true)
		expect(writesBookFile('both')).toBe(true)
	})

	it('resolves the target, with the legacy write mode "never" meaning library only', () => {
		expect(resolveTarget('both')).toBe('both')
		expect(resolveTarget(undefined)).toBe('sidecar')
		expect(resolveTarget('nonsense')).toBe('sidecar')
		expect(resolveTarget(undefined, 'never')).toBe('library')
		expect(resolveTarget('file', 'never')).toBe('file')
	})

	it('only sends valid write modes', () => {
		expect(resolveWriteMode('immediate')).toBe('immediate')
		expect(resolveWriteMode('background')).toBe('background')
		expect(resolveWriteMode('never')).toBe('background')
		expect(resolveWriteMode(undefined)).toBe('background')
	})

	it('offers embedding only for writable formats on writable, readable files', () => {
		for (const f of ['epub', 'cbz', 'fb2', 'fbz', 'EPUB']) {
			expect(canEmbed(f, true, true)).toBe(true)
		}
		for (const f of ['mobi', 'azw3', 'cbr', 'cb7', 'cbt']) {
			expect(canEmbed(f, true, true)).toBe(false)
		}
		expect(canEmbed('epub', false, true)).toBe(false)
		expect(canEmbed('epub', true, false)).toBe(false)
	})

	it('uses a task for large books', () => {
		expect(EMBED_SYNC_MAX_BYTES).toBe(20 * 1024 * 1024)
	})
})
