/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Seamless vertical scroll view for long strip comics (webtoons). foliate's fixed layout renderer shows one
 * spread at a time, so long strips get their own renderer: all pages are stacked without gaps in one scroll
 * container, images are loaded near the viewport and released again far away from it.
 */

export interface LongstripPage {
	id: string
}

export interface LongstripOptions {
	pages: LongstripPage[]
	loadBlob: (index: number) => Promise<Blob> | null
	/** current scroll position: page index and fraction inside that page */
	onRelocate: (index: number, progression: number) => void
	onTap: (zone: 'left' | 'center' | 'right') => void
}

/** Height/width ratio assumed for pages that were not loaded yet (typical webtoon segment). */
const DEFAULT_RATIO = 2.5
/** Images are loaded this far (in viewport heights) above and below the visible area. */
const LOAD_MARGIN = 2
/** Images further away than this (in viewport heights) are released; their measured height is kept. */
const UNLOAD_MARGIN = 6
const SIDE_ZONE = 0.3

interface Slot {
	el: HTMLDivElement
	img: HTMLImageElement | null
	url: string | null
	loading: boolean
	/** height / width of the image, once known */
	ratio: number | null
}

export class LongstripView {
	readonly root: HTMLDivElement
	private readonly column: HTMLDivElement
	private readonly slots: Slot[]
	private readonly opts: LongstripOptions
	private observer: IntersectionObserver | null = null
	private resizeObserver: ResizeObserver | null = null
	private frame = 0
	private destroyed = false
	/** page width: 'natural' = natural image width capped by the container, 'fit' = container width, number = natural width * zoom */
	private zoom: 'natural' | 'fit' | number = 'natural'
	private naturalWidth = 0
	private fallbackRatio = DEFAULT_RATIO

	constructor(host: HTMLElement, opts: LongstripOptions) {
		this.opts = opts
		this.root = document.createElement('div')
		this.root.className = 'ebookreader-longstrip'
		this.root.tabIndex = 0
		// overflow-anchor is not supported by Safari: the scroll position is kept stable by hand (see setRatio)
		this.root.style.cssText = 'position:absolute;inset:0;overflow-y:auto;overflow-x:hidden;overflow-anchor:none;outline:none;-webkit-overflow-scrolling:touch;'
		this.column = document.createElement('div')
		this.column.style.cssText = 'margin:0 auto;display:flex;flex-direction:column;'
		this.root.append(this.column)
		this.slots = opts.pages.map((p, i) => {
			const el = document.createElement('div')
			el.dataset.index = String(i)
			el.setAttribute('aria-label', p.id)
			el.style.cssText = `position:relative;width:100%;aspect-ratio:1 / ${DEFAULT_RATIO};flex:none;`
			this.column.append(el)
			return { el, img: null, url: null, loading: false, ratio: null }
		})
		host.append(this.root)
		this.applyWidth()

		this.root.addEventListener('scroll', this.onScroll, { passive: true })
		this.root.addEventListener('click', this.onClick)
		this.observer = new IntersectionObserver(this.onIntersect, { root: this.root, rootMargin: `${LOAD_MARGIN * 100}% 0px` })
		this.slots.forEach((s) => this.observer!.observe(s.el))
		this.resizeObserver = new ResizeObserver(() => this.applyWidth())
		this.resizeObserver.observe(this.root)
	}

	/** Index and in-page fraction of the page at the top of the viewport. */
	position(): { index: number, progression: number } {
		const top = this.root.scrollTop
		let lo = 0
		let hi = this.slots.length - 1
		while (lo < hi) {
			const mid = (lo + hi + 1) >> 1
			if (this.slots[mid].el.offsetTop <= top) {
				lo = mid
			} else {
				hi = mid - 1
			}
		}
		const el = this.slots[lo]?.el
		if (!el) {
			return { index: 0, progression: 0 }
		}
		const h = el.offsetHeight || 1
		return { index: lo, progression: Math.min(1, Math.max(0, (top - el.offsetTop) / h)) }
	}

	/**
	 * Scrolls to a page (and fraction inside it); waits for that page so its height is known.
	 *
	 * @param index page index
	 * @param progression fraction inside the page
	 */
	async goTo(index: number, progression = 0): Promise<void> {
		const i = Math.min(this.slots.length - 1, Math.max(0, index))
		const scroll = (): void => {
			const el = this.slots[i].el
			this.root.scrollTop = el.offsetTop + progression * el.offsetHeight
		}
		scroll()
		await this.load(i)
		scroll()
		this.report()
	}

	/** One screen down (keeps a small overlap so no line is skipped). */
	next(): void {
		this.root.scrollBy({ top: this.root.clientHeight * 0.9, behavior: 'smooth' })
	}

	prev(): void {
		this.root.scrollBy({ top: -this.root.clientHeight * 0.9, behavior: 'smooth' })
	}

	/**
	 * Small step, like the arrow keys on a web page (keys are handled by the UI, which listens on the window).
	 *
	 * @param direction 1 = down, -1 = up
	 */
	step(direction: 1 | -1): void {
		this.root.scrollBy({ top: direction * this.root.clientHeight * 0.15, behavior: 'smooth' })
	}

