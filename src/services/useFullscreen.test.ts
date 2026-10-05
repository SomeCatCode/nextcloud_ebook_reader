/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'
import { isFullscreenSupported, isTypingTarget, useFullscreen } from './useFullscreen.ts'

type Kind = 'standard' | 'webkit' | 'none'

/**
 * A fake document with the standard or the webkit-prefixed Fullscreen API.
 *
 * @param kind
 * @param enabled
 */
function fakeDocument(kind: Kind, enabled = true) {
	const target = new EventTarget()
	const root = {} as Record<string, unknown>
	const doc = {
		documentElement: root as unknown as Element,
		addEventListener: vi.fn((name: string, fn: EventListener) => target.addEventListener(name, fn)),
		removeEventListener: vi.fn((name: string, fn: EventListener) => target.removeEventListener(name, fn)),
	} as Record<string, unknown>
	const fire = (name: string): void => {
		target.dispatchEvent(new Event(name))
	}
	if (kind === 'standard') {
		doc.fullscreenEnabled = enabled
		doc.fullscreenElement = null
		root.requestFullscreen = vi.fn(async () => {
			doc.fullscreenElement = root
			fire('fullscreenchange')
		})
		doc.exitFullscreen = vi.fn(async () => {
			doc.fullscreenElement = null
			fire('fullscreenchange')
		})
	} else if (kind === 'webkit') {
		doc.webkitFullscreenEnabled = enabled
		doc.webkitFullscreenElement = null
		root.webkitRequestFullscreen = vi.fn(() => {
			doc.webkitFullscreenElement = root
			fire('webkitfullscreenchange')
		})
		doc.webkitExitFullscreen = vi.fn(() => {
			doc.webkitFullscreenElement = null
			fire('webkitfullscreenchange')
		})
	}
	return { doc: doc as any, root, fire }
}

describe('isFullscreenSupported', () => {
	it('detects the standard and the webkit API', () => {
		expect(isFullscreenSupported(fakeDocument('standard').doc)).toBe(true)
		expect(isFullscreenSupported(fakeDocument('webkit').doc)).toBe(true)
	})

	it('is false without API or when disabled (iPhone Safari, iframe without allow="fullscreen")', () => {
		expect(isFullscreenSupported(fakeDocument('none').doc)).toBe(false)
		expect(isFullscreenSupported(fakeDocument('standard', false).doc)).toBe(false)
		expect(isFullscreenSupported(fakeDocument('webkit', false).doc)).toBe(false)
	})
})

describe('useFullscreen', () => {
	it('toggles the whole page with the standard API', async () => {
		const { doc, root } = fakeDocument('standard')
		const fs = useFullscreen(doc)
		expect(fs.supported).toBe(true)
		await fs.toggle()
		expect(root.requestFullscreen).toHaveBeenCalledOnce()
		expect(fs.active.value).toBe(true)
		await fs.toggle()
		expect(doc.exitFullscreen).toHaveBeenCalledOnce()
		expect(fs.active.value).toBe(false)
	})

	it('falls back to the webkit-prefixed API', async () => {
		const { doc, root } = fakeDocument('webkit')
		const fs = useFullscreen(doc)
		await fs.enter()
		expect(root.webkitRequestFullscreen).toHaveBeenCalledOnce()
		expect(fs.active.value).toBe(true)
		await fs.exit()
		expect(doc.webkitExitFullscreen).toHaveBeenCalledOnce()
		expect(fs.active.value).toBe(false)
	})

	it('follows the browser when the user leaves with Esc', async () => {
		const { doc, fire } = fakeDocument('standard')
		const fs = useFullscreen(doc)
		await fs.enter()
		doc.fullscreenElement = null
		fire('fullscreenchange')
		expect(fs.active.value).toBe(false)
	})

	it('does nothing when unsupported', async () => {
		const { doc } = fakeDocument('standard', false)
		const fs = useFullscreen(doc)
		await fs.toggle()
		expect(fs.active.value).toBe(false)
		expect(doc.addEventListener).not.toHaveBeenCalled()
	})

	it('stays inactive when the browser refuses', async () => {
		const { doc, root } = fakeDocument('standard')
		root.requestFullscreen = vi.fn(async () => {
			throw new TypeError('Permissions check failed')
		})
		const fs = useFullscreen(doc)
		await expect(fs.enter()).resolves.toBeUndefined()
		expect(fs.active.value).toBe(false)
	})

	it('removes its listeners and leaves fullscreen when the component scope ends', async () => {
		const { doc, fire } = fakeDocument('standard')
		const scope = effectScope()
		const fs = scope.run(() => useFullscreen(doc))!
		await fs.enter()
		scope.stop()
		await new Promise((resolve) => setTimeout(resolve))
		expect(fs.active.value).toBe(false)
		expect(doc.exitFullscreen).toHaveBeenCalledOnce()
		expect(doc.removeEventListener).toHaveBeenCalledWith('fullscreenchange', expect.any(Function))
		expect(doc.removeEventListener).toHaveBeenCalledWith('webkitfullscreenchange', expect.any(Function))
		// no longer tracked
		doc.fullscreenElement = {}
		fire('fullscreenchange')
		expect(fs.active.value).toBe(false)
	})

	it('does not call exit on dispose when not fullscreen', () => {
		const { doc } = fakeDocument('standard')
		const fs = useFullscreen(doc)
		fs.dispose()
		fs.dispose()
		expect(doc.exitFullscreen).not.toHaveBeenCalled()
		expect(doc.removeEventListener).toHaveBeenCalledTimes(2)
	})
})

describe('isTypingTarget', () => {
	/**
	 * @param html
	 * @param selector
	 */
	function el(html: string, selector: string): HTMLElement {
		document.body.innerHTML = html
		return document.querySelector(selector) as HTMLElement
	}

	it('recognises text fields, contenteditable and dialogs', () => {
		expect(isTypingTarget(el('<input>', 'input'))).toBe(true)
		expect(isTypingTarget(el('<textarea></textarea>', 'textarea'))).toBe(true)
		expect(isTypingTarget(el('<div contenteditable="true"><b>x</b></div>', 'b'))).toBe(true)
		expect(isTypingTarget(el('<div role="dialog"><button>OK</button></div>', 'button'))).toBe(true)
	})

	it('lets keys on the page and toolbar buttons through', () => {
		expect(isTypingTarget(el('<header><button>x</button></header>', 'button'))).toBe(false)
		expect(isTypingTarget(el('<div contenteditable="false"><b>x</b></div>', 'b'))).toBe(false)
		expect(isTypingTarget(document.body)).toBe(false)
		expect(isTypingTarget(null)).toBe(false)
		expect(isTypingTarget(window)).toBe(false)
	})
})
