/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Re-vendors foliate-js into packages/reader-core/vendor/foliate-js/ and re-applies the
 * EBOOKREADER PATCHes (see VENDORED.md).
 *
 * Usage: node scripts/update-foliate.mjs [--ref <commit|branch>] [--src <existing checkout>]
 */
import { execFileSync } from 'node:child_process'
import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'

const REPO = 'https://github.com/johnfactotum/foliate-js'
const root = resolve(import.meta.dirname, '..')
const dest = join(root, 'packages', 'reader-core', 'vendor', 'foliate-js')

const args = process.argv.slice(2)
const opt = (name) => {
	const i = args.indexOf(name)
	return i >= 0 ? args[i + 1] : undefined
}
const ref = opt('--ref')
let src = opt('--src')
let tmp = null
if (!src) {
	tmp = mkdtempSync(join(tmpdir(), 'foliate-'))
	src = join(tmp, 'foliate-js')
	execFileSync('git', ['clone', ...(ref ? [] : ['--depth', '1']), REPO, src], { stdio: 'inherit' })
	if (ref) {
		execFileSync('git', ['-C', src, 'checkout', ref], { stdio: 'inherit' })
	}
}
const sha = execFileSync('git', ['-C', src, 'rev-parse', 'HEAD']).toString().trim()

const files = [
	'view.js', 'paginator.js', 'fixed-layout.js', 'epub.js', 'epubcfi.js', 'mobi.js', 'fb2.js',
	'comic-book.js', 'progress.js', 'overlayer.js', 'search.js', 'text-walker.js', 'tts.js',
	'vendor/zip.js', 'vendor/fflate.js', 'LICENSE',
]
rmSync(dest, { recursive: true, force: true })
mkdirSync(join(dest, 'vendor'), { recursive: true })
for (const f of files) {
	cpSync(join(src, f), join(dest, f))
}

/**
 * @param {string} file relative to dest
 * @param {RegExp|string} search
 * @param {string} replacement
 */
function patch(file, search, replacement) {
	const p = join(dest, file)
	const before = readFileSync(p, 'utf8')
	const after = before.replace(search, replacement)
	if (after === before) {
		throw new Error(`EBOOKREADER PATCH failed (pattern not found) in ${file}: ${search}`)
	}
	writeFileSync(p, after)
}

// PATCH 1+2: never grant allow-scripts to section iframes (EPUB scripts must not run).
const sandboxRe = /(\w+)\.setAttribute\('sandbox', 'allow-same-origin allow-scripts'\)/
const sandboxNew = "$1.setAttribute('sandbox', globalThis.__EBOOKREADER_SANDBOX ?? 'allow-same-origin') // EBOOKREADER PATCH: no allow-scripts"
patch('paginator.js', sandboxRe, sandboxNew)
patch('fixed-layout.js', sandboxRe, sandboxNew)
// PATCH 3: pdf.js is not vendored (out of scope), keep Vite from resolving the missing module.
patch('view.js', /const \{ makePDF \} = await import\('\.\/pdf\.js'\)\s*\n\s*book = await makePDF\(file\)/,
	"throw new UnsupportedTypeError('PDF not supported') // EBOOKREADER PATCH: pdf.js not vendored")

writeFileSync(join(dest, 'COMMIT'), sha + '\n')
console.log(`Vendored foliate-js @ ${sha} into ${dest}`)
if (tmp) {
	rmSync(tmp, { recursive: true, force: true })
}
if (!existsSync(join(dest, 'view.js'))) {
	throw new Error('vendoring failed')
}
