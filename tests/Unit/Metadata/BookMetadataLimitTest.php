<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Metadata\BookMetadata;
use OCA\EbookReader\Metadata\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class BookMetadataLimitTest extends TestCase {
	public function testListsNamesAndDescriptionAreBounded(): void {
		$many = array_map(static fn (int $i): string => 'Name ' . $i, range(1, 5000));
		$meta = (new BookMetadata(
			title: 'T',
			authors: array_merge([str_repeat('x', 1000)], $many),
			description: '<p>' . str_repeat('y', 300 * 1024) . '</p>',
			genres: $many,
			tags: $many,
			subjects: $many,
		))->limited();

		$this->assertCount(BookMetadata::MAX_LIST, $meta->authors);
		$this->assertSame(BookMetadata::MAX_NAME, mb_strlen($meta->authors[0]));
		$this->assertSame('Name 1', $meta->authors[1]);
		$this->assertCount(BookMetadata::MAX_LIST, $meta->genres);
		$this->assertCount(BookMetadata::MAX_LIST, $meta->tags);
		$this->assertCount(BookMetadata::MAX_LIST, $meta->subjects);
		$this->assertLessThanOrEqual(HtmlSanitizer::MAX_OUTPUT, strlen((string)$meta->description));
		$this->assertSame('T', $meta->title);
	}

	public function testSmallMetadataIsUnchanged(): void {
		$meta = new BookMetadata(title: 'T', authors: ['A', 'B'], description: '<p>d</p>', tags: ['x']);
		$this->assertEquals($meta, $meta->limited());
	}
}
