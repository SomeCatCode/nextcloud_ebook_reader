<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

/**
 * Maps age ratings found in book files to the app's fixed levels 0, 6, 12, 16, 18 (null = unknown/none).
 *
 * Named ComicInfo.xml ratings use a table of common equivalents (e.g. ESRB "Teen" ~ USK 12); a bare minimum age
 * ("16", "16+", "FSK 12", "Ages 10+", schema.org typicalAgeRange "13-17") is rounded UP to the next level, so a
 * mapped rating is never lower than the source.
 */
final class AgeRating {
	public const LEVELS = [0, 6, 12, 16, 18];

	/** lower-cased ComicInfo AgeRating values (schema v2.1 enumeration plus common spellings) */
	private const NAMED = [
		'unknown' => null,
		'rating pending' => null,
		'pending' => null,
		'early childhood' => 0,
		'everyone' => 0,
		'e' => 0,
		'g' => 0,
		'all ages' => 0,
		'kids to adults' => 6,
		'k-a' => 6,
		'everyone 10+' => 12,
		'e10+' => 12,
		'pg' => 12,
		'teen' => 12,
		't' => 12,
		't+' => 16,
		'teen plus' => 16,
		'm' => 16,
		'ma15+' => 16,
		'mature' => 18,
		'mature 17+' => 18,
		'adults only 18+' => 18,
		'adults only' => 18,
		'ao' => 18,
		'r18+' => 18,
		'x18+' => 18,
		'explicit' => 18,
		'adult' => 18,
	];

	/** Smallest level that is at least $age (ages above 18 become 18, negative ages 0). */
	public static function fromAge(int $age): int {
		foreach (self::LEVELS as $level) {
			if ($age <= $level) {
				return $level;
			}
		}
		return 18;
	}

	/** Whether a value is one of the allowed levels. */
	public static function isLevel(mixed $value): bool {
		return is_int($value) && in_array($value, self::LEVELS, true);
	}

	/** ComicInfo.xml `AgeRating` (or any free text rating) to a level; unknown values give null. */
	public static function fromText(?string $raw): ?int {
		if ($raw === null) {
			return null;
		}
		$text = strtolower(trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw));
		if ($text === '') {
			return null;
		}
		if (array_key_exists($text, self::NAMED)) {
			return self::NAMED[$text];
		}
		// "16", "16+", "Ab 16", "FSK 12", "USK 18", "PEGI 7", "Ages 10+", "13-17": the (first) minimum age
		if (preg_match('/(?<!\d)(\d{1,2})(?!\d)/', $text, $m) === 1) {
			return self::fromAge((int)$m[1]);
		}
		return null;
	}

	/**
	 * schema.org `typicalAgeRange` as used in EPUB 3 (`<meta property="schema:typicalAgeRange">12-</meta>`):
	 * "12-", "7-12", "16+" or "12". The lower bound counts; "-6" (up to 6) means suitable from 0.
	 */
	public static function fromAgeRange(?string $raw): ?int {
		if ($raw === null) {
			return null;
		}
		$text = trim($raw);
		if (preg_match('/^(\d{1,2})\s*(?:\+|-\s*(?:\d{1,2})?)?$/', $text, $m) === 1) {
			return self::fromAge((int)$m[1]);
		}
		if (preg_match('/^-\s*\d{1,2}$/', $text) === 1) {
			return 0;
		}
		return null;
	}
}
