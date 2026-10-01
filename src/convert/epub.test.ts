/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { strFromU8, unzipSync } from 'fflate'
import { describe, expect, it } from 'vitest'
import { buildComicInfo, buildEpub, comicInfoCoverIndex, comicInfoIsRtl, epubPageHrefs } from './epub.ts'

function pages(n: number) {
	return Array.from({ length: n }, (_, i) => ({
		name: `${String(i + 1).padStart(4, '0')}.png`,
		data: new Uint8Array([i, 1, 2, 3]),
		width: 800,
		height: 1200 + i,
	}))
}

describe('buildEpub', () => {
	const meta = { title: 'A & B', authors: ['Alice', 'Bob <Co>'], series: 'Series', seriesIndex: 2, language: 'en', genres: ['Action'], tags: ['x'], description: '<p>Hi &amp; bye</p>' }

	it('writes mimetype first and stored', () => {
		const zip = buildEpub(pages(3), meta, false)
		// first local file header: method at offset 8, name at offset 30
		const view = new DataView(zip.buffer, zip.byteOffset)
		expect(view.getUint16(8, true)).toBe(0)
		const nameLength = view.getUint16(26, true)
		expect(strFromU8(zip.slice(30, 30 + nameLength))).toBe('mimetype')
		expect(strFromU8(unzipSync(zip).mimetype)).toBe('application/epub+zip')
	})

	it('creates one spine item and one page document per image, pre-paginated', () => {
		const files = unzipSync(buildEpub(pages(3), meta, false, 1))
		const opf = strFromU8(files['OEBPS/content.opf'])
		expect(opf).toContain('<meta property="rendition:layout">pre-paginated</meta>')
		expect(opf.match(/<itemref /g)).toHaveLength(3)
		expect(opf).toContain('<dc:title>A &amp; B</dc:title>')
		expect(opf).toContain('<dc:creator>Bob &lt;Co&gt;</dc:creator>')
		expect(opf).toContain('<dc:description>Hi &amp; bye</dc:description>')
		expect(opf).toContain('<meta refines="#series1" property="group-position">2</meta>')
		expect(opf).toContain('<item id="img0002" href="images/0002.png" media-type="image/png" properties="cover-image"/>')
		expect(opf).not.toContain('page-progression-direction')
		for (let n = 1; n <= 3; n++) {
			const id = String(n).padStart(4, '0')
			expect(Object.keys(files)).toContain(`OEBPS/pages/${id}.xhtml`)
			expect(Object.keys(files)).toContain(`OEBPS/images/${id}.png`)
			expect(strFromU8(files[`OEBPS/pages/${id}.xhtml`])).toContain('<meta name="viewport" content="width=800, height=')
		}
		expect(strFromU8(files['OEBPS/nav.xhtml'])).toContain('epub:type="toc"')
		expect(epubPageHrefs(3)).toEqual(['OEBPS/pages/0001.xhtml', 'OEBPS/pages/0002.xhtml', 'OEBPS/pages/0003.xhtml'])
	})

	it('sets the page progression for right to left comics', () => {
		const files = unzipSync(buildEpub(pages(2), {}, true))
		expect(strFromU8(files['OEBPS/content.opf'])).toContain('<spine page-progression-direction="rtl">')
	})
})

describe('ComicInfo helpers', () => {
	it('detects manga direction and the cover page', () => {
		const xml = '<ComicInfo><Manga>YesAndRightToLeft</Manga><Pages><Page Image="0"/><Page Type="FrontCover" Image="2"/></Pages></ComicInfo>'
		expect(comicInfoIsRtl(xml)).toBe(true)
		expect(comicInfoIsRtl('<ComicInfo><Manga>No</Manga></ComicInfo>')).toBe(false)
		expect(comicInfoIsRtl(null)).toBe(false)
		expect(comicInfoCoverIndex(xml)).toBe(2)
		expect(comicInfoCoverIndex(null)).toBe(0)
	})

	it('builds a ComicInfo from book metadata, or nothing', () => {
		const xml = buildComicInfo({ title: 'T & U', authors: ['A', 'B'], series: 'S', seriesIndex: 1.5, publishedAt: '2018-09-03', genres: ['G'] })
		expect(xml).toContain('<Title>T &amp; U</Title>')
		expect(xml).toContain('<Writer>A, B</Writer>')
		expect(xml).toContain('<Number>1.5</Number>')
		expect(xml).toContain('<Year>2018</Year>')
		expect(xml).toContain('<Month>9</Month>')
		expect(buildComicInfo({})).toBeNull()
	})
})
