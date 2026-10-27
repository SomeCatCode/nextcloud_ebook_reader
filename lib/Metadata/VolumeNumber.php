<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

/**
 * Reads a volume (or chapter) number out of a title or file name when the metadata has no series index.
 *
 * Recognised, in this order: "Vol. 10" / "Volume 12" / "v02" / "Band 3" / "Tome 4" / "Book 2" / "#7" / "No. 5" / "T04",
 * then "Ch. 5" / "Chapter 5" / "Kapitel 5" / "Ep. 5", then "第3巻", then a trailing number ("Saga 10", "Saga 10 (Digital)").
 * With $leading a leading number ("01 - Title") counts as well. Decimals ("10.5") are kept; years (1900-2100) are not
 * taken as a trailing number. The same rules exist in the Android app.
 */
final class VolumeNumber {
	private const NUMBER = '0*(\d{1,5}(?:\.\d{1,3})?)';
	private const LONG_VOLUME = 'vol(?:ume|umen|\.)?|band|bd|tome|tomo|livre|book|buch|teil|part|volumen|deel|bok';
	private const CHAPTER = 'chapter|chap|ch|kapitel|kap|episode|ep|capitulo|cap[ií]tulo|chapitre';

	public static function parse(?string $text, bool $leading = false): ?float {
		if ($text === null) {
			return null;
		}
		$text = trim(str_replace('_', ' ', $text));
		if ($text === '') {
			return null;
		}
		$text = (string)preg_replace('/\.(?:fb2\.zip|[A-Za-z0-9]{2,5})$/u', '', $text);

		$patterns = [
			// "Vol. 10", "Volume 12", "Band 3", "Tome 4"
			'/(?<![\p{L}\p{N}])(?:' . self::LONG_VOLUME . ')\s*\.?\s*' . self::NUMBER . '(?![\p{L}\p{N}]|\.\d)/iu',
			// "v02", "v.2", "t4", "#7", "No. 5", "Nr. 5"
			'/(?<![\p{L}\p{N}])(?:v|t|#|no|nr|n°|№)\s*\.?\s*' . self::NUMBER . '(?![\p{L}\p{N}]|\.\d)/iu',
			// chapters
			'/(?<![\p{L}\p{N}])(?:' . self::CHAPTER . ')\s*\.?\s*' . self::NUMBER . '(?![\p{L}\p{N}]|\.\d)/iu',
			// "第3巻", "第12話"
			'/第\s*' . self::NUMBER . '\s*[巻卷冊册話话章回]/u',
		];
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $text, $m) === 1) {
				return (float)$m[1];
			}
		}

		// trailing number, ignoring trailing "(Digital)" / "[Group]"
		$tail = $text;
		do {
			$before = $tail;
			$tail = trim((string)preg_replace('/\s*(?:\([^()]*\)|\[[^\[\]]*\])\s*$/u', '', $tail));
		} while ($tail !== $before);
		if (preg_match('/(?:^|[\s\-\x{2013}:,])' . self::NUMBER . '$/u', $tail, $m) === 1) {
			$n = (float)$m[1];
			if (!self::isYear($m[1])) {
				return $n;
			}
		}

		if ($leading && preg_match('/^' . self::NUMBER . '\s*(?:[-\x{2013}.)_:]\s|\s)/u', $tail . ' ', $m) === 1 && !self::isYear($m[1])) {
			return (float)$m[1];
		}
		return null;
	}

	/** Number of a book: from the title first, then from the file name (where a leading number counts too). */
	public static function fromTitleOrFilename(?string $title, ?string $path): ?float {
		$n = self::parse($title);
		if ($n !== null) {
			return $n;
		}
		$name = $path === null || $path === '' ? null : pathinfo($path, PATHINFO_BASENAME);
		return self::parse($name, true);
	}

	private static function isYear(string $digits): bool {
		return preg_match('/^(?:19|20)\d{2}$/', $digits) === 1;
	}
}
