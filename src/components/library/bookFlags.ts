/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { AgeRating, Book, Completion, FacetEntry } from '../../types.ts'

import { t } from '@nextcloud/l10n'

export const COMPLETIONS: Completion[] = ['ongoing', 'completed']
/** names a `completion:` filter term can use */
export const COMPLETION_TERMS = ['ongoing', 'completed', 'unknown'] as const
export const AGE_RATINGS: AgeRating[] = [0, 6, 12, 16, 18]
/** `age:<=N` navigation entries ("Up to N"); 18 would be "every rated book" */
export const AGE_MAX_LEVELS: AgeRating[] = [0, 6, 12, 16]

/**
 * @param value
 */
export function isAgeRating(value: unknown): value is AgeRating {
	return typeof value === 'number' && (AGE_RATINGS as number[]).includes(value)
}

/**
 * @param name
 */
export function isCompletionTerm(name: string): boolean {
	return (COMPLETION_TERMS as readonly string[]).includes(name)
}

/**
 * Canonical name of an `age:` term (mirrors the server): "0".."18", "none" or "<=N"; null if invalid.
 *
 * @param name
 */
export function normalizeAgeTerm(name: string): string | null {
	const n = name.replace(/\s+/g, '').toLowerCase()
	if (n === 'none') {
		return n
	}
	const prefix = n.startsWith('<=') ? '<=' : ''
	const num = n.slice(prefix.length)
	if (!/^\d{1,2}$/.test(num) || !isAgeRating(Number(num))) {
		return null
	}
	return prefix + String(Number(num))
}

/**
 * Short badge text of an age rating, e.g. "16+".
 *
 * @param age
 */
export function ageBadge(age: AgeRating): string {
	return t('ebookreader', '{age}+', { age: String(age) })
}

/**
 * @param age
 */
export function ageTitle(age: AgeRating): string {
	return t('ebookreader', 'Age rating: from {age} years', { age: String(age) })
}

/**
 * @param completion
 */
export function completionLabel(completion: Completion | 'unknown' | null): string {
	switch (completion) {
		case 'ongoing': return t('ebookreader', 'Ongoing')
		case 'completed': return t('ebookreader', 'Completed')
		default: return t('ebookreader', 'Unknown')
	}
}

/**
 * Label of an `age:` term name ("16+", "Up to 12", "No age rating").
 *
 * @param name
 */
export function ageTermLabel(name: string): string {
	const n = normalizeAgeTerm(name)
	if (n === null) {
		return name
	}
	if (n === 'none') {
		return t('ebookreader', 'No age rating')
	}
	if (n.startsWith('<=')) {
		return t('ebookreader', 'Up to {age}', { age: n.slice(2) })
	}
	return ageBadge(Number(n) as AgeRating)
}

/**
 * Label of a `completion:` term name.
 *
 * @param name
 */
export function completionTermLabel(name: string): string {
	return completionLabel(name === 'ongoing' || name === 'completed' ? name : 'unknown')
}

export interface FlagNavEntry {
	/** term name */
	name: string
	label: string
	count: number
}

/**
 * Navigation entries of the "Completion" group (facets in server order), only entries with books.
 *
 * @param facets
 */
export function completionEntries(facets: FacetEntry[] | undefined): FlagNavEntry[] {
	return (facets ?? [])
		.filter((f) => isCompletionTerm(f.name) && f.count > 0)
		.map((f) => ({ name: f.name, label: completionTermLabel(f.name), count: f.count }))
}

/**
 * Navigation entries of the "Age rating" group: "Up to N" (sum of the levels up to N), then the single levels and
 * "No age rating"; entries without books are left out. Nothing at all when no book has a rating.
 *
 * @param facets
 */
export function ageEntries(facets: FacetEntry[] | undefined): FlagNavEntry[] {
	const counts = new Map((facets ?? []).map((f) => [f.name, f.count]))
	const rated = AGE_RATINGS.reduce<number>((sum, a) => sum + (counts.get(String(a)) ?? 0), 0)
	if (rated === 0) {
		return []
	}
	const out: FlagNavEntry[] = []
	for (const max of AGE_MAX_LEVELS) {
		const count = AGE_RATINGS.filter((a) => a <= max).reduce<number>((sum, a) => sum + (counts.get(String(a)) ?? 0), 0)
		if (count > 0) {
			out.push({ name: '<=' + max, label: ageTermLabel('<=' + max), count })
		}
	}
	for (const name of [...AGE_RATINGS.map(String), 'none']) {
		const count = counts.get(name) ?? 0
		if (count > 0) {
			out.push({ name, label: ageTermLabel(name), count })
		}
	}
	return out
}

/**
 * Display label of a volume: "Volume 3" by series index, otherwise the title.
 *
 * @param book
 * @param title display title (see bookTitle)
 */
export function volumeLabel(book: Pick<Book, 'seriesIndex'>, title: string): string {
	return book.seriesIndex !== null && book.seriesIndex !== undefined
		? t('ebookreader', 'Volume {index}: {title}', { index: String(book.seriesIndex), title })
		: title
}

/** Progress from which the reader offers the next volume (same threshold as the server's "finished"). */
export const NEXT_VOLUME_THRESHOLD = 0.98

/**
 * Whether the reader should offer the next volume: at the end of a book that belongs to a series.
 *
 * @param book
 * @param percentage current reading position 0..1
 */
export function offersNextVolume(book: Pick<Book, 'series'> | null, percentage: number): boolean {
	return !!book?.series && percentage >= NEXT_VOLUME_THRESHOLD
}
