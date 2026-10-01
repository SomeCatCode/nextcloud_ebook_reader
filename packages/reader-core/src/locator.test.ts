/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { planNavigation, toLocator } from './locator.ts'
import { injectCsp, sanitizeSvg, SECTION_CSP } from './secure-sections.ts'

const ids = ['OEBPS/ch1.xhtml', 'OEBPS/ch2.xhtml', 'OEBPS/ch3.xhtml']

describe('toLocator', () => {
	it('builds a Readium style epub locator', () => {
		const l = toLocator({
			cfi: 'epubcfi(/6/8!/4/2/10)',
			fraction: 0.371234567,
			sectionIndex: 2,
			sectionFraction: 0.42,
			locationCurrent: 56,
			tocLabel: 'Kapitel 3',
			sectionHref: 'OEBPS/ch3.xhtml',
		}, false)
		expect(l).toEqual({
			href: 'OEBPS/ch3.xhtml',
			type: 'application/xhtml+xml',
			title: 'Kapitel 3',
			locations: { progression: 0.42, totalProgression: 0.37123, position: 57, cfi: 'epubcfi(/6/8!/4/2/10)' },
		})
	})

	it('uses 1-based page number and image type for comics', () => {
		const l = toLocator({ fraction: 0.5, sectionIndex: 4, sectionHref: 'p005.PNG' }, true)
		expect(l.type).toBe('image/png')
		expect(l.locations?.position).toBe(5)
		expect(l.locations?.cfi).toBeUndefined()
	})

	it('clamps out-of-range values', () => {
		const l = toLocator({ fraction: 1.4, sectionIndex: 0, sectionFraction: -1, sectionHref: 'a.html' }, false)
		expect(l.locations?.totalProgression).toBe(1)
		expect(l.locations?.progression).toBe(0)
		expect(l.type).toBe('text/html')
	})
})

describe('planNavigation', () => {
	it('prefers cfi, then href+progression, then totalProgression', () => {
		const steps = planNavigation({
			href: 'OEBPS/ch2.xhtml',
			locations: { cfi: 'epubcfi(/6/4!/4)', progression: 0.5, totalProgression: 0.3 },
		}, { sectionIds: ids, isComic: false })
		expect(steps.map((s) => s.kind)).toEqual(['cfi', 'section', 'fraction'])
		expect(steps[1]).toMatchObject({ index: 1, progression: 0.5 })
	})

	it('falls back to fraction when the href is unknown (e.g. after an edit)', () => {
		const steps = planNavigation({ href: 'gone.xhtml', locations: { totalProgression: 0.6 } }, { sectionIds: ids, isComic: false })
		expect(steps).toEqual([{ kind: 'fraction', fraction: 0.6 }])
	})

	it('accepts a plain href string with a fragment', () => {
		const steps = planNavigation('OEBPS/ch3.xhtml#sec1', { sectionIds: ids, isComic: false })
		expect(steps).toEqual([{ kind: 'section', index: 2, progression: 0, fragment: 'sec1' }])
	})

	it('ignores cfi for comics and uses position as fallback', () => {
		const steps = planNavigation({ href: 'nope.jpg', locations: { position: 2, cfi: 'epubcfi(/6/2)' } }, { sectionIds: ['a.jpg', 'b.jpg'], isComic: true })
		expect(steps).toEqual([{ kind: 'section', index: 1, progression: 0 }])
	})

	it('rejects non-cfi strings in the cfi slot', () => {
		const steps = planNavigation({ href: 'OEBPS/ch1.xhtml', locations: { cfi: 'javascript:alert(1)' } }, { sectionIds: ids, isComic: false })
		expect(steps.map((s) => s.kind)).toEqual(['section'])
	})
})

describe('injectCsp', () => {
	it('puts the CSP meta first in head of an XHTML document', () => {
		const out = injectCsp('<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>x</title><script>1</script></head><body/></html>', 'application/xhtml+xml')
		expect(out.type).toBe('application/xhtml+xml')
		const doc = new DOMParser().parseFromString(out.text, 'application/xhtml+xml')
		const first = doc.documentElement.querySelector('head')!.firstElementChild!
		expect(first.getAttribute('http-equiv')).toBe('Content-Security-Policy')
		expect(first.getAttribute('content')).toBe(SECTION_CSP)
	})

	it('cannot be bypassed by a fake head inside a comment', () => {
		const out = injectCsp('<!-- <head> --><html><head></head><body></body></html>', 'text/html')
		const doc = new DOMParser().parseFromString(out.text, 'text/html')
		expect(doc.head.firstElementChild?.getAttribute('http-equiv')).toBe('Content-Security-Policy')
	})

	it('falls back to HTML for invalid XHTML', () => {
		const out = injectCsp('<html><body><p>unclosed', 'application/xhtml+xml')
		expect(out.type).toBe('text/html')
		expect(out.text).toContain('Content-Security-Policy')
	})
})

describe('sanitizeSvg', () => {
	it('removes scripts, handlers and javascript links but keeps drawing content', () => {
		const svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" onload="alert(1)">'
			+ '<script>alert(2)</script><foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>'
			+ '<a xlink:href="java&#x09;script:alert(3)"><rect width="10" height="10" onclick="alert(4)"/></a>'
			+ '<image href="blob:abc"/></svg>'
		const out = sanitizeSvg(svg)
		expect(out).not.toMatch(/alert|<script|foreignObject|onload|onclick|javascript/i)
		expect(out).toContain('<rect')
		expect(out).toContain('blob:abc')
	})

	it('returns an empty svg for unparsable input', () => {
		expect(sanitizeSvg('<svg><not closed')).toBe('<svg xmlns="http://www.w3.org/2000/svg"/>')
	})
})