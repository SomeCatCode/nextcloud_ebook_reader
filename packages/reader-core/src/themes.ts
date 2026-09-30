/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { ReaderThemeName, ReaderTypography } from './types.ts'

export interface Palette {
	bg: string
	fg: string
	link: string
}

export const PALETTES: Record<Exclude<ReaderThemeName, 'auto'>, Palette> = {
	light: { bg: '#ffffff', fg: '#1a1a1a', link: '#0b5cad' },
	sepia: { bg: '#f4ecd8', fg: '#5b4636', link: '#8a5a2b' },
	dark: { bg: '#1c1c1e', fg: '#d8d8d8', link: '#7db4ff' },
}

/**
 * @param theme
 * @param prefersDark
 */
export function resolveTheme(theme: ReaderThemeName, prefersDark: boolean): Exclude<ReaderThemeName, 'auto'> {
	if (theme === 'auto') {
		return prefersDark ? 'dark' : 'light'
	}
	return theme
}

/**
 * Font family names go into CSS: restrict to a safe character set.
 *
 * @param name
 */
function safeFont(name: string): string {
	return name.replace(/[^\w\s,'"-]/g, '')
}

/**
 * CSS injected into every section document through renderer.setStyles().
 *
 * @param theme
 * @param prefersDark
 * @param typo
 */
export function buildCss(theme: ReaderThemeName, prefersDark: boolean, typo: ReaderTypography = {}): string {
	const resolved = resolveTheme(theme, prefersDark)
	const p = PALETTES[resolved]
	const fontSize = typo.fontSize ? `html { font-size: ${Math.round(typo.fontSize)}% !important; }` : ''
	const family = typo.fontFamily && typo.fontFamily !== 'default'
		? `html, body, p, li, blockquote, dd, div, span { font-family: ${safeFont(typo.fontFamily)} !important; }`
		: ''
	const lh = typo.lineHeight
		? `p, li, blockquote, dd, div { line-height: ${Number(typo.lineHeight)} !important; }`
		: ''
	return `
html { color-scheme: ${resolved === 'dark' ? 'dark' : 'light'}; }
html, body { background: ${p.bg} !important; color: ${p.fg} !important; }
a:link, a:visited { color: ${p.link} !important; }
img, svg { max-width: 100%; }
${fontSize}
${family}
${lh}
`
}
