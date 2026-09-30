/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Hardening of section documents (see docs/SECURITY-READER.md).
 *
 * 1. The vendored paginator/fixed-layout create their iframes with sandbox="allow-same-origin"
 *    (no allow-scripts), so EPUB scripts never run.
 * 2. Every HTML section document gets a CSP <meta> as first child of <head>: no remote loads
 *    (privacy) and, as defense in depth, no script execution.
 */

export const SECTION_CSP = 'default-src \'none\'; img-src blob: data:; media-src blob: data:; style-src blob: \'unsafe-inline\'; font-src blob: data:'

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
 * @param url
 */
async function rewrite(url: string): Promise<string> {
	const res = await fetch(url)
	const blob = await res.blob()
	if (/svg/i.test(blob.type) || /^(image|audio|video)\//i.test(blob.type)) {
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
