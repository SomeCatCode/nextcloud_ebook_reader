/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Pure helpers for scripts/l10n.mjs and src/l10n.test.ts: string extraction from JS/TS/Vue and
 * PHP sources, placeholder handling and validation of l10n/<lang>.json files.
 *
 * Key format follows Nextcloud: t() keys are the English text, n() keys are
 * "_<singular>_::_<plural>_" with an array of plural forms as value.
 */

export const APP_ID = 'ebookreader'

/** Expected plural rule per language; files with other rules are reported. */
export const PLURAL_FORMS = {
	de: 'nplurals=2; plural=(n != 1);',
	de_DE: 'nplurals=2; plural=(n != 1);',
	es: 'nplurals=2; plural=(n != 1);',
	ja: 'nplurals=1; plural=0;',
}

/**
 * Nextcloud identifier of a plural string.
 *
 * @param {string} singular
 * @param {string} plural
 * @return {string}
 */
export function pluralKey(singular, plural) {
	return `_${singular}_::_${plural}_`
}

/**
 * Splits a plural identifier into singular and plural, or returns null for a plain key.
 *
 * @param {string} key
 * @return {[string, string] | null}
 */
export function splitPluralKey(key) {
	const m = /^_([\s\S]*)_::_([\s\S]*)_$/.exec(key)
	return m ? [m[1], m[2]] : null
}

/**
 * Placeholders that must survive translation: {name} (JS), %s / %1$s / %d (PHP) and %n (plural count).
 *
 * @param {string} text
 * @return {string[]} sorted list (duplicates kept)
 */
export function placeholders(text) {
	return (text.match(/\{[A-Za-z0-9_.]+\}|%(?:\d+\$)?[sdn]/g) ?? []).sort()
}

/**
 * @param {string} src
 * @param {number} index
 * @return {number}
 */
function lineAt(src, index) {
	let line = 1
	for (let i = 0; i < index; i++) {
		if (src.charCodeAt(i) === 10) {
			line++
		}
	}
	return line
}

/**
 * @param {string} src
 * @param {number} i
 * @return {number}
 */
function skipSpace(src, i) {
	while (i < src.length) {
		if (/\s/.test(src[i])) {
			i++
		} else if (src.startsWith('//', i)) {
			const nl = src.indexOf('\n', i)
			i = nl === -1 ? src.length : nl + 1
		} else if (src.startsWith('/*', i)) {
			const end = src.indexOf('*/', i + 2)
			i = end === -1 ? src.length : end + 2
		} else {
			break
		}
	}
	return i
}

const JS_ESCAPES = { n: '\n', t: '\t', r: '\r', b: '\b', f: '\f', v: '\v', 0: '\0' }

/**
 * Parses one JS string literal ('…', "…" or `…` without ${}) at src[i].
 *
 * @param {string} src
 * @param {number} i
 * @return {{ value: string, end: number } | null}
 */
function parseJsLiteral(src, i) {
	const q = src[i]
	if (q !== '\'' && q !== '"' && q !== '`') {
		return null
	}
	let value = ''
	for (let j = i + 1; j < src.length; j++) {
		const c = src[j]
		if (c === q) {
			return { value, end: j + 1 }
		}
		if (q === '`' && c === '$' && src[j + 1] === '{') {
			return null
		}
		if ((c === '\n' || c === '\r') && q !== '`') {
			return null
		}
		if (c === '\\') {
			const e = src[++j]
			if (e === 'u') {
				if (src[j + 1] === '{') {
					const close = src.indexOf('}', j)
					value += String.fromCodePoint(parseInt(src.slice(j + 2, close), 16))
					j = close
				} else {
					value += String.fromCharCode(parseInt(src.slice(j + 1, j + 5), 16))
					j += 4
				}
			} else if (e === 'x') {
				value += String.fromCharCode(parseInt(src.slice(j + 1, j + 3), 16))
				j += 2
			} else if (e === '\r' || e === '\n') {
				if (e === '\r' && src[j + 1] === '\n') {
					j++
				}
			} else {
				value += JS_ESCAPES[e] ?? e
			}
			continue
		}
		value += c
	}
	return null
}

