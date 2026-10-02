<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

/** Parses (and generates) ComicInfo.xml; shared by the CBZ and CBR/CB7/CBT extractors and the converter. */
final class ComicInfoParser {
	private const FIELDS = ['Title', 'Series', 'Number', 'Summary', 'Writer', 'Publisher', 'Year', 'Month', 'Day', 'LanguageISO', 'Genre', 'Tags', 'Manga'];

	/**
	 * @return array{fields: array<string, ?string>, summaryRaw: ?string, coverIndex: int, coverExplicit?: bool}
	 */
	public static function parse(?string $xml): array {
		$fields = [];
		$summaryRaw = null;
		$coverIndex = 0;
		$coverExplicit = false;
		$doc = $xml === null || $xml === '' ? null : XmlUtil::load($xml);
		if ($doc !== null) {
			$xp = new \DOMXPath($doc);
			foreach (self::FIELDS as $f) {
				$fields[$f] = XmlUtil::text($xp, "/*/*[local-name()='$f']");
			}
			$summaryNode = $xp->query("/*/*[local-name()='Summary']")->item(0);
			if ($summaryNode instanceof \DOMElement) {
				$summaryRaw = $summaryNode->textContent;
			}
			$pages = $xp->query("//*[local-name()='Pages']/*[local-name()='Page'][@Type='FrontCover']");
			$pageEl = $pages === false ? null : $pages->item(0);
			if ($pageEl instanceof \DOMElement && ctype_digit($pageEl->getAttribute('Image'))) {
				$coverIndex = (int)$pageEl->getAttribute('Image');
				$coverExplicit = true;
			}
		}
		return ['fields' => $fields, 'summaryRaw' => $summaryRaw, 'coverIndex' => $coverIndex, 'coverExplicit' => $coverExplicit];
	}

	/** Whether the ComicInfo says the comic reads right to left. */
	public static function isRightToLeft(?string $xml): bool {
		return (self::parse($xml)['fields']['Manga'] ?? null) === 'YesAndRightToLeft';
	}

	/**
	 * @param array{fields: array<string, ?string>, summaryRaw: ?string, coverIndex: int} $parsed
	 */
	public static function toMetadata(array $parsed, ?string $coverData, ?string $coverMime): BookMetadata {
		$fields = $parsed['fields'];
		$description = null;
		if (($parsed['summaryRaw'] ?? '') !== '') {
			$clean = HtmlSanitizer::sanitize((string)$parsed['summaryRaw']);
			$description = $clean === '' ? null : $clean;
		}
		$num = $fields['Number'] ?? null;
		$seriesIndex = $num !== null && is_numeric($num) ? (float)$num : null;

		$publishedAt = null;
		$year = $fields['Year'] ?? null;
		if ($year !== null && preg_match('/^\d{4}$/', $year) === 1) {
			$publishedAt = $year;
			$month = $fields['Month'] ?? null;
			if ($month !== null && ctype_digit($month) && (int)$month >= 1 && (int)$month <= 12) {
				$publishedAt .= '-' . sprintf('%02d', (int)$month);
				$day = $fields['Day'] ?? null;
				if ($day !== null && ctype_digit($day) && (int)$day >= 1 && (int)$day <= 31) {
					$publishedAt .= '-' . sprintf('%02d', (int)$day);
				}
			}
		}

		return new BookMetadata(
			title: $fields['Title'] ?? null,
			authors: XmlUtil::splitList($fields['Writer'] ?? null),
			series: $fields['Series'] ?? null,
			seriesIndex: $seriesIndex,
			description: $description,
			language: $fields['LanguageISO'] ?? null,
			publisher: $fields['Publisher'] ?? null,
			publishedAt: $publishedAt,
			genres: XmlUtil::splitList($fields['Genre'] ?? null),
			tags: XmlUtil::splitList($fields['Tags'] ?? null),
			coverData: $coverData,
			coverMime: $coverMime,
		);
	}

