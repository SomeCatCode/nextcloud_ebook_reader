<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

use OCA\EbookReader\Service\ArchiveTools;

/**
 * CBR, CB7 and CBT: ComicInfo.xml plus cover from the FrontCover page or the first image.
 * CBT is read in PHP; CBR and CB7 need an external tool (7z/unrar/bsdtar, see ArchiveTools). Without a
 * tool the result is empty, so MetadataService falls back to the file name (the client then uploads a cover).
 */
class CbrExtractor implements ExtractorInterface {
	public function __construct(
		private ?ArchiveTools $tools = null,
	) {
	}

	#[\Override]
	public function supports(string $format): bool {
		return $format === 'cbr' || $format === 'cb7' || $format === 'cbt';
	}

	/**
	 * @throws \RuntimeException|UnsafeArchiveException if the archive is unreadable although a tool exists
	 */
	#[\Override]
	public function extract(string $localPath, ?string $format = null): BookMetadata {
		$format ??= self::formatFromName($localPath);
		$tools = $this->tools ??= new ArchiveTools();
		if (!$tools->canRead($format)) {
			// nothing installed that can open it: file name only (MetadataService fills in the title)
			return new BookMetadata();
		}
		$archive = ComicArchive::open($localPath, $format, $tools);
		try {
			$parsed = ComicInfoParser::parse($archive->comicInfo());
			[$cover, $mime] = ComicInfoParser::pickCover($archive, $parsed['coverIndex'], $parsed['coverExplicit'] ?? false);
			return ComicInfoParser::toMetadata($parsed, $cover, $mime);
		} finally {
			$archive->close();
		}
	}

	private static function formatFromName(string $path): string {
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return in_array($ext, ['cbr', 'cb7', 'cbt'], true) ? $ext : 'cbr';
	}
}