	setZoom(zoom: 'fit-page' | 'fit-width' | number | undefined): void {
		this.zoom = zoom === 'fit-width' ? 'fit' : (typeof zoom === 'number' && zoom > 0 ? zoom : 'natural')
		this.applyWidth()
	}

	focus(): void {
		this.root.focus({ preventScroll: true })
	}

	destroy(): void {
		this.destroyed = true
		cancelAnimationFrame(this.frame)
		this.observer?.disconnect()
		this.resizeObserver?.disconnect()
		this.root.removeEventListener('scroll', this.onScroll)
		this.root.removeEventListener('click', this.onClick)
		for (const s of this.slots) {
			if (s.url) {
				URL.revokeObjectURL(s.url)
			}
		}
		this.root.remove()
	}

	private applyWidth(): void {
		const pos = this.position()
		const max = this.root.clientWidth
		let width = max
		if (this.zoom === 'natural' && this.naturalWidth > 0) {
			width = Math.min(max, this.naturalWidth)
		} else if (typeof this.zoom === 'number' && this.naturalWidth > 0) {
			width = this.naturalWidth * this.zoom
		}
		const before = this.column.style.width
		this.column.style.width = `${Math.max(1, Math.round(width))}px`
		if (before !== this.column.style.width) {
			// page heights scale with the width: stay on the same spot
			const el = this.slots[pos.index]?.el
			if (el) {
				this.root.scrollTop = el.offsetTop + pos.progression * el.offsetHeight
			}
		}
	}

	private onIntersect = (entries: IntersectionObserverEntry[]): void => {
		for (const e of entries) {
			if (e.isIntersecting) {
				void this.load(Number((e.target as HTMLElement).dataset.index))
			}
		}
		this.release()
	}

	/** Frees images far away from the viewport (memory stays bounded for thousands of pages). */
	private release(): void {
		const top = this.root.scrollTop
		const h = this.root.clientHeight
		const min = top - UNLOAD_MARGIN * h
		const max = top + (UNLOAD_MARGIN + 1) * h
		for (const s of this.slots) {
			if (!s.img) {
				continue
			}
			const y = s.el.offsetTop
			if (y + s.el.offsetHeight < min || y > max) {
				s.img.remove()
				s.img = null
				if (s.url) {
					URL.revokeObjectURL(s.url)
					s.url = null
				}
			}
		}
	}

	private async load(index: number): Promise<void> {
		const s = this.slots[index]
		if (!s || s.img || s.loading || this.destroyed) {
			return
		}
		s.loading = true
		try {
			const blob = await this.opts.loadBlob(index)
			if (!blob || this.destroyed) {
				return
			}
			const url = URL.createObjectURL(blob)
			const img = new Image()
			img.alt = ''
			img.draggable = false
			img.decoding = 'async'
			img.style.cssText = 'display:block;width:100%;height:100%;user-select:none;-webkit-user-select:none;'
			img.src = url
			try {
				await img.decode()
			} catch {
				// broken image: keep the placeholder height
			}
			if (this.destroyed) {
				URL.revokeObjectURL(url)
				return
			}
			s.img = img
			s.url = url
			if (img.naturalWidth > 0 && img.naturalHeight > 0) {
				if (this.naturalWidth === 0) {
					this.naturalWidth = img.naturalWidth
					this.applyWidth()
				}
				this.setRatio(index, img.naturalHeight / img.naturalWidth)
			}
			s.el.append(img)
		} catch (e) {
			console.warn('ebookreader: could not load long strip page', index, e)
		} finally {
			s.loading = false
		}
	}

	/**
	 * Sets the measured height of a page; pages above the viewport shift the scroll position by the difference.
	 *
	 * @param index page index
	 * @param ratio height / width
	 */
	private setRatio(index: number, ratio: number): void {
		const s = this.slots[index]
		if (s.ratio === ratio) {
			return
		}
		const firstMeasure = s.ratio === null
		const above = s.el.offsetTop + s.el.offsetHeight <= this.root.scrollTop
		const before = s.el.offsetHeight
		s.ratio = ratio
		s.el.style.aspectRatio = `1 / ${ratio}`
		if (above) {
			this.root.scrollTop += s.el.offsetHeight - before
		}
		if (firstMeasure && this.fallbackRatio === DEFAULT_RATIO) {
			// strips usually have segments of one size: use the first measured one for the unknown pages
			this.fallbackRatio = ratio
			const pos = this.position()
			for (const o of this.slots) {
				if (o.ratio === null) {
					o.el.style.aspectRatio = `1 / ${ratio}`
				}
			}
			const el = this.slots[pos.index].el
			this.root.scrollTop = el.offsetTop + pos.progression * el.offsetHeight
		}
	}

	private onScroll = (): void => {
		if (this.frame) {
			return
		}
		this.frame = requestAnimationFrame(() => {
			this.frame = 0
			this.report()
		})
	}

	private report(): void {
		if (this.destroyed) {
			return
		}
		const { index, progression } = this.position()
		this.opts.onRelocate(index, progression)
	}

	private onClick = (e: MouseEvent): void => {
		const rect = this.root.getBoundingClientRect()
		const f = rect.width ? (e.clientX - rect.left) / rect.width : 0.5
		this.opts.onTap(f < SIDE_ZONE ? 'left' : (f > 1 - SIDE_ZONE ? 'right' : 'center'))
	}
}
