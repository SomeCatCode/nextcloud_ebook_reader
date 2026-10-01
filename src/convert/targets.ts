/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { ConvertFormat, ConvertTarget } from './types.ts'

import { RECOMMENDED_FORMAT } from './formats.ts'

/**
 * Targets the user can pick (server or browser conversion).
 *
 * @param targets
 */
export function selectableTargets(targets: ConvertTarget[]): ConvertTarget[] {
	return targets.filter((t) => t.mode !== 'unavailable')
}

/**
 * The preselected target: CBZ when possible (best compatibility), otherwise the first one that works.
 *
 * @param targets
 */
export function defaultTarget(targets: ConvertTarget[]): ConvertFormat | null {
	const usable = selectableTargets(targets)
	return usable.find((t) => t.format === RECOMMENDED_FORMAT)?.format ?? usable[0]?.format ?? null
}

/**
 * Whether a target is the recommendation for this source: always CBZ, which matters most for CBR.
 *
 * @param format
 * @param source
 */
export function isRecommended(format: ConvertFormat, source: string): boolean {
	return format === RECOMMENDED_FORMAT && source !== RECOMMENDED_FORMAT
}

/**
 * User-relative path of the converted file: same folder and name, new extension.
 *
 * @param path
 * @param target
 */
export function targetPath(path: string, target: ConvertFormat): string {
	const slash = path.lastIndexOf('/')
	const dir = slash >= 0 ? path.slice(0, slash + 1) : ''
	const name = path.slice(slash + 1)
	const dot = name.lastIndexOf('.')
	const base = dot > 0 ? name.slice(0, dot) : name
	return `${dir}${base}.${target}`
}

/**
 * Whether the browser can do a conversion on its own: it reads every comic format (libarchive.js)
 * and writes everything but RAR.
 *
 * @param source
 * @param target
 */
export function browserCanConvert(source: string, target: string): boolean {
	return ['cbz', 'cbr', 'cb7', 'cbt'].includes(source) && ['cbz', 'cb7', 'cbt', 'epub'].includes(target) && source !== target
}
