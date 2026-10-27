/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { LongstripView } from './longstrip.ts'

/** IntersectionObserver stand-in: tests trigger intersections by hand */
class FakeIntersectionObserver {
	static last: FakeIntersectionObserver | null = null
	readonly targets: Element[] = []
	constructor(public readonly cb: IntersectionObserverCallback) {
		FakeIntersectionObserver.last = this
	}

	observe(el: Element): void {
		this.targets.push(el)
	}

	disconnect(): void {}

	fire(index: number): void {
		this.cb([{ isIntersecting: true, target: this.targets[index] } as unknown as IntersectionObserverEntry], this as unknown as IntersectionObserver)
	}
}

class FakeResizeObserver {
	observe(): void {}
	disconnect(): void {}
}

const flush = async (): Promise<void> => {
	for (let i = 0; i < 5; i++) {
		await Promise.resolve()
	}
}

/**
 * @param count
 */
function setup(count: number) {
	const host = document.createElement('div')
	document.body.append(host)
	const loadBlob = vi.fn(async (i: number) => new Blob([String(i)], { type: 'image/png' }))
	const onRelocate = vi.fn()
	const onTap = vi.fn()
	const view = new LongstripView(host, {
		pages: Array.from({ length: count }, (_, i) => ({ id: `p${i}.png` })),
		loadBlob,
		onRelocate,
		onTap,
	})
	const slots = [...view.root.querySelectorAll<HTMLElement>('[data-index]')]
	return { host, view, loadBlob, onRelocate, onTap, slots }
}

/**
 * jsdom has no layout: give every page a fixed position and height.
 *
 * @param slots
 * @param height
 */
function layout(slots: HTMLElement[], height: number): void {
	slots.forEach((el, i) => {
		Object.defineProperty(el, 'offsetTop', { configurable: true, get: () => i * height })
		Object.defineProperty(el, 'offsetHeight', { configurable: true, get: () => height })
	})
}

describe('LongstripView', () => {
	beforeEach(() => {
		vi.stubGlobal('IntersectionObserver', FakeIntersectionObserver)
		vi.stubGlobal('ResizeObserver', FakeResizeObserver)
		URL.createObjectURL = vi.fn(() => 'blob:x')
		URL.revokeObjectURL = vi.fn()
		HTMLImageElement.prototype.decode = vi.fn(async () => {})
	})

	afterEach(() => {
		vi.unstubAllGlobals()
		document.body.innerHTML = ''
	})

	it('stacks one placeholder per page without loading anything up front', () => {
		const { slots, loadBlob } = setup(4)
		expect(slots).toHaveLength(4)
		expect(slots[2].getAttribute('aria-label')).toBe('p2.png')
		expect(loadBlob).not.toHaveBeenCalled()
	})

	it('loads pages that come near the viewport, once', async () => {
		const { loadBlob, slots } = setup(3)
		FakeIntersectionObserver.last!.fire(1)
		FakeIntersectionObserver.last!.fire(1)
		await flush()
		expect(loadBlob).toHaveBeenCalledTimes(1)
		expect(loadBlob).toHaveBeenCalledWith(1)
		expect(slots[1].querySelector('img')).not.toBeNull()
	})

	it('reports page and position inside the page', () => {
		const { view, slots } = setup(5)
		layout(slots, 1000)
		view.root.scrollTop = 2500
		expect(view.position()).toEqual({ index: 2, progression: 0.5 })
		view.root.scrollTop = 0
		expect(view.position()).toEqual({ index: 0, progression: 0 })
	})

	it('goes to a page, loads it and reports the new position', async () => {
		const { view, slots, loadBlob, onRelocate } = setup(5)
		layout(slots, 1000)
		await view.goTo(3, 0.25)
		expect(loadBlob).toHaveBeenCalledWith(3)
		expect(view.root.scrollTop).toBe(3250)
		expect(onRelocate).toHaveBeenLastCalledWith(3, 0.25)
	})

	it('maps clicks to tap zones', () => {
		const { view, onTap } = setup(1)
		view.root.getBoundingClientRect = () => ({ left: 0, width: 1000, top: 0, height: 800, right: 1000, bottom: 800, x: 0, y: 0, toJSON: () => ({}) })
		view.root.dispatchEvent(new MouseEvent('click', { clientX: 100 }))
		view.root.dispatchEvent(new MouseEvent('click', { clientX: 500 }))
		view.root.dispatchEvent(new MouseEvent('click', { clientX: 900 }))
		expect(onTap.mock.calls.map((c) => c[0])).toEqual(['left', 'center', 'right'])
	})

	it('releases its images and element on destroy', async () => {
		const { view, host } = setup(2)
		FakeIntersectionObserver.last!.fire(0)
		await flush()
		view.destroy()
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:x')
		expect(host.children).toHaveLength(0)
	})
})
