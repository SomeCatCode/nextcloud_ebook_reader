/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Ref } from 'vue'

import { getCurrentScope, onScopeDispose, ref } from 'vue'

/** The standard Fullscreen API plus the webkit-prefixed variant (Safari, iPadOS). */
interface FullscreenDocument {
	fullscreenEnabled?: boolean
	fullscreenElement?: Element | null
	exitFullscreen?: () => Promise<void>
	webkitFullscreenEnabled?: boolean
	webkitFullscreenElement?: Element | null
	webkitExitFullscreen?: () => Promise<void> | void
	documentElement: Element
	addEventListener: Document['addEventListener']
	removeEventListener: Document['removeEventListener']
}

interface FullscreenElement {
	requestFullscreen?: (options?: FullscreenOptions) => Promise<void>
	webkitRequestFullscreen?: () => Promise<void> | void
}

export interface FullscreenState {
	/** false when the browser can not do it (e.g. iPhone Safari) or it is blocked (iframe without allow="fullscreen") */
	supported: boolean
	/** true while our document is in fullscreen; follows the browser (Esc, F11 exit, …) */
	active: Ref<boolean>
	enter: () => Promise<void>
	exit: () => Promise<void>
	toggle: () => Promise<void>
	/** Removes the listeners and leaves fullscreen. Runs automatically when the owning scope (component) is disposed. */
	dispose: () => void
}

const CHANGE_EVENTS = ['fullscreenchange', 'webkitfullscreenchange'] as const

/**
 * @param doc
 */
export function isFullscreenSupported(doc: FullscreenDocument): boolean {
	const el = doc.documentElement as FullscreenElement
	if (doc.fullscreenEnabled !== undefined) {
		return doc.fullscreenEnabled === true && typeof el.requestFullscreen === 'function'
	}
	return doc.webkitFullscreenEnabled === true && typeof el.webkitRequestFullscreen === 'function'
}

/**
 * @param doc
 */
function currentElement(doc: FullscreenDocument): Element | null {
	return doc.fullscreenElement ?? doc.webkitFullscreenElement ?? null
}

/**
 * Fullscreen for the whole page (`document.documentElement`), not only the reader:
 * dialogs and popovers of `@nextcloud/vue` are teleported to `<body>` and would be invisible
 * if a smaller element were fullscreen.
 *
 * @param doc injectable for tests
 */
export function useFullscreen(doc: FullscreenDocument = document): FullscreenState {
	const supported = isFullscreenSupported(doc)
	const active = ref(!!currentElement(doc))

	const onChange = (): void => {
		active.value = !!currentElement(doc)
	}
	if (supported) {
		CHANGE_EVENTS.forEach((name) => doc.addEventListener(name, onChange))
	}

	const enter = async (): Promise<void> => {
		if (!supported || currentElement(doc)) {
			return
		}
		const el = doc.documentElement as FullscreenElement
		try {
			if (el.requestFullscreen) {
				await el.requestFullscreen({ navigationUI: 'hide' })
			} else {
				await el.webkitRequestFullscreen?.()
			}
		} catch {
			// refused (no user gesture, permissions policy): stay as we are
		}
		onChange()
	}

	const exit = async (): Promise<void> => {
		if (!currentElement(doc)) {
			return
		}
		try {
			if (doc.exitFullscreen) {
				await doc.exitFullscreen()
			} else {
				await doc.webkitExitFullscreen?.()
			}
		} catch {
			// already left (e.g. the user pressed Esc meanwhile)
		}
		onChange()
	}

	let disposed = false
	const dispose = (): void => {
		if (disposed) {
			return
		}
		disposed = true
		CHANGE_EVENTS.forEach((name) => doc.removeEventListener(name, onChange))
		void exit()
	}
	if (getCurrentScope()) {
		onScopeDispose(dispose)
	}

	return {
		supported,
		active,
		enter,
		exit,
		toggle: () => active.value ? exit() : enter(),
		dispose,
	}
}

/**
 * Whether a key press belongs to a text field or a dialog, where shortcuts must not fire.
 *
 * @param target
 */
export function isTypingTarget(target: EventTarget | null | undefined): boolean {
	const el = target as HTMLElement | null | undefined
	if (!el || typeof el.closest !== 'function') {
		return false
	}
	return el.isContentEditable === true
		|| !!el.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="dialog"], [role="alertdialog"]')
}
