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
	 * @param ?int $ageRating age rating found in the file (0, 6, 12, 16, 18), see AgeRating
	 * @param bool $longstrip the file marks the comic as long strip (webtoon), see Longstrip
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
		public readonly ?int $ageRating = null,
		public readonly bool $longstrip = false,
	) {
	}

	/** At most this many authors, genres, tags and subjects are kept (a crafted book could list thousands) */
	public const MAX_LIST = 100;
	/** Longer author names and subjects are cut */
	public const MAX_NAME = 255;

	/**
	 * Copy within the limits of the database: lists and names shortened, description at most HtmlSanitizer::MAX_OUTPUT.
	 */
	public function limited(): self {
		$list = static fn (array $names): array => array_values(array_map(
			static fn (string $n): string => mb_substr($n, 0, self::MAX_NAME),
			array_slice($names, 0, self::MAX_LIST),
		));
		return $this->with([
			'authors' => $list($this->authors),
			'genres' => $list($this->genres),
			'tags' => $list($this->tags),
			'subjects' => $list($this->subjects),
			'description' => $this->description !== null ? HtmlSanitizer::limit($this->description) : null,
		]);
	}

	/**
	 * Returns a copy with the given properties replaced (named property => new value).
	 * @param array<string, mixed> $changes
	 */
	public function with(array $changes): self {
		/** @psalm-suppress InvalidArgument */
		return new self(...array_merge(get_object_vars($this), $changes));
	}
}
