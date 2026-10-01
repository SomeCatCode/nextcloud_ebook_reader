/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Hardening of section documents (see docs/SECURITY-READER.md).
 *
 * 1. Every HTML section document gets a CSP <meta> as first child of <head>: no script execution
 *    and no remote loads (privacy). The blob: documents also inherit the page CSP of Nextcloud,
 *    which allows neither inline scripts nor blob: scripts.
 * 2. SVG sections (no CSP meta possible) are sanitized: scripts, handlers, javascript: links removed.
 * 3. Script resources of EPUBs are never loaded (transformTarget isScript -> allow = false).
 * The iframes get sandbox="allow-same-origin allow-scripts" because foliate's own listeners run
 * inside the section documents; see docs/SECURITY-READER.md.
 */

export const SECTION_CSP = 'default-src \'none\'; img-src blob: data:; media-src blob: data:; style-src blob: \'unsafe-inline\'; font-src blob: data:; script-src \'none\'; base-uri \'none\'; form-action \'none\'; frame-src \'none\'; object-src \'none\''

const XHTML_NS = 'http://www.w3.org/1999/xhtml'
const SVG_NS = 'http://www.w3.org/2000/svg'
const ERROR_PAGE = '<!DOCTYPE html>\n<html><head><meta http-equiv="Content-Security-Policy" content="' + SECTION_CSP + '"></head><body></body></html>'

/**
 * Inject the CSP meta into an (X)HTML document string. Uses a real parser, so comments or
 * CDATA can not hide the tag.
 *
 * @param text
 * @param type
 */
export function injectCsp(text: string, type: string): { text: string, type: string } {
	const parser = new DOMParser()
	let mime: string = /xml/i.test(type) ? type : 'text/html'
	let doc = parser.parseFromString(text, mime as DOMParserSupportedType)
	if (mime !== 'text/html' && (doc.querySelector('parsererror') || !doc.documentElement)) {
		mime = 'text/html'
		doc = parser.parseFromString(text, 'text/html')
	}
	const root = doc.documentElement
	const ns = root.namespaceURI
	if (mime !== 'text/html' && !(root.localName.toLowerCase() === 'html' && ns === XHTML_NS)) {
		// Not an XHTML document (e.g. an <svg> root served with an XHTML media type): a CSP meta
		// would not be honoured, so clean it like an SVG or replace it with an empty page.
		if (root.localName === 'svg' && ns === SVG_NS) {
			return { text: cleanSvgDocument(doc), type: 'image/svg+xml' }
		}
		return { text: ERROR_PAGE, type: 'text/html' }
	}
	const create = (name: string): Element => ns ? doc.createElementNS(ns, name) : doc.createElement(name)
	let head = Array.from(root.children).find((c) => c.localName === 'head') ?? null
	if (!head) {
		head = create('head')
		root.insertBefore(head, root.firstChild)
	}
	const meta = create('meta')
	meta.setAttribute('http-equiv', 'Content-Security-Policy')
	meta.setAttribute('content', SECTION_CSP)
	head.insertBefore(meta, head.firstChild)
	if (mime === 'text/html') {
		return { text: '<!DOCTYPE html>\n' + root.outerHTML, type: 'text/html' }
	}
	return { text: new XMLSerializer().serializeToString(doc), type: mime }
}

interface SectionLike {
	load?: () => unknown
	unload?: () => unknown
}
interface BookLike {
	sections: SectionLike[]
	transformTarget?: EventTarget
}

/**
 * Strip everything executable from an SVG document: CSP <meta> is not honoured in SVG documents,
 * so scripts, event handler attributes and javascript: links are removed instead.
 *
 * @param text
 */
export function sanitizeSvg(text: string): string {
	const doc = new DOMParser().parseFromString(text, 'image/svg+xml')
	if (doc.querySelector('parsererror')) {
		return '<svg xmlns="http://www.w3.org/2000/svg"/>'
	}
	return cleanSvgDocument(doc)
}

/**
 * @param doc
 */
function cleanSvgDocument(doc: Document): string {
	for (const el of Array.from(doc.querySelectorAll('*'))) {
		const name = el.localName.toLowerCase()
		if (name === 'script' || name === 'foreignobject' || name === 'iframe' || name === 'embed' || name === 'object') {
			el.remove()
			continue
		}
		for (const attr of Array.from(el.attributes)) {
			const attrName = attr.name.toLowerCase()
			const value = attr.value.replace(/[\s\u0000-\u001f]/g, '').toLowerCase()
			if (attrName.startsWith('on') || ((attrName === 'href' || attrName.endsWith(':href') || attrName === 'src') && (value.startsWith('javascript:') || value.startsWith('data:text/html')))) {
				el.removeAttributeNode(attr)
			}
		}
	}
	return new XMLSerializer().serializeToString(doc)
}

/**
 * @param url
 */
async function rewrite(url: string): Promise<string> {
	const res = await fetch(url)
	const blob = await res.blob()
	if (/svg/i.test(blob.type)) {
		return URL.createObjectURL(new Blob([sanitizeSvg(await blob.text())], { type: 'image/svg+xml' }))
	}
	if (/^(image|audio|video)\//i.test(blob.type)) {
		return url
	}
	const out = injectCsp(await blob.text(), blob.type)
	return URL.createObjectURL(new Blob([out.text], { type: out.type }))
}

/**
 * Wraps section load/unload of a foliate book object so every HTML section gets the CSP,
 * and disables loading of script resources from EPUBs.
 *
 * @param book
 */
export function hardenBook(book: BookLike): void {
	book.transformTarget?.addEventListener('load', ((e: CustomEvent<{ isScript?: boolean, allow?: unknown }>) => {
		if (e.detail?.isScript) {
			e.detail.allow = false
		}
	}) as EventListener)
	for (const section of book.sections) {
		const origLoad = section.load?.bind(section)
		const origUnload = section.unload?.bind(section)
		if (!origLoad) {
			continue
		}
		let mine: string[] = []
		section.load = async () => {
			const result = await origLoad() as string | { src?: string } | null
			const src = typeof result === 'string' ? result : result?.src
			if (!src) {
				return result
			}
			const safe = await rewrite(src)
			if (safe !== src) {
				mine.push(safe)
			}
			return typeof result === 'string' ? safe : { ...result, src: safe }
		}
		section.unload = () => {
			origUnload?.()
			for (const u of mine) {
				URL.revokeObjectURL(u)
			}
			mine = []
		}
	}
}
