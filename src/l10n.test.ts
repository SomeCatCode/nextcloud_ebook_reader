/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { CatalogueEntry } from '../scripts/l10n-lib.mjs'

import { describe, expect, it } from 'vitest'
import {
	checkLanguage,
	extractJs,
	extractPhp,
	placeholders,
	PLURAL_FORMS,
	pluralKey,
	renderJs,
	sortTranslations,
} from '../scripts/l10n-lib.mjs'
import source from '../translationfiles/source.json'

// Vite globs instead of node:fs (the Nextcloud Vite config replaces node built-ins with browser shims).
interface LangFile { translations: Record<string, string | string[]>, pluralForm: string }
const jsonFiles = import.meta.glob<LangFile>('../l10n/*.json', { eager: true, import: 'default' })
const jsFiles = import.meta.glob<string>('../l10n/*.js', { eager: true, query: '?raw', import: 'default' })
const langOf = (path: string) => path.replace(/^.*\/|\.[a-z]+$/g, '')
const files = Object.fromEntries(Object.entries(jsonFiles).map(([p, d]) => [langOf(p), d]))
const scripts = Object.fromEntries(Object.entries(jsFiles).map(([p, d]) => [langOf(p), d]))
const catalogue = source.strings as CatalogueEntry[]
const languages = Object.keys(files)

describe('l10n files', () => {
	it('ships the expected languages', () => {
		expect(languages.sort()).toEqual(['de', 'de_DE', 'es', 'ja'])
	})

	it.each(languages)('%s: valid structure, plural forms and placeholders', (lang) => {
		const data = files[lang]
		expect(data.pluralForm).toBe(PLURAL_FORMS[lang])
		const report = checkLanguage(lang, data, catalogue)
		expect(report.errors).toEqual([])
		expect(report.stale).toEqual([])
	})

	it.each(languages)('%s: l10n/%s.js matches the json file', (lang) => {
		const data = files[lang]
		const js = (scripts[lang] ?? '').replace(/\r\n/g, '\n')
		expect(js).toBe(renderJs(sortTranslations(data.translations), data.pluralForm))
	})
})

describe('l10n extraction', () => {
	it('extracts t(), n() and aliases from JS/Vue', () => {
		const src = `
			t('ebookreader', 'Hello {name}', { name })
			translate('ebookreader', "It's")
			nPlural('ebookreader', '%n book', '%n books', count)
			n('ebookreader', 'a' + 'b', 'cs', 2)
			t('other', 'Not ours')
			tr('Title changed')
			t('ebookreader', variable)
			t('ebookreader', variable) // l10n-ignore
		`
		const r = extractJs(src, 'x.vue')
		expect(r.strings.map((s) => s.key)).toEqual(['Hello {name}', 'It\'s', pluralKey('%n book', '%n books'), pluralKey('ab', 'cs'), 'Title changed'])
		expect(r.warnings).toHaveLength(1)
	})

	it('extracts $this->l10n->t() and ->n() from PHP', () => {
		const src = "<?php $this->l10n->t('Author: %s', [$a]); $l->n('%n book', '%n books', 3); $this->l10n->t('It\\'s');"
		expect(extractPhp(src, 'x.php').strings.map((s) => s.key)).toEqual(['Author: %s', pluralKey('%n book', '%n books'), 'It\'s'])
	})
})

describe('l10n checks', () => {
	const cat: CatalogueEntry[] = [
		{ id: 'Hello {name}', files: [] },
		{ id: pluralKey('%n book', '%n books'), singular: '%n book', plural: '%n books', files: [] },
	]

	it('finds placeholders', () => {
		expect(placeholders('{a} %s %1$s %n {b.c} 100 %')).toEqual(['%1$s', '%n', '%s', '{a}', '{b.c}'])
	})

	it('reports placeholder mismatches, wrong plural arrays and missing keys', () => {
		const r = checkLanguage('de', {
			translations: { 'Hello {name}': 'Hallo {nome}', [pluralKey('%n book', '%n books')]: ['Ein Buch'] },
			pluralForm: PLURAL_FORMS.de,
		}, cat)
		expect(r.errors).toHaveLength(2)
		expect(r.missing).toEqual([])
		expect(checkLanguage('de', { translations: {}, pluralForm: PLURAL_FORMS.de }, cat).missing).toHaveLength(2)
	})

	it('allows the singular form to drop %n', () => {
		const r = checkLanguage('de', {
			translations: { 'Hello {name}': 'Hallo {name}', [pluralKey('%n book', '%n books')]: ['Ein Buch', '%n Bücher'] },
			pluralForm: PLURAL_FORMS.de,
		}, cat)
		expect(r.errors).toEqual([])
	})
})
