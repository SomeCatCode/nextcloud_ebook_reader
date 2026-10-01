import type { FoliateBook, OpenedBook } from './open-book.ts'
import type {
	BookInfo,
	ReaderEvents,
	ReaderFormat,
	ReaderHandle,
	ReaderLayout,
	ReaderLocator,
	ReaderOptions,
	ReaderSource,
	ReaderThemeName,
	ReaderTypography,
	SearchGroup,
	SearchOptions,
	TocItem,
} from './types.ts'

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { planNavigation, toLocator } from './locator.ts'
import { openBook } from './open-book.ts'
import { hardenBook } from './secure-sections.ts'
import { buildCss } from './themes.ts'

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Any = any

const SIDE_ZONE = 0.3

/**
 * Creates a reader inside `container`. Framework free.
 *
 * @param container
 * @param options
 */
export function createReader(container: HTMLElement, options: ReaderOptions = {}): ReaderHandle {
	// foliate registers its click/key/touch listeners inside the section documents; without
	// allow-scripts Chromium and WebKit refuse to run them ("Blocked script execution"), which
	// breaks navigation and comic rendering. Book scripts stay blocked by the section CSP,
	// the inherited Nextcloud CSP and SVG sanitizing (see secure-sections.ts).
	(globalThis as Any).__EBOOKREADER_SANDBOX = options.sandboxScripts === false
		? 'allow-same-origin'
		: 'allow-same-origin allow-scripts'
	let theme: ReaderThemeName = options.theme ?? 'auto'
	let typography: ReaderTypography = { ...options.typography }
	let layout: ReaderLayout = { flow: 'paginated', maxColumns: 2, margin: 48, comicSpread: 'single', comicZoom: 'fit-page', ...options.layout }

	const listeners = new Map<string, Set<(p: never) => void>>()
	const emit = <K extends keyof ReaderEvents>(name: K, payload: ReaderEvents[K]): void => {
		listeners.get(name)?.forEach((cb) => (cb as (p: ReaderEvents[K]) => void)(payload))
	}

	const media = globalThis.matchMedia?.('(prefers-color-scheme: dark)')
	const onMedia = (): void => applyStyles()
	media?.addEventListener?.('change', onMedia)

	let view: Any = null
	let opened: OpenedBook | null = null
	let info: BookInfo | null = null
	let lastFile: { file: ReaderSource, format: ReaderFormat } | null = null
	let lastLocator: ReaderLocator | null = null
	let sectionFraction: number | undefined
	let destroyed = false

	/** Reader host element sits inside container; recreated on every open(). */
	let host: HTMLElement | null = null

	/**
	 *
	 */
	function prefersDark(): boolean {
		return media?.matches ?? false
	}

	/**
	 *
	 */
	function applyStyles(): void {
		if (!view?.renderer) {
			return
		}
		const css = buildCss(theme, prefersDark(), typography)
		view.renderer.setStyles?.(css)
		container.style.background = getComputedBackground()
	}

	/**
	 *
	 */
	function getComputedBackground(): string {
		const dark = theme === 'dark' || (theme === 'auto' && prefersDark())
		return theme === 'sepia' ? '#f4ecd8' : (dark ? '#1c1c1e' : '#ffffff')
	}

	/**
	 *
	 */
	function applyLayout(): void {
		const r = view?.renderer
		if (!r || !opened) {
			return
		}
		if (opened.isComic || view.isFixedLayout) {
			const z = layout.comicZoom ?? 'fit-page'
			r.setAttribute('zoom', String(z))
			return
		}
		r.setAttribute('flow', layout.flow === 'scrolled' ? 'scrolled' : 'paginated')
		r.setAttribute('max-column-count', String(layout.maxColumns ?? 2))
		r.setAttribute('margin', `${layout.margin ?? 48}px`)
		r.setAttribute('animated', '')
	}

	/**
	 * @param e
	 * @param doc
	 */
	function zoneOf(e: MouseEvent, doc: Document): 'left' | 'center' | 'right' {
		const frame = doc.defaultView?.frameElement as HTMLElement | null
		const rect = frame?.getBoundingClientRect()
		const host = container.getBoundingClientRect()
		const x = (rect ? rect.left : 0) + e.clientX - host.left
		const f = host.width ? x / host.width : 0.5
		if (f < SIDE_ZONE) {
			return 'left'
		}
		if (f > 1 - SIDE_ZONE) {
			return 'right'
		}
		return 'center'
	}

	/**
	 * @param doc
	 */
	function bindDocument(doc: Document): void {
		doc.addEventListener('click', (e: MouseEvent) => {
			if (e.defaultPrevented) {
				return
			}
			if (doc.getSelection?.()?.type === 'Range') {
				return
			}
			emit('tap', { zone: zoneOf(e, doc) })
		})
		doc.addEventListener('keydown', (e: KeyboardEvent) => {
			emit('key', { key: e.key })
		})
	}

	/**
	 * @param detail
	 * @param detail.fraction
	 */
	function onRendererRelocate(detail: { fraction?: number }): void {
		sectionFraction = typeof detail?.fraction === 'number' ? detail.fraction : undefined
		const loc = view.lastLocation
		if (!loc || !opened) {
			return
		}
		const index: number = loc.section?.current ?? 0
		const section = opened.book.sections[index]
		const isComic = opened.isComic
		const locator = toLocator({
			cfi: loc.cfi,
			fraction: loc.fraction ?? 0,
			sectionIndex: index,
			sectionFraction,
			locationCurrent: loc.location?.current,
			tocLabel: loc.tocItem?.label,
			sectionHref: String(section?.id ?? ''),
		}, isComic)
		lastLocator = locator
		const percentage = isComic
			? (info && info.pageCount > 0 ? (index + 1) / info.pageCount : 0)
			: (loc.fraction ?? 0)
		emit('relocate', {
			locator,
			percentage,
			label: loc.tocItem?.label,
			page: isComic ? { current: index + 1, total: info?.pageCount ?? 0 } : (loc.location ? { current: loc.location.current + 1, total: loc.location.total } : undefined),
		})
	}

	/**
	 *
	 */
	function teardown(): void {
		if (view) {
			try {
				view.close?.()
			} catch {
				// ignore
			}
		}
		try {
			opened?.close()
		} catch {
			// ignore
		}
		host?.remove()
		host = null
		view = null
		opened = null
		info = null
	}

	/**
	 * @param target
	 */
	async function goTo(target: ReaderLocator | string): Promise<boolean> {
		if (!view || !opened) {
			return false
		}
		const book: FoliateBook = opened.book
		const steps = planNavigation(target, { sectionIds: book.sections.map((s: Any) => s.id), isComic: opened.isComic })
		for (const step of steps) {
			try {
				if (step.kind === 'cfi') {
					const r = view.resolveNavigation(step.cfi)
					if (r && r.index >= 0) {
						await view.renderer.goTo(r)
						return true
					}
				} else if (step.kind === 'section') {
					if (step.fragment) {
						const r = view.resolveNavigation(`${book.sections[step.index].id}#${step.fragment}`)
						if (r && r.index >= 0) {
							await view.renderer.goTo(r)
							return true
						}
					}
					await view.renderer.goTo({ index: step.index, anchor: step.progression })
					return true
				} else {
					await view.goToFraction(step.fraction)
					return true
				}
			} catch (e) {
				console.warn('ebookreader: navigation step failed', step, e)
			}
		}
		return false
	}

	const handle: ReaderHandle = {
		async open(file, format, initial = null) {
			teardown()
			lastFile = { file, format }
			sectionFraction = undefined
			try {
				await import('../vendor/foliate-js/view.js')
				const book = await openBook(file, format, options, layout)
				hardenBook(book.book)
				opened = book.isComic ? book : book
				host = document.createElement('div')
				host.style.cssText = 'position:absolute;inset:0;'
				container.style.position ||= 'relative'
				container.append(host)
				view = document.createElement('foliate-view')
				view.style.cssText = 'display:block;width:100%;height:100%;'
				host.append(view)
				// Never let foliate open book links itself: always cancel and hand the URL to the UI,
				// which asks for confirmation (see docs/SECURITY-READER.md).
				view.addEventListener('external-link', (e: CustomEvent<{ href_?: string, a?: Element }>) => {
					e.preventDefault()
					const url = String(e.detail?.href_ ?? e.detail?.a?.getAttribute?.('href') ?? '')
					if (url) {
						emit('external-link', { url })
					}
				})
				view.addEventListener('load', (e: CustomEvent<{ doc: Document }>) => {
					bindDocument(e.detail.doc)
				})
				await view.open(book.book)
				view.renderer.addEventListener('relocate', (e: CustomEvent) => onRendererRelocate(e.detail))
				const md = book.book.metadata ?? {}
				const pickName = (x: Any): string => typeof x === 'string' ? x : (x?.name ? (typeof x.name === 'string' ? x.name : Object.values(x.name)[0] as string) : '')
				const title = typeof md.title === 'string' ? md.title : (md.title ? String(Object.values(md.title)[0]) : '')
				const authorsRaw = Array.isArray(md.author) ? md.author : (md.author ? [md.author] : [])
				info = {
					title,
					authors: authorsRaw.map(pickName).filter(Boolean),
					language: Array.isArray(md.language) ? md.language[0] : md.language,
					isComic: book.isComic,
					fixedLayout: !!view.isFixedLayout,
					rtl: book.book.dir === 'rtl',
					pageCount: book.book.sections.length,
				}
				applyLayout()
				applyStyles()
				let went = false
				if (initial) {
					went = await goTo(initial)
				}
				if (!went) {
					await view.init({ showTextStart: false })
				}
				emit('ready', info)
			} catch (e) {
				teardown()
				const err = e instanceof Error ? e : new Error(String(e))
				emit('error', err)
				throw err
			}
		},
		goTo,
		async next() {
			await view?.next()
		},
		async prev() {
			await view?.prev()
		},
		setTheme(t) {
			theme = t
			applyStyles()
		},
		setTypography(t) {
			typography = { ...typography, ...t }
			applyStyles()
		},
		async setLayout(l) {
			const before = layout
			layout = { ...layout, ...l }
			const comicChange = opened?.isComic && (before.comicSpread !== layout.comicSpread || before.comicRtl !== layout.comicRtl)
			if (comicChange && lastFile) {
				// spread/direction are fixed when the renderer opens: re-open at the current position
				await handle.open(lastFile.file, lastFile.format, lastLocator)
				return
			}
			applyLayout()
		},
		getToc() {
			const map = (items: Any[] | undefined): TocItem[] => (items ?? []).map((i) => ({
				label: String(i.label ?? ''),
				href: String(i.href ?? ''),
				subitems: i.subitems?.length ? map(i.subitems) : undefined,
			}))
			return map(opened?.book.toc)
		},
		getInfo: () => info,
		async* search(opts: SearchOptions): AsyncGenerator<SearchGroup | { progress: number }, void, void> {
			if (!view) {
				return
			}
			for await (const r of view.search({ query: opts.query, matchCase: opts.matchCase, matchWholeWords: opts.wholeWords })) {
				if (r === 'done') {
					return
				}
				if (r.subitems) {
					yield { label: r.label, subitems: r.subitems }
				} else if (typeof r.progress === 'number') {
					yield { progress: r.progress }
				}
			}
		},
		async goToCfi(cfi) {
			await view?.goTo(cfi)
		},
		clearSearch() {
			view?.clearSearch?.()
		},
		async getCover() {
			try {
				return (await opened?.book.getCover?.()) ?? null
			} catch {
				return null
			}
		},
		on(event, cb) {
			let set = listeners.get(event)
			if (!set) {
				set = new Set()
				listeners.set(event, set)
			}
			set.add(cb as (p: never) => void)
			return () => set.delete(cb as (p: never) => void)
		},
		destroy() {
			if (destroyed) {
				return
			}
			destroyed = true
			media?.removeEventListener?.('change', onMedia)
			teardown()
			listeners.clear()
		},
	}
	return handle
}