/**
 * Parses one PHP string literal ('…' or "…" without interpolation) at src[i].
 *
 * @param {string} src
 * @param {number} i
 * @return {{ value: string, end: number } | null}
 */
function parsePhpLiteral(src, i) {
	const q = src[i]
	if (q !== '\'' && q !== '"') {
		return null
	}
	let value = ''
	for (let j = i + 1; j < src.length; j++) {
		const c = src[j]
		if (c === q) {
			return { value, end: j + 1 }
		}
		if (q === '"' && c === '$') {
			return null
		}
		if (c === '\\') {
			const e = src[j + 1]
			if (q === '\'') {
				if (e === '\'' || e === '\\') {
					value += e
					j++
				} else {
					value += c
				}
			} else {
				const map = { n: '\n', t: '\t', r: '\r', v: '\v', f: '\f', e: '\x1b', 0: '\0', '\\': '\\', '"': '"', $: '$' }
				if (e in map) {
					value += map[e]
					j++
				} else {
					value += c
				}
			}
			continue
		}
		value += c
	}
	return null
}

/**
 * Parses literal ("+" literal)* starting at src[i].
 *
 * @param {string} src
 * @param {number} i
 * @param {typeof parseJsLiteral} parseLiteral
 * @param {string} concat
 * @return {{ value: string, end: number } | null}
 */
function parseStringExpr(src, i, parseLiteral, concat) {
	let lit = parseLiteral(src, skipSpace(src, i))
	if (!lit) {
		return null
	}
	let value = lit.value
	let end = lit.end
	for (;;) {
		const k = skipSpace(src, end)
		if (src[k] !== concat) {
			break
		}
		lit = parseLiteral(src, skipSpace(src, k + 1))
		if (!lit) {
			return null
		}
		value += lit.value
		end = lit.end
	}
	return { value, end }
}

/**
 * @typedef {object} Extracted
 * @property {string} key Nextcloud identifier
 * @property {string} singular
 * @property {string} [plural]
 * @property {string} file
 * @property {number} line
 */

/**
 * @typedef {object} ExtractResult
 * @property {Extracted[]} strings
 * @property {{ file: string, line: number, message: string }[]} warnings
 */

/**
 * Extracts t('ebookreader', …), n('ebookreader', …) (also translate / translatePlural / aliased
 * nPlural) and tr('…') (the translator injected into src/editor/useEditorState.ts) from JS/TS/Vue.
 * Lines containing the marker `l10n-ignore` are skipped (for wrappers that pass a variable on).
 *
 * @param {string} src
 * @param {string} file
 * @return {ExtractResult}
 */
