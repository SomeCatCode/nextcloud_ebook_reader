/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { strToU8, zipSync } from 'fflate'

/** Same structure as lib/Service/ComicWriter.php (fixed layout EPUB 3, one XHTML page per image). */

export interface EpubPage {
	/** final file name, e.g. 0001.jpg */
	name: string
	data: Uint8Array
	width: number
	height: number
}

export interface EpubMeta {
	title?: string | null
	authors?: string[]
	series?: string | null
	seriesIndex?: number | null
	description?: string | null
	language?: string | null
	publisher?: string | null
	publishedAt?: string | null
	genres?: string[]
	tags?: string[]
}

const IMAGE_TYPES: Record<string, string> = {
	jpg: 'image/jpeg',
	jpeg: 'image/jpeg',
	png: 'image/png',
	gif: 'image/gif',
	webp: 'image/webp',
	avif: 'image/avif',
	bmp: 'image/bmp',
}

/**
 * @param s
 */
export function escapeXml(s: string): string {
	return s
		// eslint-disable-next-line no-control-regex
		.replace(/[^\u0009\u000A\u000D -퟿-�\u{10000}-\u{10FFFF}]/gu, '')
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&apos;')
}

/**
 * @param html
 */
function plainText(html: string): string {
	return html
		.replace(/<\/(p|div)\s*>|<br\s*\/?>/gi, '\n')
		.replace(/<[^>]*>/g, '')
		.replace(/&nbsp;/g, ' ')
		.replace(/&lt;/g, '<')
		.replace(/&gt;/g, '>')
		.replace(/&quot;/g, '"')
		.replace(/&amp;/g, '&')
		.trim()
}

/**
 * @param n
 */
function formatNumber(n: number): string {
	return String(Math.round(n * 100) / 100)
}

/**
 * @param n 1-based page number
 * @param width
 */
export function pageId(n: number, width = 4): string {
	return String(n).padStart(width, '0')
}

/**
 * Zip paths of the XHTML documents, same order as the pages.
 *
 * @param count
 */
export function epubPageHrefs(count: number): string[] {
	return Array.from({ length: count }, (_, i) => `OEBPS/pages/${pageId(i + 1)}.xhtml`)
}

/**
 * @param n
 * @param name
 * @param w
 * @param h
 */
function pageXhtml(n: number, name: string, w: number, h: number): string {
	return '<?xml version="1.0" encoding="UTF-8"?>\n<!DOCTYPE html>\n'
		+ `<html xmlns="http://www.w3.org/1999/xhtml"><head><meta charset="utf-8"/><title>Page ${n}</title>`
		+ `<meta name="viewport" content="width=${w}, height=${h}"/>`
		+ `<style>html,body{margin:0;padding:0;width:${w}px;height:${h}px;overflow:hidden}img{display:block;width:${w}px;height:${h}px}</style></head>`
		+ `<body><img src="../images/${escapeXml(name)}" alt="Page ${n}"/></body></html>`
}

/**
 * Builds the EPUB: `mimetype` first and stored, images stored, XML deflated.
 *
 * @param pages
 * @param meta
 * @param rtl right to left reading direction (manga)
 * @param coverIndex 0-based page index used as cover
 */
export function buildEpub(pages: EpubPage[], meta: EpubMeta, rtl: boolean, coverIndex = 0): Uint8Array {
	const cover = pages[coverIndex] ? coverIndex : 0
	const files: Record<string, [Uint8Array, { level: 0 | 6 }]> = {}
	files.mimetype = [strToU8('application/epub+zip'), { level: 0 }]
	files['META-INF/container.xml'] = [strToU8('<?xml version="1.0" encoding="UTF-8"?>\n'
		+ '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles>'
		+ '<rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>'), { level: 6 }]

	let manifest = ''
	let spine = ''
	let nav = ''
	pages.forEach((page, i) => {
		const n = i + 1
		const id = pageId(n)
		const ext = page.name.split('.').pop()?.toLowerCase() ?? 'jpg'
		files[`OEBPS/images/${page.name}`] = [page.data, { level: 0 }]
		files[`OEBPS/pages/${id}.xhtml`] = [strToU8(pageXhtml(n, page.name, page.width, page.height)), { level: 6 }]
		manifest += `<item id="img${id}" href="images/${escapeXml(page.name)}" media-type="${IMAGE_TYPES[ext] ?? 'image/jpeg'}"${i === cover ? ' properties="cover-image"' : ''}/>\n`
		manifest += `<item id="p${id}" href="pages/${id}.xhtml" media-type="application/xhtml+xml"/>\n`
		spine += `<itemref idref="p${id}"/>\n`
		nav += `<li><a href="pages/${id}.xhtml">${i === cover ? 'Cover' : 'Page ' + n}</a></li>\n`
	})
	manifest += '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>\n'

	files['OEBPS/nav.xhtml'] = [strToU8('<?xml version="1.0" encoding="UTF-8"?>\n<!DOCTYPE html>\n'
		+ '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><head><meta charset="utf-8"/><title>'
		+ escapeXml(meta.title || 'Comic') + '</title></head><body><nav epub:type="toc" id="toc"><ol>\n' + nav + '</ol></nav></body></html>'), { level: 6 }]
	files['OEBPS/content.opf'] = [strToU8(opf(meta, manifest, spine, rtl, pageId(cover + 1))), { level: 6 }]
	return zipSync(files)
}

