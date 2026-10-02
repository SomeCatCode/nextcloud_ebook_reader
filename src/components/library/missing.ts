/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { MissingCounts, MissingField } from '../../types.ts'

import { t } from '@nextcloud/l10n'

/** Fields a `missing:<field>` filter term can ask for, in navigation order */
export const MISSING_FIELDS: MissingField[] = ['genre', 'tag', 'author', 'series', 'description', 'cover', 'language']

/**
 * @param name
 */
export function isMissingField(name: string): name is MissingField {
	return (MISSING_FIELDS as string[]).includes(name)
}

export const emptyMissing = (): MissingCounts => ({ genre: 0, tag: 0, author: 0, series: 0, description: 0, cover: 0, language: 0 })

/**
 * Label of a missing term: "Without genre" when included, "Has genre" when excluded (navigation: include wording).
 *
 * @param field
 * @param state
 */
export function missingLabel(field: string, state: 'include' | 'exclude' = 'include'): string {
	const without = state === 'include'
	switch (field) {
		case 'genre': return without ? t('ebookreader', 'Without genre') : t('ebookreader', 'Has genre')
		case 'tag': return without ? t('ebookreader', 'Without tags') : t('ebookreader', 'Has tags')
		case 'author': return without ? t('ebookreader', 'Without author') : t('ebookreader', 'Has author')
		case 'series': return without ? t('ebookreader', 'Without series') : t('ebookreader', 'Has series')
		case 'description': return without ? t('ebookreader', 'Without description') : t('ebookreader', 'Has description')
		case 'cover': return without ? t('ebookreader', 'Without cover') : t('ebookreader', 'Has cover')
		case 'language': return without ? t('ebookreader', 'Without language') : t('ebookreader', 'Has language')
		default: return field
	}
}

/**
 * Navigation entries of the "Needs attention" group: only fields with at least one book.
 *
 * @param counts
 */
export function missingEntries(counts: MissingCounts): { field: MissingField, count: number }[] {
	return MISSING_FIELDS.map((field) => ({ field, count: counts[field] ?? 0 })).filter((e) => e.count > 0)
}
