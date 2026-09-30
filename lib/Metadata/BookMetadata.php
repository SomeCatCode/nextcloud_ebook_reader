<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

/**
 * Extracted (or edited) metadata of a book. Immutable DTO.
 */
final class BookMetadata {
	/**
	 * @param list<string> $authors
	 * @param list<string> $genres
	 * @param list<string> $tags
	 * @param list<string> $subjects unclassified dc:subject values
	 * @param ?string $coverData raw image bytes
	 */
	public function __construct(
		public readonly ?string $title = null,
		public readonly array $authors = [],
		public readonly ?string $series = null,
		public readonly ?float $seriesIndex = null,
		public readonly ?string $description = null,
		public readonly ?string $language = null,
		public readonly ?string $publisher = null,
		public readonly ?string $isbn = null,
		public readonly ?string $publishedAt = null,
		public readonly array $genres = [],
		public readonly array $tags = [],
		public readonly array $subjects = [],
		public readonly ?string $coverData = null,
		public readonly ?string $coverMime = null,
	) {
	}
}
