/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
export { createReader } from './src/reader.ts'
export { ReaderError } from './src/open-book.ts'
export type { ReaderErrorCode } from './src/open-book.ts'
export { planNavigation, toLocator } from './src/locator.ts'
export { ANNOTATION_COLORS, ANNOTATION_COLOR_NAMES, cfiOnPage, collapseCfi, colorValue, compareCfi, compareLocators, isAnnotationColor, locatorOnPage } from './src/annotations.ts'
export { PALETTES, buildCss, resolveTheme } from './src/themes.ts'
export { SECTION_CSP, injectCsp } from './src/secure-sections.ts'
export type * from './src/types.ts'
