/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { readerBlockingDirective } from './cspViolation.ts'

describe('readerBlockingDirective', () => {
	it('names the directive that blocks the blob: frame of the reader', () => {
		expect(readerBlockingDirective({ blockedURI: 'blob', effectiveDirective: 'frame-src', disposition: 'enforce' })).toBe('frame-src')
		expect(readerBlockingDirective({ blockedURI: 'blob:https://cloud.example.org/1b2c', effectiveDirective: 'frame-src' })).toBe('frame-src')
		expect(readerBlockingDirective({ blockedURI: 'blob', effectiveDirective: 'worker-src' })).toBe('worker-src')
		expect(readerBlockingDirective({ blockedURI: 'blob', effectiveDirective: 'default-src' })).toBe('default-src')
	})

	it('reads the directive of old browsers', () => {
		expect(readerBlockingDirective({ blockedURI: 'blob', violatedDirective: "frame-src 'self' http://192.168.1.2" })).toBe('frame-src')
	})

	it('ignores violations that do not stop the reader', () => {
		expect(readerBlockingDirective({ blockedURI: 'https://example.org/a.png', effectiveDirective: 'img-src' })).toBeNull()
		expect(readerBlockingDirective({ blockedURI: 'https://example.org/', effectiveDirective: 'frame-src' })).toBeNull()
		expect(readerBlockingDirective({ blockedURI: 'blob', effectiveDirective: 'img-src' })).toBeNull()
		expect(readerBlockingDirective({ blockedURI: 'blob', effectiveDirective: 'frame-src', disposition: 'report' })).toBeNull()
		expect(readerBlockingDirective({ blockedURI: 'blob' })).toBeNull()
	})
})
