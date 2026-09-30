/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { Permission, registerFileAction } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

const EBOOK_MIMES = [
	'application/epub+zip',
	'application/x-mobipocket-ebook',
	'application/vnd.amazon.mobi8-ebook',
	'application/x-fictionbook+xml',
	'application/x-zip-compressed-fb2',
	'application/vnd.comicbook+zip',
	'application/vnd.comicbook-rar',
	'application/comicbook+zip',
	'application/comicbook+rar',
]

const BOOK_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M18 2H6C4.9 2 4 2.9 4 4V20C4 21.1 4.9 22 6 22H18C19.1 22 20 21.1 20 20V4C20 2.9 19.1 2 18 2M6 4H11V12L8.5 10.5L6 12V4Z" /></svg>'
const PENCIL_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M20.71 7.04C21.1 6.65 21.1 6 20.71 5.63L18.37 3.29C18 2.9 17.35 2.9 16.96 3.29L15.12 5.12L18.87 8.87M3 17.25V21H6.75L17.81 9.93L14.06 6.18L3 17.25Z" /></svg>'

/**
 * @param nodes
 */
function isEbook(nodes: { mime?: string, fileid?: number }[]): boolean {
	return nodes.length === 1 && nodes[0].fileid !== undefined && EBOOK_MIMES.includes(nodes[0].mime ?? '')
}

registerFileAction({
	id: 'ebookreader-open',
	displayName: () => t('ebookreader', 'Open in E-Book Reader'),
	iconSvgInline: () => BOOK_SVG,
	enabled: ({ nodes }) => isEbook(nodes),
	async exec({ nodes }) {
		window.location.href = generateUrl('/apps/ebookreader/read/{fileId}', { fileId: nodes[0].fileid as number })
		return null
	},
	order: 50,
})

registerFileAction({
	id: 'ebookreader-edit',
	displayName: () => t('ebookreader', 'Edit e-book'),
	iconSvgInline: () => PENCIL_SVG,
	enabled: ({ nodes }) => isEbook(nodes) && nodes.every((n) => (n.permissions & Permission.READ) !== 0),
	async exec({ nodes }) {
		window.location.href = generateUrl('/apps/ebookreader/edit/{fileId}', { fileId: nodes[0].fileid as number })
		return null
	},
	order: 51,
})