export function extractJs(src, file) {
	const strings = []
	const warnings = []
	const re = /(?<![\w$.])(t|n|translate|translatePlural|nPlural|tr)\s*\(/g
	let m
	while ((m = re.exec(src)) !== null) {
		const fn = m[1]
		const line = lineAt(src, m.index)
		const lineStart = src.lastIndexOf('\n', m.index) + 1
		const lineEnd = src.indexOf('\n', m.index)
		if (src.slice(lineStart, lineEnd === -1 ? undefined : lineEnd).includes('l10n-ignore')) {
			continue
		}
		let i = m.index + m[0].length
		if (fn !== 'tr') {
			const app = parseJsLiteral(src, skipSpace(src, i))
			if (!app || app.value !== APP_ID) {
				continue
			}
			i = skipSpace(src, app.end)
			if (src[i] !== ',') {
				continue
			}
			i++
		}
		const first = parseStringExpr(src, i, parseJsLiteral, '+')
		if (!first) {
			if (fn !== 'tr') {
				warnings.push({ file, line, message: `${fn}() with a non-literal text can not be extracted` })
			}
			continue
		}
		if (fn === 'n' || fn === 'translatePlural' || fn === 'nPlural') {
			const k = skipSpace(src, first.end)
			const second = src[k] === ',' ? parseStringExpr(src, k + 1, parseJsLiteral, '+') : null
			if (!second) {
				warnings.push({ file, line, message: `${fn}() with a non-literal plural text can not be extracted` })
				continue
			}
			strings.push({ key: pluralKey(first.value, second.value), singular: first.value, plural: second.value, file, line })
		} else {
			strings.push({ key: first.value, singular: first.value, file, line })
		}
	}
	return { strings, warnings }
}

/**
 * Extracts $this->l10n->t('…') / $l->n('…', '…', …) style calls from PHP.
 *
 * @param {string} src
 * @param {string} file
 * @return {ExtractResult}
 */
export function extractPhp(src, file) {
	const strings = []
	const warnings = []
	const re = /\$(?:this->)?(?:l10n|l|il10n|trans)->(t|n)\s*\(/gi
	let m
	while ((m = re.exec(src)) !== null) {
		const line = lineAt(src, m.index)
		const first = parseStringExpr(src, m.index + m[0].length, parsePhpLiteral, '.')
		if (!first) {
			warnings.push({ file, line, message: `->${m[1]}() with a non-literal text can not be extracted` })
			continue
		}
		if (m[1] === 'n') {
			const k = skipSpace(src, first.end)
			const second = src[k] === ',' ? parseStringExpr(src, k + 1, parsePhpLiteral, '.') : null
			if (!second) {
				warnings.push({ file, line, message: '->n() with a non-literal plural text can not be extracted' })
				continue
			}
			strings.push({ key: pluralKey(first.value, second.value), singular: first.value, plural: second.value, file, line })
		} else {
			strings.push({ key: first.value, singular: first.value, file, line })
		}
	}
	return { strings, warnings }
}

/**
 * Navigation entry names in appinfo/info.xml; Nextcloud translates them with the app's l10n.
 *
 * @param {string} xml
 * @param {string} file
 * @return {ExtractResult}
 */
export function extractInfoXml(xml, file) {
	const strings = []
	const re = /<navigation>[\s\S]*?<name>([^<]+)<\/name>/g
	let m
	while ((m = re.exec(xml)) !== null) {
		strings.push({ key: m[1].trim(), singular: m[1].trim(), file, line: lineAt(xml, m.index) })
	}
	return { strings, warnings: [] }
}

/**
 * @typedef {object} CatalogueEntry
 * @property {string} id
 * @property {string} [singular]
 * @property {string} [plural]
 * @property {string[]} files
 */

/**
 * Merges extracted strings into a sorted catalogue (one entry per key).
 *
 * @param {Extracted[]} strings
 * @return {CatalogueEntry[]}
 */
export function buildCatalogue(strings) {
	const map = new Map()
	for (const s of strings) {
		let e = map.get(s.key)
		if (!e) {
			e = s.plural === undefined ? { id: s.key, files: [] } : { id: s.key, singular: s.singular, plural: s.plural, files: [] }
			map.set(s.key, e)
		}
		if (!e.files.includes(s.file)) {
			e.files.push(s.file)
		}
	}
	const out = [...map.values()]
	for (const e of out) {
		e.files.sort()
	}
	return out.sort((a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0))
}

/**
 * @typedef {object} LangReport
 * @property {string[]} errors malformed entries, placeholder mismatches, wrong plural arrays
 * @property {string[]} missing source keys without (non-empty) translation
 * @property {string[]} stale translated keys no longer in the source
 */

/**
 * Validates one parsed l10n/<lang>.json against the source catalogue.
 *
 * @param {string} lang
 * @param {unknown} data parsed JSON
 * @param {CatalogueEntry[]} catalogue
 * @return {LangReport}
 */
export function checkLanguage(lang, data, catalogue) {
	const errors = []
	const missing = []
	const stale = []
	if (typeof data !== 'object' || data === null || typeof data.translations !== 'object' || data.translations === null || Array.isArray(data.translations)) {
		return { errors: ['expected { "translations": {…}, "pluralForm": "…" }'], missing: catalogue.map((e) => e.id), stale }
	}
	const pluralForm = data.pluralForm
	const np = typeof pluralForm === 'string' ? /nplurals\s*=\s*(\d+)/.exec(pluralForm) : null
	if (!np) {
		errors.push(`pluralForm missing or invalid: ${JSON.stringify(pluralForm)}`)
	} else if (PLURAL_FORMS[lang] && PLURAL_FORMS[lang] !== pluralForm) {
		errors.push(`pluralForm is ${JSON.stringify(pluralForm)}, expected ${JSON.stringify(PLURAL_FORMS[lang])}`)
	}
	const nplurals = np ? Number(np[1]) : 0
	const tr = data.translations
	const byId = new Map(catalogue.map((e) => [e.id, e]))
	const same = (a, b) => a.length === b.length && a.every((x, i) => x === b[i])

	for (const [key, value] of Object.entries(tr)) {
		const entry = byId.get(key)
		if (!entry) {
			stale.push(key)
		}
		const parts = splitPluralKey(key)
		const singular = entry?.singular ?? (parts ? parts[0] : key)
		const plural = entry?.plural ?? (parts ? parts[1] : undefined)
		if (plural !== undefined) {
			if (!Array.isArray(value) || value.some((v) => typeof v !== 'string')) {
				errors.push(`${JSON.stringify(key)}: plural entry must be an array of strings`)
				continue
			}
			if (nplurals && value.length !== nplurals) {
				errors.push(`${JSON.stringify(key)}: ${value.length} plural forms, expected ${nplurals}`)
			}
			value.forEach((v, i) => {
				// Form 0 of a two-form language is the singular; every other form follows the plural text.
				const src = nplurals > 1 && i === 0 ? singular : plural
				// The singular form may drop %n ("one book"), but no other placeholder.
				const drop = (list) => (nplurals > 1 && i === 0 ? list.filter((p) => p !== '%n') : list)
				const want = placeholders(src)
				const got = placeholders(v)
				if (!same(drop(got), drop(want))) {
					errors.push(`${JSON.stringify(key)} [${i}]: placeholders ${JSON.stringify(got)} do not match source ${JSON.stringify(want)}`)
				}
			})
		} else {
			if (typeof value !== 'string') {
				errors.push(`${JSON.stringify(key)}: value must be a string`)
				continue
			}
			const want = placeholders(key)
			const got = placeholders(value)
			if (!same(want, got)) {
				errors.push(`${JSON.stringify(key)}: placeholders ${JSON.stringify(got)} do not match source ${JSON.stringify(want)}`)
			}
		}
	}
	for (const e of catalogue) {
		const v = tr[e.id]
		if (v === undefined || v === '' || (Array.isArray(v) && v.some((x) => x === ''))) {
			missing.push(e.id)
		}
	}
	return { errors, missing, stale }
}

/**
 * Content of l10n/<lang>.js for the given translations (the format Nextcloud's own tooling writes).
 *
 * @param {Record<string, string | string[]>} translations
 * @param {string} pluralForm
 * @return {string}
 */
export function renderJs(translations, pluralForm) {
	const body = Object.entries(translations)
		.map(([k, v]) => `    ${JSON.stringify(k)} : ${JSON.stringify(v)}`)
		.join(',\n')
	return `OC.L10N.register(\n    ${JSON.stringify(APP_ID)},\n    {\n${body}\n},\n${JSON.stringify(pluralForm)});\n`
}

/**
 * Content of l10n/<lang>.json, keys sorted like the source catalogue.
 *
 * @param {Record<string, string | string[]>} translations
 * @param {string} pluralForm
 * @return {string}
 */
export function renderJson(translations, pluralForm) {
	return JSON.stringify({ translations, pluralForm }, null, '\t') + '\n'
}

/**
 * Sorts translations by key (code unit order, same as the catalogue).
 *
 * @param {Record<string, string | string[]>} translations
 * @return {Record<string, string | string[]>}
 */
export function sortTranslations(translations) {
	return Object.fromEntries(Object.entries(translations).sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0)))
}
