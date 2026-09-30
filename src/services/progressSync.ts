/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Locator, Progress } from '../types.ts'

import { ConflictError, getProgress, putProgress, putProgressKeepalive } from './api.ts'

const DEBOUNCE_MS = 2000

/**
 * Short, human readable device name (max 64 chars) for "newer position from <device>".
 */
export function deviceName(): string {
	const nav = navigator as Navigator & { userAgentData?: { platform?: string, brands?: { brand: string }[] } }
	const ua = navigator.userAgent
	let browser = 'Browser'
	if (/Edg\//.test(ua)) {
		browser = 'Edge'
	} else if (/Firefox\//.test(ua)) {
		browser = 'Firefox'
	} else if (/Chrome\//.test(ua)) {
		browser = 'Chrome'
	} else if (/Safari\//.test(ua)) {
		browser = 'Safari'
	}
	let platform = nav.userAgentData?.platform
	if (!platform) {
		platform = /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS' : /Windows/.test(ua) ? 'Windows' : /Mac/.test(ua) ? 'macOS' : /Linux/.test(ua) ? 'Linux' : ''
	}
	return `${browser}${platform ? ' (' + platform + ')' : ''}`.slice(0, 64)
}

export interface ProgressSyncOptions {
	/** The server has a newer position than the one we tried to write. */
	onConflict: (current: Progress) => void
	onError?: (e: unknown) => void
	device?: string
}

export interface ProgressSync {
	/** Loads the stored server progress (null if none). */
	loadRemote(): Promise<Progress | null>
	/** Reports a new position; written after 2 s of quiet. */
	update(locator: Locator, percentage: number): void
	/** Write pending position now. `keepalive` uses fetch keepalive (page unload). */
	flush(keepalive?: boolean): Promise<void>
	/** After a conflict the user chose to keep the local position: overwrite the server. */
	forceLocal(): Promise<void>
	/** After a conflict the user chose to jump: drop pending local state. */
	discardPending(): void
	destroy(): void
}

/**
 * @param fileId
 * @param options
 */
export function createProgressSync(fileId: number, options: ProgressSyncOptions): ProgressSync {
	const device = options.device ?? deviceName()
	let pending: { locator: Locator, percentage: number, clientUpdatedAt: number } | null = null
	let timer: ReturnType<typeof setTimeout> | null = null
	let conflicted = false

	const clearTimer = (): void => {
		if (timer) {
			clearTimeout(timer)
			timer = null
		}
	}

	const send = async (): Promise<void> => {
		clearTimer()
		if (!pending || conflicted) {
			return
		}
		const body = { ...pending, device }
		try {
			await putProgress(fileId, body)
			if (pending?.clientUpdatedAt === body.clientUpdatedAt) {
				pending = null
			}
		} catch (e) {
			if (e instanceof ConflictError) {
				conflicted = true
				options.onConflict(e.current as Progress)
			} else {
				options.onError?.(e)
			}
		}
	}

	const self: { handle?: ProgressSync } = {}
	const onHidden = (): void => {
		if (document.visibilityState === 'hidden') {
			void self.handle?.flush(true)
		}
	}
	const onPageHide = (): void => {
		void self.handle?.flush(true)
	}
	document.addEventListener('visibilitychange', onHidden)
	window.addEventListener('pagehide', onPageHide)

	const handle: ProgressSync = {
		loadRemote: () => getProgress(fileId),
		update(locator, percentage) {
			pending = { locator, percentage, clientUpdatedAt: Date.now() }
			if (conflicted) {
				return
			}
			clearTimer()
			timer = setTimeout(() => void send(), DEBOUNCE_MS)
		},
		async flush(keepalive = false) {
			if (!pending || conflicted) {
				return
			}
			if (keepalive) {
				clearTimer()
				putProgressKeepalive(fileId, { ...pending, device })
				return
			}
			await send()
		},
		async forceLocal() {
			conflicted = false
			if (pending) {
				pending.clientUpdatedAt = Date.now()
				await send()
			}
		},
		discardPending() {
			clearTimer()
			pending = null
			conflicted = false
		},
		destroy() {
			void handle.flush(true)
			clearTimer()
			document.removeEventListener('visibilitychange', onHidden)
			window.removeEventListener('pagehide', onPageHide)
		},
	}
	self.handle = handle
	return handle
}
