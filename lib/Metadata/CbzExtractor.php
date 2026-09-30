<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

/** CBZ: ComicInfo.xml plus cover from FrontCover page or first image. */
class CbzExtractor implements ExtractorInterface {
	#[\Override]
	public function supports(string $format): bool {
		return $format === 'cbz';
	}

	#[\Override]
	public function extract(string $localPath): BookMetadata {
		$zip = SafeZip::open($localPath);
		try {
			return $this->read($zip);
		} finally {
			$zip->close();
		}
	}

	private function read(SafeZip $zip): BookMetadata {
		$images = [];
		$comicInfo = null;
		foreach ($zip->names() as $name) {
			if (str_contains($name, '__MACOSX/') || str_starts_with(basename($name), '.')) {
				continue;
			}
			if (strtolower(basename($name)) === 'comicinfo.xml') {
				$comicInfo ??= $name;
			} elseif (ImageUtil::isImageName($name)) {
				$images[] = $name;
			}
		}
		usort($images, static fn (string $a, string $b): int => strnatcasecmp($a, $b));

		$fields = [];
		$coverIndex = 0;
		if ($comicInfo !== null) {
			$doc = XmlUtil::load($zip->read($comicInfo) ?? '');
			if ($doc !== null) {
				$xp = new \DOMXPath($doc);
				foreach (['Title', 'Series', 'Number', 'Summary', 'Writer', 'Publisher', 'Year', 'Month', 'Day', 'LanguageISO', 'Genre', 'Tags'] as $f) {
					$fields[$f] = XmlUtil::text($xp, "/*/*[local-name()='$f']");
				}
				$summaryNode = $xp->query("/*/*[local-name()='Summary']")->item(0);
				if ($summaryNode !== null) {
					$fields['SummaryRaw'] = $summaryNode->textContent;
				}
				$pages = $xp->query("//*[local-name()='Pages']/*[local-name()='Page'][@Type='FrontCover']");
				$pageEl = $pages === false ? null : $pages->item(0);
				if ($pageEl instanceof \DOMElement && ctype_digit($pageEl->getAttribute('Image'))) {
					$coverIndex = (int)$pageEl->getAttribute('Image');
				}
			}
		}

		$coverData = null;
		$coverMime = null;
		$order = array_unique(array_merge([$coverIndex], [0, 1]));
		foreach ($order as $i) {
			if (!isset($images[$i])) {
				continue;
			}
			try {
				$data = $zip->read($images[$i]);
			} catch (UnsafeArchiveException) {
				continue;
			}
			$mime = $data === null ? null : ImageUtil::mime($data);
			if ($data !== null && $mime !== null) {
				$coverData = $data;
				$coverMime = $mime;
				break;
			}
		}

		$description = null;
		if (($fields['SummaryRaw'] ?? '') !== '') {
			$clean = HtmlSanitizer::sanitize($fields['SummaryRaw']);
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
}
