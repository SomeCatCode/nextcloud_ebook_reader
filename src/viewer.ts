/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * OCA.Viewer handler for e-book MIME types.
 *
 * nextcloud/viewer (master) is still a Vue 2 app and registers handler components with
 * `Vue.component(...)`, so a Vue 3 SFC can not be handed over directly. The handler component is a
 * plain Vue 2 compatible options object (render function, no Vue import) that embeds the reader
 * SPA route `/apps/ebookreader/read/{fileid}?embedded=1` in a same-origin iframe. This keeps the
 * reader on one code path and works with whatever Vue version the Viewer uses.
 * On a public link share (no login) the iframe shows the public reader `/apps/ebookreader/s/{token}?path=...`
 * instead (PublicReaderController).
 * See docs/SECURITY-READER.md, "Viewer integration".
 */
import { generateUrl } from '@nextcloud/router'
import { getSharingToken, isPublicShare } from '@nextcloud/sharing/public'

export const EBOOK_MIMES = [
	'application/epub+zip',
	'application/x-mobipocket-ebook',
	'application/vnd.amazon.mobi8-ebook',
	'application/x-fictionbook+xml',
	'application/x-zip-compressed-fb2',
	'application/vnd.comicbook+zip',
	'application/vnd.comicbook-rar',
	'application/comicbook+zip',
	'application/comicbook+rar',
	'application/x-cb7',
	'application/x-cbt',
]

interface ViewerGlobal {
	OCA?: { Viewer?: { registerHandler?: (handler: unknown) => void } }
	_oca_viewer_handlers?: unknown[]
}

/**
 * Path of a file inside a public share, taken from its WebDAV URL (`.../public.php/dav/files/<token>/<path>`).
 *
 * @param url WebDAV URL of the file
 * @param token share token
 */
export function publicSharePath(url: string, token: string): string | null {
	const marker = '/public.php/dav/files/' + token
	const i = url.indexOf(marker)
	if (i < 0) {
		return null
	}
	try {
		return decodeURIComponent(url.slice(i + marker.length).split(/[?#]/)[0]) || '/'
	} catch {
		return null
	}
}

const EbookReaderViewer = {
	name: 'EbookReaderViewer',
	props: {
		fileid: { type: [Number, String], default: null },
		filename: { type: String, default: '' },
		source: { type: String, default: '' },
		davPath: { type: String, default: '' },
		active: { type: Boolean, default: false },
	},
	computed: {
		src(this: { fileid: number | string | null, filename: string, source: string, davPath: string }): string {
			const token = isPublicShare() ? getSharingToken() : null
			if (token) {
				const path = publicSharePath(this.source || this.davPath, token) ?? this.filename
				return generateUrl('/apps/ebookreader/s/{token}', { token }) + '?embedded=1&path=' + encodeURIComponent(path)
			}
			return generateUrl('/apps/ebookreader/read/{fileid}', { fileid: String(this.fileid) }) + '?embedded=1'
		},
	},
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	render(this: any, h: (...args: any[]) => unknown) {
		if (!this.active || this.fileid === null) {
			return h('div')
		}
		return h('iframe', {
			attrs: { src: this.src, title: this.filename, allow: 'fullscreen' },
			style: { position: 'absolute', inset: '0', width: '100%', height: '100%', border: '0', background: '#fff' },
		})
	},
}

const handler = {
	id: 'ebookreader',
	mimes: EBOOK_MIMES,
	component: EbookReaderViewer,
	group: null,
	theme: 'default',
	canCompare: false,
}

/**
 *
 */
function register(): void {
	const g = window as unknown as ViewerGlobal
	if (g.OCA?.Viewer?.registerHandler) {
		g.OCA.Viewer.registerHandler(handler)
	} else {
		// Viewer not initialised yet: it registers everything from this list on startup.
		g._oca_viewer_handlers ??= []
		g._oca_viewer_handlers.push(handler)
	}
}

register()