	/**
	 * Picks the cover image: the FrontCover page, else the first readable image.
	 *
	 * @return array{0: ?string, 1: ?string} image bytes and mime
	 */
	public static function pickCover(ComicArchive $archive, int $coverIndex, bool $explicit = false): array {
		$pages = $archive->pages();
		return self::chooseCover(static function (int $i) use ($archive, $pages): ?string {
			return isset($pages[$i]) ? $archive->read($pages[$i]) : null;
		}, $coverIndex, $explicit);
	}

	/** Pages wider than this (width / height) are banners or double pages, not covers. */
	public const MAX_COVER_ASPECT = 1.3;
	/** How many leading pages are considered when looking for a portrait cover. */
	public const COVER_CANDIDATES = 4;

	/**
	 * Cover image of a comic: the page marked as FrontCover in ComicInfo.xml, otherwise the first of the
	 * leading pages in portrait format (a wide title banner or a double page is skipped), otherwise the
	 * first readable image.
	 *
	 * @param \Closure(int): ?string $read page content by index (null if missing)
	 * @return array{0: ?string, 1: ?string} image data and mime type
	 */
	public static function chooseCover(\Closure $read, int $coverIndex, bool $explicit): array {
		$fallback = [null, null];
		$order = $explicit ? [$coverIndex] : [];
		$order = array_values(array_unique(array_merge($order, range(0, self::COVER_CANDIDATES - 1))));
		foreach ($order as $n => $i) {
			try {
				$data = $read($i);
			} catch (\Throwable) {
				continue;
			}
			$mime = $data === null ? null : ImageUtil::mime($data);
			if ($data === null || $mime === null) {
				continue;
			}
			if (($explicit && $n === 0) || self::isPortrait($data)) {
				return [$data, $mime];
			}
			if ($fallback[0] === null) {
				$fallback = [$data, $mime];
			}
		}
		return $fallback;
	}

	private static function isPortrait(string $data): bool {
		$size = @getimagesizefromstring($data);
		if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
			return true;
		}
		return $size[0] / $size[1] <= self::MAX_COVER_ASPECT;
	}

	/**
	 * A minimal ComicInfo.xml from book metadata (used when a converted comic had none).
	 *
	 * @param list<string> $authors
	 * @param list<string> $genres
	 * @param list<string> $tags
	 */
	public static function build(?string $title, array $authors, ?string $series, ?float $seriesIndex, ?string $publisher, ?string $language, ?string $publishedAt, array $genres, array $tags): string {
		$doc = new \DOMDocument('1.0', 'UTF-8');
		$doc->formatOutput = true;
		$root = $doc->createElement('ComicInfo');
		$doc->appendChild($root);
		$add = static function (string $name, ?string $value) use ($doc, $root): void {
			if ($value !== null && $value !== '') {
				$el = $doc->createElement($name);
				$el->appendChild($doc->createTextNode($value));
				$root->appendChild($el);
			}
		};
		$add('Title', $title);
		$add('Series', $series);
		if ($seriesIndex !== null) {
			$add('Number', rtrim(rtrim(number_format($seriesIndex, 2, '.', ''), '0'), '.'));
		}
		$add('Writer', $authors === [] ? null : implode(', ', $authors));
		$add('Publisher', $publisher);
		$add('LanguageISO', $language);
		if ($publishedAt !== null && preg_match('/^(\d{4})(?:-(\d{2}))?(?:-(\d{2}))?$/', $publishedAt, $m) === 1) {
			$add('Year', $m[1]);
			$add('Month', isset($m[2]) && $m[2] !== '' ? (string)(int)$m[2] : null);
			$add('Day', isset($m[3]) && $m[3] !== '' ? (string)(int)$m[3] : null);
		}
		$add('Genre', $genres === [] ? null : implode(', ', $genres));
		$add('Tags', $tags === [] ? null : implode(', ', $tags));
		return (string)$doc->saveXML();
	}
}