/**
 * @param meta
 * @param manifest
 * @param spine
 * @param rtl
 * @param coverId
 */
function opf(meta: EpubMeta, manifest: string, spine: string, rtl: boolean, coverId: string): string {
	const lang = meta.language?.trim() || 'und'
	const bytes = crypto.getRandomValues(new Uint8Array(16))
	bytes[6] = (bytes[6] & 0x0F) | 0x40
	bytes[8] = (bytes[8] & 0x3F) | 0x80
	const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('')
	const uuid = `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
	let md = `<dc:identifier id="bookid">urn:uuid:${uuid}</dc:identifier>\n`
		+ `<dc:title>${escapeXml(meta.title?.trim() || 'Comic')}</dc:title>\n`
		+ `<dc:language>${escapeXml(lang)}</dc:language>\n`
	for (const a of meta.authors ?? []) {
		md += `<dc:creator>${escapeXml(a)}</dc:creator>\n`
	}
	if (meta.publisher?.trim()) {
		md += `<dc:publisher>${escapeXml(meta.publisher.trim())}</dc:publisher>\n`
	}
	if (meta.publishedAt?.trim()) {
		md += `<dc:date>${escapeXml(meta.publishedAt.trim())}</dc:date>\n`
	}
	const description = meta.description ? plainText(meta.description) : ''
	if (description) {
		md += `<dc:description>${escapeXml(description)}</dc:description>\n`
	}
	for (const s of [...(meta.genres ?? []), ...(meta.tags ?? [])]) {
		md += `<dc:subject>${escapeXml(s)}</dc:subject>\n`
	}
	const series = meta.series?.trim()
	if (series) {
		md += `<meta property="belongs-to-collection" id="series1">${escapeXml(series)}</meta>\n`
			+ '<meta refines="#series1" property="collection-type">series</meta>\n'
		if (typeof meta.seriesIndex === 'number') {
			md += `<meta refines="#series1" property="group-position">${formatNumber(meta.seriesIndex)}</meta>\n`
				+ `<meta name="calibre:series_index" content="${formatNumber(meta.seriesIndex)}"/>\n`
		}
		md += `<meta name="calibre:series" content="${escapeXml(series)}"/>\n`
	}
	md += `<meta property="dcterms:modified">${new Date().toISOString().replace(/\.\d+Z$/, 'Z')}</meta>\n`
		+ '<meta property="rendition:layout">pre-paginated</meta>\n'
		+ '<meta property="rendition:orientation">auto</meta>\n'
		+ '<meta property="rendition:spread">auto</meta>\n'
		+ `<meta name="cover" content="img${coverId}"/>\n`
	return '<?xml version="1.0" encoding="UTF-8"?>\n'
		+ `<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid" xml:lang="${escapeXml(lang)}">\n`
		+ `<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">\n${md}</metadata>\n`
		+ `<manifest>\n${manifest}</manifest>\n`
		+ `<spine${rtl ? ' page-progression-direction="rtl"' : ''}>\n${spine}</spine>\n`
		+ '</package>'
}

/**
 * Whether ComicInfo.xml marks the comic as right to left.
 *
 * @param xml
 */
export function comicInfoIsRtl(xml: string | null): boolean {
	return xml !== null && /<Manga>\s*YesAndRightToLeft\s*<\/Manga>/i.test(xml)
}

/**
 * Page index of the FrontCover page in ComicInfo.xml (0 if not marked).
 *
 * @param xml
 */
export function comicInfoCoverIndex(xml: string | null): number {
	if (xml === null) {
		return 0
	}
	for (const tag of xml.match(/<Page\b[^>]*>/gi) ?? []) {
		if (/\bType\s*=\s*["']FrontCover["']/i.test(tag)) {
			const m = /\bImage\s*=\s*["'](\d+)["']/i.exec(tag)
			if (m) {
				return Number(m[1])
			}
		}
	}
	return 0
}

/**
 * Minimal ComicInfo.xml from book metadata, for converted comics that had none.
 *
 * @param meta
 */
export function buildComicInfo(meta: EpubMeta): string | null {
	const parts: string[] = []
	const add = (name: string, value: string | null | undefined): void => {
		if (value && value.trim() !== '') {
			parts.push(`  <${name}>${escapeXml(value.trim())}</${name}>`)
		}
	}
	add('Title', meta.title)
	add('Series', meta.series)
	if (typeof meta.seriesIndex === 'number') {
		add('Number', formatNumber(meta.seriesIndex))
	}
	add('Writer', meta.authors?.join(', '))
	add('Publisher', meta.publisher)
	add('LanguageISO', meta.language)
	const date = /^(\d{4})(?:-(\d{2}))?(?:-(\d{2}))?$/.exec(meta.publishedAt ?? '')
	if (date) {
		add('Year', date[1])
		add('Month', date[2] ? String(Number(date[2])) : null)
		add('Day', date[3] ? String(Number(date[3])) : null)
	}
	add('Genre', meta.genres?.join(', '))
	add('Tags', meta.tags?.join(', '))
	if (parts.length === 0) {
		return null
	}
	return `<?xml version="1.0" encoding="UTF-8"?>\n<ComicInfo>\n${parts.join('\n')}\n</ComicInfo>\n`
}
