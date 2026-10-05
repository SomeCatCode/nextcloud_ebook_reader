/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export type ReaderFormat = 'epub' | 'mobi' | 'azw3' | 'fb2' | 'fbz' | 'cbz' | 'cbr' | 'cb7' | 'cbt'
export type ReaderThemeName = 'auto' | 'light' | 'dark' | 'sepia'

/** Readium-compatible locator (mirrors CONTRACTS section 3). */
export interface ReaderLocator {
	href: string
	type?: string
	title?: string
	locations?: {
		progression?: number
		totalProgression?: number
		position?: number
		cfi?: string
	}
}

export interface ReaderLayout {
	flow?: 'paginated' | 'scrolled'
	/** 1 = single column, 2 = allow two columns on wide screens */
	maxColumns?: number
	margin?: number
	/** comics */
	comicSpread?: 'single' | 'double'
	/** comics: right-to-left reading direction (manga) */
	comicRtl?: boolean
	/** comics: zoom */
	comicZoom?: 'fit-page' | 'fit-width' | number
}

export interface ReaderTypography {
	fontSize?: number
	fontFamily?: string
	lineHeight?: number
}

export interface ReaderOptions {
	theme?: ReaderThemeName
	typography?: ReaderTypography
	layout?: ReaderLayout
	/**
	 * Grant allow-scripts to the section iframes (default true). foliate's own listeners inside the
	 * section documents need it; book scripts are still blocked by the section CSP meta, the
	 * inherited page CSP and SVG sanitizing. `false` = strict sandbox (breaks navigation in Chromium).
	 */
	sandboxScripts?: boolean
	/** Provides libarchive's worker source + wasm URL (CBR). Resolved lazily. */
	loadLibarchive?: () => Promise<{ workerSource: string, wasmUrl: string }>
}

export interface TocItem {
	label: string
	href: string
	subitems?: TocItem[]
}

export interface SearchHit {
	cfi: string
	excerpt: { pre: string, match: string, post: string }
}

export interface SearchGroup {
	label: string
	subitems: SearchHit[]
}

export interface SearchOptions {
	query: string
	matchCase?: boolean
	wholeWords?: boolean
}

export interface BookInfo {
	title: string
	authors: string[]
	language?: string
	isComic: boolean
	fixedLayout: boolean
	rtl: boolean
	pageCount: number
}

export type AnnotationColor = 'yellow' | 'green' | 'blue' | 'pink' | 'purple'

/** A highlight to draw into the book (reflowable formats only). `cfi` is the range CFI of the text. */
export interface ReaderAnnotation {
	/** Annotation id (uuid), returned with `annotation-click` */
	id: string
	cfi: string
	color: AnnotationColor | null
	/** has a note: drawn with an additional underline */
	hasNote: boolean
}

/** Text the user has selected in the book. Coordinates are relative to the reader container (px). */
export interface ReaderSelection {
	text: string
	/** Range CFI of the selection */
	cfi: string
	/** Locator of the selection start (href, progression, totalProgression, cfi) */
	locator: ReaderLocator
	rect: { left: number, top: number, right: number, bottom: number }
}

export interface ReaderEvents {
	relocate: { locator: ReaderLocator, percentage: number, label?: string, page?: { current: number, total: number } }
	ready: BookInfo
	error: Error
	/** Emitted for a tap/click on the page */
	tap: { zone: 'left' | 'center' | 'right' }
	/** A key pressed inside the book. `modified`: Ctrl, Alt or Meta was held (browser shortcuts, not ours) */
	key: { key: string, modified?: boolean }
	/** The user selected text (reflowable books). The UI offers highlight/note/copy. */
	selection: ReaderSelection
	/** The selection is gone (collapsed, page turned) */
	'selection-clear': Record<string, never>
	/** The user clicked a drawn highlight */
	'annotation-click': { id: string, rect: ReaderSelection['rect'] }
	/** A link in the book points outside of it. foliate's default (window.open) is always cancelled; the UI decides. */
	'external-link': { url: string }
}

/**
 * A comic whose pages are fetched one by one (e.g. from the server) instead of downloading and
 * unpacking the whole archive in the browser.
 */
export interface RemoteComicSource {
	kind: 'remote-comic'
	name: string
	/** Page entry names in reading order (used as section ids / locator hrefs) and their sizes */
	pages: { name: string, size: number }[]
	loadPage: (index: number) => Promise<Blob>
}

/**
 * A ZIP based book (EPUB, FBZ) whose entries are fetched one by one from the server instead of
 * downloading the whole file.
 */
export interface RemoteZipSource {
	kind: 'remote-zip'
	name: string
	entries: { name: string, size: number }[]
	loadEntry: (name: string) => Promise<Blob>
}

export type ReaderSource = Blob | File | RemoteComicSource | RemoteZipSource

export interface ReaderHandle {
	open(file: ReaderSource, format: ReaderFormat, initial?: ReaderLocator | null): Promise<void>
	goTo(target: ReaderLocator | string): Promise<boolean>
	next(): Promise<void>
	prev(): Promise<void>
	setTheme(theme: ReaderThemeName): void
	setTypography(typography: ReaderTypography): void
	setLayout(layout: ReaderLayout): Promise<void>
	getToc(): TocItem[]
	getInfo(): BookInfo | null
	search(opts: SearchOptions): AsyncGenerator<SearchGroup | { progress: number }, void, void>
	goToCfi(cfi: string): Promise<void>
	/** Cover of the open book (first page of a comic), or null if the format has none */
	getCover(): Promise<Blob | null>
	clearSearch(): void
	/** Replaces the highlights drawn in the book. No-op for comics and fixed layouts. */
	setAnnotations(annotations: ReaderAnnotation[]): void
	/** True when highlights/selection can be used with the open book (reflowable text). */
	supportsAnnotations(): boolean
	/** Removes the text selection in the book */
	clearSelection(): void
	/** Locator of the visible page; its cfi is the start of the page. Used for bookmarks. */
	getPageLocator(): ReaderLocator | null
	/** Is the locator (e.g. of a bookmark) on the visible page? */
	isLocatorOnPage(locator: ReaderLocator): boolean
	on<K extends keyof ReaderEvents>(event: K, cb: (payload: ReaderEvents[K]) => void): () => void
	destroy(): void
}
