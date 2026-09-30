/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { sanitizeDescription } from './sanitize.ts'

describe('sanitizeDescription', () => {
	it('keeps allowed markup', () => {
		expect(sanitizeDescription('<p>a <b>b</b> <em>c</em></p><ul><li>x</li></ul>')).toBe('<p>a <b>b</b> <em>c</em></p><ul><li>x</li></ul>')
	})

	it('strips scripts, attributes and other tags', () => {
		const out = sanitizeDescription('<p onclick="x()">a<script>alert(1)</script><a href="javascript:1">l</a><img src=x></p>')
		expect(out).toBe('<p>al</p>')
	})
})
