<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Service\SeriesAggregator;
use PHPUnit\Framework\TestCase;

class SeriesAggregatorTest extends TestCase {
	/** @return array<string, mixed> */
	private static function row(int $id, string $series, ?float $index, int $added = 1, string $status = 'unread', string $title = 'T'): array {
		return ['file_id' => $id, 'series' => $series, 'series_index' => $index, 'added_at' => $added, 'read_status' => $status, 'title' => $title, 'path' => '/x'];
	}

	public function testAggregatesCountsReadAndCovers(): void {
		$rows = [
			self::row(1, 'Saga', 3.0, 100, 'finished'),
			self::row(2, 'Saga', 1.0, 300, 'finished'),
			self::row(3, 'Saga', 2.0, 200, 'reading'),
			self::row(4, 'Saga', 4.0, 50),
			self::row(5, 'Other', null, 10),
		];
		$out = SeriesAggregator::aggregate($rows);
		$this->assertCount(2, $out);
		$this->assertSame('Other', $out[0]['name']);
		$saga = $out[1];
		$this->assertSame('Saga', $saga['name']);
		$this->assertSame(4, $saga['count']);
		$this->assertSame(2, $saga['readCount']);
		$this->assertSame([2, 3, 1], $saga['coverFileIds']);
		$this->assertSame(2, $saga['firstFileId']);
		$this->assertSame(300, $saga['lastAddedAt']);
	}

	public function testNullIndexComesLastThenTitle(): void {
		$out = SeriesAggregator::aggregate([
			self::row(1, 'S', null, 1, 'unread', 'b'),
			self::row(2, 'S', null, 1, 'unread', 'a'),
			self::row(3, 'S', 5.0),
		]);
		$this->assertSame([3, 2, 1], $out[0]['coverFileIds']);
	}

	public function testNaturalNameSortAndDirection(): void {
		$rows = [self::row(1, 'Band 10', 1.0), self::row(2, 'band 2', 1.0), self::row(3, 'Alpha', 1.0)];
		$this->assertSame(['Alpha', 'band 2', 'Band 10'], array_column(SeriesAggregator::aggregate($rows), 'name'));
		$this->assertSame(['Band 10', 'band 2', 'Alpha'], array_column(SeriesAggregator::aggregate($rows, 'name', 'desc'), 'name'));
	}

	public function testSortByLastAdded(): void {
		$rows = [self::row(1, 'A', 1.0, 10), self::row(2, 'B', 1.0, 30), self::row(3, 'C', 1.0, 20)];
		$this->assertSame(['A', 'C', 'B'], array_column(SeriesAggregator::aggregate($rows, 'added'), 'name'));
		$this->assertSame(['B', 'C', 'A'], array_column(SeriesAggregator::aggregate($rows, 'added', 'desc'), 'name'));
	}

	public function testGroupsCaseInsensitivelyAndSkipsEmptyNames(): void {
		$out = SeriesAggregator::aggregate([self::row(1, 'Saga', 1.0), self::row(2, 'saga', 2.0), self::row(3, '  ', 1.0)]);
		$this->assertCount(1, $out);
		$this->assertSame(2, $out[0]['count']);
	}

	public function testLimit(): void {
		$rows = [];
		for ($i = 0; $i < 5; $i++) {
			$rows[] = self::row($i + 1, 'S' . $i, 1.0);
		}
		$this->assertCount(3, SeriesAggregator::aggregate($rows, 'name', 'asc', 3));
		$this->assertSame(2000, SeriesAggregator::MAX_SERIES);
	}
}
