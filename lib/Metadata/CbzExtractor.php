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

		$parsed = ComicInfoParser::parse($comicInfo === null ? null : $zip->read($comicInfo));

		$coverData = null;
		$coverMime = null;
		$order = array_unique(array_merge([$parsed['coverIndex']], [0, 1]));
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

		return ComicInfoParser::toMetadata($parsed, $coverData, $coverMime);
	}
}
