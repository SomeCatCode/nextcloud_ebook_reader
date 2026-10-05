/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Types for scripts/l10n-lib.mjs (imported by src/l10n.test.ts).

export interface Extracted {
	key: string
	singular: string
	plural?: string
	file: string
	line: number
}

export interface ExtractResult {
	strings: Extracted[]
	warnings: { file: string, line: number, message: string }[]
}

export interface CatalogueEntry {
	id: string
	singular?: string
	plural?: string
	files: string[]
}

export interface LangReport {
	errors: string[]
	missing: string[]
	stale: string[]
}

export const APP_ID: string
export const PLURAL_FORMS: Record<string, string>
export function pluralKey(singular: string, plural: string): string
export function splitPluralKey(key: string): [string, string] | null
export function placeholders(text: string): string[]
export function extractJs(src: string, file: string): ExtractResult
export function extractPhp(src: string, file: string): ExtractResult
export function extractInfoXml(xml: string, file: string): ExtractResult
export function buildCatalogue(strings: Extracted[]): CatalogueEntry[]
export function checkLanguage(lang: string, data: unknown, catalogue: CatalogueEntry[]): LangReport
export function renderJs(translations: Record<string, string | string[]>, pluralForm: string): string
export function renderJson(translations: Record<string, string | string[]>, pluralForm: string): string
export function sortTranslations(translations: Record<string, string | string[]>): Record<string, string | string[]>
