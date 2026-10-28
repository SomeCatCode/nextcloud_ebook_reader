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
	const XHTML = 'http://www.w3.org/1999/xhtml'
	const policyIsFirst = (out: { text: string, type: string }): boolean => {
		const doc = new DOMParser().parseFromString(out.text, out.type as DOMParserSupportedType)
		const head = doc.documentElement.firstElementChild
		const meta = head?.firstElementChild
		return head?.localName === 'head' && head.namespaceURI === XHTML
			&& meta?.localName === 'meta' && meta.getAttribute('http-equiv') === 'Content-Security-Policy'
			&& meta.getAttribute('content') === SECTION_CSP
	}

	it('puts the policy in front when the head comes after the body', () => {
		const out = injectCsp(`<html xmlns="${XHTML}"><body><p>hi</p><script>alert(1)</script></body><head><title>x</title></head></html>`, 'application/xhtml+xml')
		expect(policyIsFirst(out)).toBe(true)
		expect(out.text).not.toContain('alert')
	})

	it('does not take a head of another namespace', () => {
		const out = injectCsp(`<html xmlns="${XHTML}"><x:head xmlns:x="urn:x"/><body><p>hi</p></body></html>`, 'application/xhtml+xml')
		expect(policyIsFirst(out)).toBe(true)
	})

	it('removes a script in front of the head', () => {
		const out = injectCsp(`<html xmlns="${XHTML}"><script>alert(1)</script><head/><body/></html>`, 'application/xhtml+xml')
		expect(policyIsFirst(out)).toBe(true)
		expect(out.text).not.toMatch(/<script|alert/)
	})

	it('keeps an XHTML head that is the first element', () => {
		const out = injectCsp(`<html xmlns="${XHTML}"><head><title>x</title></head><body/></html>`, 'application/xhtml+xml')
		expect(policyIsFirst(out)).toBe(true)
		expect(out.text.match(/<head/g)).toHaveLength(1)
	})

	it('removes handlers, script links, meta refresh, base and stylesheet instructions', () => {
		const src = `<?xml-stylesheet type="text/xsl" href="evil.xsl"?><html xmlns="${XHTML}"><head><meta http-equiv="refresh" content="0;url=javascript:alert(1)"/><base href="https://evil.example/"/></head>`
			+ '<body onload="alert(2)"><a href="  java\tscript:alert(3)">x</a><img src="a.png" onerror="alert(4)"/><form><button formaction="javascript:alert(5)">b</button></form>'
			+ '<iframe srcdoc="&lt;script&gt;alert(6)&lt;/script&gt;"/><svg xmlns="http://www.w3.org/2000/svg"><a><set attributeName="href" to="javascript:alert(7)"/><rect/></a></svg></body></html>'
		const out = injectCsp(src, 'application/xhtml+xml')
		expect(policyIsFirst(out)).toBe(true)
		expect(out.text).not.toMatch(/alert|xml-stylesheet|refresh|<base|<iframe|<set/)
		expect(out.text).toContain('a.png')
		expect(out.text).toContain('<rect')
	})

	it('cleans plain HTML as well', () => {
		const out = injectCsp('<script>alert(1)</script><head></head><p onclick="alert(2)">hi</p>', 'text/html')
		expect(policyIsFirst(out)).toBe(true)
		expect(out.text).not.toContain('alert')
		expect(out.text).toContain('hi')
	})
})
