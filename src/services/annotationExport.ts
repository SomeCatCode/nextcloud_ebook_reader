/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Annotation } from '../types.ts'

import { translate as t } from '@nextcloud/l10n'
import { compareLocators } from '../../packages/reader-core/src/annotations.ts'
import { kindOf } from './annotationUtils.ts'

/**
 * "Chapter title, 42 %" or "Page 7" for a comic bookmark.
 *
 * @param a
 */
export function positionLabel(a: Pick<Annotation, 'locator'>): string {
	const parts: string[] = []
	if (a.locator.title) {
		parts.push(a.locator.title)
	}
	const total = a.locator.locations?.totalProgression
	if (typeof total === 'number') {
		parts.push(`${Math.round(total * 100)} %`)
	}
	return parts.join(', ')
}

/**
 * @param text
 */
function quote(text: string): string {
	return text.split(/\r?\n/).map((l) => `> ${l}`).join('\n')
}

/**
 * Markdown export of the annotations of one book (generated in the browser, never sent anywhere).
 *
 * @param book
 * @param book.title
 * @param book.authors
 * @param annotations
 */
export function annotationsToMarkdown(book: { title: string, authors?: string[] }, annotations: Annotation[]): string {
	const sorted = [...annotations].sort((a, b) => compareLocators(a.locator, b.locator))
	const out: string[] = [`# ${book.title}`]
	if (book.authors?.length) {
		out.push('', book.authors.join(', '))
	}
	const sections: [string, Annotation[]][] = [
		[t('ebookreader', 'Highlights'), sorted.filter((a) => kindOf(a) === 'highlight')],
		[t('ebookreader', 'Notes'), sorted.filter((a) => kindOf(a) === 'note')],
		[t('ebookreader', 'Bookmarks'), sorted.filter((a) => kindOf(a) === 'bookmark')],
	]
	for (const [heading, list] of sections) {
		if (!list.length) {
			continue
		}
		out.push('', `## ${heading}`)
		for (const a of list) {
			out.push('')
			if (a.text) {
				out.push(quote(a.text))
			}
			const pos = positionLabel(a)
			if (pos) {
				out.push('', `*${pos}*`)
			}
			if (a.note) {
				out.push('', a.note)
			}
		}
	}
	return out.join('\n').replace(/\n{3,}/g, '\n\n') + '\n'
}
