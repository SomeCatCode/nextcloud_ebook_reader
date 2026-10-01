/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { injectCsp, SECTION_CSP } from './secure-sections.ts'

describe('SECTION_CSP', () => {
	it('contains the extended directives', () => {
		for (const d of ['script-src \'none\'', 'base-uri \'none\'', 'form-action \'none\'', 'frame-src \'none\'', 'object-src \'none\'']) {
			expect(SECTION_CSP).toContain(d)
		}
	})
})

describe('injectCsp', () => {
	it('adds the CSP meta to a normal XHTML section', () => {
		const out = injectCsp('<html xmlns="http://www.w3.org/1999/xhtml"><head><title>x</title></head><body><p>hi</p></body></html>', 'application/xhtml+xml')
		expect(out.type).toBe('application/xhtml+xml')
		expect(out.text).toContain('Content-Security-Policy')
		expect(out.text).toContain('<p>hi</p>')
	})

	it('adds the CSP meta to a plain HTML section', () => {
		const out = injectCsp('<p>hi</p>', 'text/html')
		expect(out.type).toBe('text/html')
		expect(out.text).toContain('script-src \'none\'')
	})

	it('sanitizes an svg root served as XHTML', () => {
		const svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script><foreignObject><div/></foreignObject><a href="javascript:alert(3)"><rect/></a></svg>'
		const out = injectCsp(svg, 'application/xhtml+xml')
		expect(out.type).toBe('image/svg+xml')
		expect(out.text).not.toMatch(/script|onload|foreignObject|javascript:/i)
		expect(out.text).toContain('<rect')
	})

	it('replaces other non-XHTML roots with an empty HTML page', () => {
		const out = injectCsp('<foo xmlns="urn:x"><script>alert(1)</script></foo>', 'application/xhtml+xml')
		expect(out.type).toBe('text/html')
		expect(out.text).not.toContain('alert')
		expect(out.text).toContain('Content-Security-Policy')
	})
})
