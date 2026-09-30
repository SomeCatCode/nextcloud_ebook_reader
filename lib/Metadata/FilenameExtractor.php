<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

/** Fallback extractor: derives title (and author) from the file name, "Author - Title". */
class FilenameExtractor implements ExtractorInterface {
	#[\Override]
	public function supports(string $format): bool {
		return true;
	}

	#[\Override]
	public function extract(string $localPath): BookMetadata {
		return $this->fromFilename(basename($localPath));
	}

	public function fromFilename(string $filename): BookMetadata {
		$base = preg_replace('/\.fb2\.zip$/i', '', $filename) ?? $filename;
		$base = preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $base) ?? $base;
		$base = trim(preg_replace('/\s+/u', ' ', str_replace('_', ' ', $base)) ?? $base);
		if ($base === '') {
			return new BookMetadata();
		}
		$parts = preg_split('/\s+-\s+/u', $base, 2) ?: [$base];
		if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
			$authors = [];
			foreach (preg_split('/\s+&\s+|;\s*/u', trim($parts[0])) ?: [] as $a) {
				$a = trim($a);
				if ($a !== '') {
					$authors[] = $a;
				}
			}
			return new BookMetadata(title: trim($parts[1]), authors: $authors);
		}
		return new BookMetadata(title: $base);
	}
}
