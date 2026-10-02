/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { ComparisonRow, ConvertFormat, FormatInfo } from './types.ts'

import { translate as t } from '@nextcloud/l10n'

/** All formats, in display order. */
export const FORMAT_KEYS: readonly ConvertFormat[] = ['cbz', 'cbr', 'cb7', 'cbt', 'epub']

/** The format recommended in general (and for CBR in particular). */
export const RECOMMENDED_FORMAT: ConvertFormat = 'cbz'

/**
 * Description, pros and cons of every format. Built lazily so the strings are translated with the
 * language that is active when the dialog opens.
 *
 * @param key
 */
export function formatInfo(key: ConvertFormat): FormatInfo {
	switch (key) {
		case 'cbz':
			return {
				key,
				name: t('ebookreader', 'CBZ (ZIP)'),
				extension: 'cbz',
				summary: t('ebookreader', 'A ZIP archive of page images. The standard format for digital comics.'),
				pros: [
					t('ebookreader', 'Most compatible: every comic reader, Calibre, browsers, and Kobo/Kindle after a conversion'),
					t('ebookreader', 'Fast access to single pages'),
					t('ebookreader', 'Open format, can be created with free software'),
					t('ebookreader', 'Recommended default'),
				],
				cons: [
					t('ebookreader', 'The images are already compressed, so the file is barely smaller than the other formats'),
				],
				compatibility: t('ebookreader', 'Supported by practically all comic readers and apps.'),
			}
		case 'cbr':
			return {
				key,
				name: t('ebookreader', 'CBR (RAR)'),
				extension: 'cbr',
				summary: t('ebookreader', 'A RAR archive of page images. Very common in older comic collections.'),
				pros: [
					t('ebookreader', 'Very common in old collections, so most comic readers can open it'),
				],
				cons: [
					t('ebookreader', 'Proprietary format: it cannot be created with free software'),
					t('ebookreader', 'Slower page access than CBZ'),
					t('ebookreader', 'Some readers and devices do not support RAR5'),
					t('ebookreader', 'Recommendation: convert to CBZ'),
				],
				compatibility: t('ebookreader', 'Widely supported, but newer RAR5 files fail in some apps.'),
			}
		case 'cb7':
			return {
				key,
				name: t('ebookreader', 'CB7 (7z)'),
				extension: 'cb7',
				summary: t('ebookreader', 'A 7z archive of page images.'),
				pros: [
					t('ebookreader', 'Open format'),
					t('ebookreader', 'Best compression for text files and other non-image extras'),
				],
				cons: [
					t('ebookreader', 'Images barely shrink, they are compressed already'),
					t('ebookreader', 'Slower to open than CBZ'),
					t('ebookreader', 'Poor support in devices and apps'),
				],
				compatibility: t('ebookreader', 'Only some comic readers can open it.'),
			}
		case 'cbt':
			return {
				key,
				name: t('ebookreader', 'CBT (tar)'),
				extension: 'cbt',
				summary: t('ebookreader', 'A plain tar archive of page images, without compression.'),
				pros: [
					t('ebookreader', 'Simple and open'),
					t('ebookreader', 'No compression step, so packing and unpacking is cheap'),
				],
				cons: [
					t('ebookreader', 'Rarely supported'),
					t('ebookreader', 'No random access: the whole file is read to find a page'),
					t('ebookreader', 'Larger than the compressed formats when the pages are not images'),
				],
				compatibility: t('ebookreader', 'Few comic readers can open it.'),
			}
		case 'epub':
			return {
				key,
				name: t('ebookreader', 'EPUB (fixed layout)'),
				extension: 'epub',
				summary: t('ebookreader', 'An e-book with one page image per page, laid out at a fixed size.'),
				pros: [
					t('ebookreader', 'Works on e-readers, tablets and Apple Books'),
					t('ebookreader', 'Metadata and table of contents are built in'),
				],
				cons: [
					t('ebookreader', 'Larger than the comic formats'),
					t('ebookreader', 'Not every reader handles fixed layout well'),
					t('ebookreader', 'No comic-specific features such as double pages or reading direction in every app'),
				],
				compatibility: t('ebookreader', 'Supported by e-book readers that understand fixed layout.'),
			}
	}
}

/** Info entries of all formats in display order. */
export function allFormatInfos(): FormatInfo[] {
	return FORMAT_KEYS.map(formatInfo)
}

/**
 * Rows of the comparison table.
 */
export function comparisonRows(): ComparisonRow[] {
	const high = t('ebookreader', 'High')
	const medium = t('ebookreader', 'Medium')
	const low = t('ebookreader', 'Low')
	const yes = t('ebookreader', 'Yes')
	const no = t('ebookreader', 'No')
	return [
		{
			key: 'compatibility',
			label: t('ebookreader', 'Compatibility'),
			cells: { cbz: high, cbr: medium, cb7: low, cbt: low, epub: medium },
		},
		{
			key: 'access',
			label: t('ebookreader', 'Page access'),
			cells: {
				cbz: t('ebookreader', 'Fast'),
				cbr: t('ebookreader', 'Slower'),
				cb7: t('ebookreader', 'Slower'),
				cbt: t('ebookreader', 'Reads the whole file'),
				epub: t('ebookreader', 'Fast'),
			},
		},
		{
			key: 'open',
			label: t('ebookreader', 'Open format'),
			cells: { cbz: yes, cbr: no, cb7: yes, cbt: yes, epub: yes },
		},
		{
			key: 'create',
			label: t('ebookreader', 'Can be created here'),
			cells: { cbz: yes, cbr: no, cb7: yes, cbt: yes, epub: yes },
		},
		{
			key: 'metadata',
			label: t('ebookreader', 'Built-in metadata'),
			cells: { cbz: yes, cbr: yes, cb7: yes, cbt: yes, epub: yes },
		},
	]
}

/**
 * Server reasons are English; map the known ones to translated texts.
 *
 * @param reason
 */
export function translateReason(reason: string | undefined): string {
	switch (reason) {
		case undefined:
		case '':
			return ''
		case 'This is the current format':
			return t('ebookreader', 'This is the current format')
		case 'RAR can only be written with proprietary software':
			return t('ebookreader', 'RAR can only be written with proprietary software')
		case 'A file with this name already exists':
			return t('ebookreader', 'A file with this name already exists')
		case 'You cannot create files in this folder':
			return t('ebookreader', 'You cannot create files in this folder')
		case 'Image optimization is not available on this server':
			return t('ebookreader', 'Image optimization is not available on this server')
		case 'The server cannot read this format (bsdtar or unrar is required for CBR)':
			return t('ebookreader', 'Optimizing images needs bsdtar or unrar on the server to read CBR files.')
		default:
			return reason
	}
}

/**
 * @param value
 */
export function isConvertFormat(value: string): value is ConvertFormat {
	return (FORMAT_KEYS as readonly string[]).includes(value)
}
