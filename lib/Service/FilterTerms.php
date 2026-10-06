<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\Db\Book;

/**
 * Pure helpers for the special filter terms: hierarchical names (`tag:Fantasy/*`), `shelf:<id>` and `age:<rating>`.
 */
final class FilterTerms {
	/** `age:none` = books without an age rating */
	public const AGE_NONE = 'none';
	public const SEPARATOR = '/';
	public const MAX_LEVELS = 5;
	public const WILDCARD = '/*';

	/**
	 * Normalises a hierarchical name: whitespace collapsed, spaces around "/" trimmed, empty levels dropped,
	 * at most MAX_LEVELS levels. Returns null if nothing is left.
	 */
	public static function normalizeHierarchy(string $name): ?string {
		$parts = [];
		foreach (explode(self::SEPARATOR, $name) as $part) {
			$part = trim(preg_replace('/\s+/u', ' ', $part) ?? $part);
			if ($part !== '') {
				$parts[] = $part;
			}
		}
		if ($parts === []) {
			return null;
		}
		return implode(self::SEPARATOR, array_slice($parts, 0, self::MAX_LEVELS));
	}

	/**
	 * For a term name like "Fantasy/*" returns the normalised base ("Fantasy"), otherwise null.
	 * A bare "*" or "/*" is not a hierarchy term.
	 */
	public static function hierarchyBase(string $name): ?string {
		$name = trim($name);
		if (!str_ends_with($name, self::WILDCARD)) {
			return null;
		}
		return self::normalizeHierarchy(substr($name, 0, -strlen(self::WILDCARD)));
	}

	/**
	 * Does a stored tag/genre name match a term? "Fantasy/*" matches "Fantasy" and "Fantasy/..." (not "Fantasyx"),
	 * a plain term matches the full name only; both case-insensitive. Mirrors the SQL built by LibraryService.
	 */
	public static function tagMatches(string $tagName, string $term): bool {
		$tag = mb_strtolower(trim($tagName));
		$base = self::hierarchyBase($term);
		if ($base === null) {
			return $tag === mb_strtolower(trim($term));
		}
		$base = mb_strtolower($base);
		return $tag === $base || str_starts_with($tag, $base . self::SEPARATOR);
	}

	/** Shelf id of a `shelf:<id>` term name, or null if it is not a positive integer. */
	public static function shelfId(string $name): ?int {
		$name = trim($name);
		if ($name === '' || !ctype_digit($name) || strlen($name) > 18) {
			return null;
		}
		$id = (int)$name;
		return $id > 0 ? $id : null;
	}

	/**
	 * Canonical name of an `age:` term, or null if it is invalid:
	 * "0" | "6" | "12" | "16" | "18" (exactly this rating), "none" (no rating) or "<=N" with N one of the levels
	 * (rated N or lower; books without a rating do not match). Spaces are dropped ("<= 12" becomes "<=12").
	 */
	public static function normalizeAge(string $name): ?string {
		$name = strtolower(preg_replace('/\s+/', '', $name) ?? $name);
		if ($name === self::AGE_NONE) {
			return $name;
		}
		$prefix = str_starts_with($name, '<=') ? '<=' : '';
		$num = substr($name, strlen($prefix));
		if ($num === '' || !ctype_digit($num) || strlen($num) > 2 || !in_array((int)$num, Book::AGE_RATINGS, true)) {
			return null;
		}
		return $prefix . (string)(int)$num;
	}

	/**
	 * Parsed `age:` term: ['op' => 'none'|'eq'|'lte', 'value' => ?int], null if invalid.
	 * @return array{op: 'none'|'eq'|'lte', value: ?int}|null
	 */
	public static function parseAge(string $name): ?array {
		$name = self::normalizeAge($name);
		if ($name === null) {
			return null;
		}
		if ($name === self::AGE_NONE) {
			return ['op' => 'none', 'value' => null];
		}
		if (str_starts_with($name, '<=')) {
			return ['op' => 'lte', 'value' => (int)substr($name, 2)];
		}
		return ['op' => 'eq', 'value' => (int)$name];
	}

	/** Mirrors the SQL of an `age:` term for a book's rating (null = none). */
	public static function ageMatches(?int $rating, string $term): bool {
		$t = self::parseAge($term);
		if ($t === null) {
			return false;
		}
		return match ($t['op']) {
			'none' => $rating === null,
			'eq' => $rating === $t['value'],
			'lte' => $rating !== null && $rating <= (int)$t['value'],
		};
	}
}
