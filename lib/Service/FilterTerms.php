<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

/**
 * Pure helpers for the special filter terms: hierarchical names (`tag:Fantasy/*`) and `shelf:<id>`.
 */
final class FilterTerms {
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
}
