<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

/**
 * Recognises long strip comics (webtoons: one tall vertical strip, read by scrolling) from ComicInfo.xml:
 * Format "Webtoon" / "Long Strip" / "Vertical" (as written by Komga, Kavita, Mihon and others) or such a tag/genre.
 */
final class Longstrip {
	private const PATTERN = '/\b(web\s*-?\s*toons?|long\s*-?\s*strips?|vertical\s*(scroll|strip|format)?)\b/i';
	private const TAG_PATTERN = '/^(web\s*-?\s*toons?|long\s*-?\s*strips?|vertical\s*(scroll|strip|format))$/i';

	/**
	 * @param array<string, ?string> $fields parsed ComicInfo fields
	 */
	public static function fromComicInfo(array $fields): bool {
		$format = trim((string)($fields['Format'] ?? ''));
		if ($format !== '' && preg_match(self::PATTERN, $format) === 1) {
			return true;
		}
		foreach (['Tags', 'Genre'] as $field) {
			foreach (XmlUtil::splitList($fields[$field] ?? null) as $value) {
				if (preg_match(self::TAG_PATTERN, trim($value)) === 1) {
					return true;
				}
			}
		}
		return false;
	}
}
