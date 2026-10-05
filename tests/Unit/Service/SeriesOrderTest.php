<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Service\SeriesOrder;
use PHPUnit\Framework\TestCase;

class SeriesOrderTest extends TestCase {
	private static function book(int $fileId, ?float $index, string $title, string $series = 'Saga', string $status = Book::STATUS_UNREAD): Book {
		$b = new Book();
		$b->setFileId($fileId);
		$b->setSeries($series);
		$b->setSeriesIndex($index);
		$b->setTitle($title);
		$b->setPath('/Books/' . $title . '.epub');
		$b->setReadStatus($status);
		return $b;
	}

	/** @param list<Book> $books */
	private static function ids(array $books): array {
		return array_map(static fn (Book $b): int => $b->getFileId(), $books);
	}

	public function testSortByIndexThenNaturalTitleThenFileId(): void {
		$books = [
			self::book(1, null, 'Vol 10'),
			self::book(2, 2.0, 'B'),
			self::book(3, 1.0, 'A'),
			self::book(4, null, 'Vol 2'),
			self::book(5, 1.5, 'Interlude'),
			self::book(7, null, 'vol 2'),
		];
		$this->assertSame([3, 5, 2, 4, 7, 1], self::ids(SeriesOrder::sort($books)));
	}

	public function testNextVolume(): void {
		$v1 = self::book(1, 1.0, 'One');
		$v2 = self::book(2, 2.0, 'Two');
		$v3 = self::book(3, 3.0, 'Three');
		$volumes = [$v3, $v1, $v2];
		$this->assertSame(2, SeriesOrder::next($volumes, $v1)?->getFileId());
		$this->assertSame(3, SeriesOrder::next($volumes, $v2)?->getFileId());
		$this->assertNull(SeriesOrder::next($volumes, $v3));
	}

	public function testNextSkipsOtherCopiesOfTheSameVolumeAndMatchesSeriesCaseInsensitively(): void {
		$v1 = self::book(1, 1.0, 'One');
		$v1cbz = self::book(9, 1.0, 'One (comic)', 'saga ');
		$v2 = self::book(2, 2.0, 'Two', 'SAGA');
		$other = self::book(5, 1.5, 'Elsewhere', 'Other');
		$this->assertSame(2, SeriesOrder::next([$v1, $v1cbz, $v2, $other], $v1)?->getFileId());
	}

	public function testNextWithoutSeriesOrUnknownBookIsNull(): void {
		$lone = self::book(1, 1.0, 'Lone', '');
		$this->assertNull(SeriesOrder::next([$lone], $lone));
		$v1 = self::book(1, 1.0, 'One');
		$this->assertNull(SeriesOrder::next([self::book(2, 2.0, 'Two')], $v1));
	}

	public function testNextWithoutIndexUsesTitleOrder(): void {
		$a = self::book(1, null, 'Part 1');
		$b = self::book(2, null, 'Part 2');
		$c = self::book(3, null, 'Part 10');
		$this->assertSame(2, SeriesOrder::next([$c, $b, $a], $a)?->getFileId());
		$this->assertSame(3, SeriesOrder::next([$c, $b, $a], $b)?->getFileId());
	}

	public function testNextWithAcceptSkipsRejectedVolumes(): void {
		$v1 = self::book(1, 1.0, 'One', 'Saga', Book::STATUS_FINISHED);
		$v2 = self::book(2, 2.0, 'Two', 'Saga', Book::STATUS_FINISHED);
		$v3 = self::book(3, 3.0, 'Three');
		$next = SeriesOrder::next([$v1, $v2, $v3], $v1, static fn (Book $b): bool => $b->getReadStatus() !== Book::STATUS_FINISHED);
		$this->assertSame(3, $next?->getFileId());
	}

	public function testUpNextOffersTheNextUnstartedVolumeOfFinishedSeries(): void {
		$s1 = self::book(1, 1.0, 'S1', 'Saga', Book::STATUS_FINISHED);
		$s2 = self::book(2, 2.0, 'S2', 'Saga');
		// series "Other": the latest read volume is still being read -> nothing
		$o1 = self::book(10, 1.0, 'O1', 'Other', Book::STATUS_FINISHED);
		$o2 = self::book(11, 2.0, 'O2', 'Other', Book::STATUS_READING);
		// series "Done": last volume finished -> nothing
		$d1 = self::book(20, 1.0, 'D1', 'Done', Book::STATUS_FINISHED);
		// series "Started": next volume already reading -> nothing (it is in continue reading)
		$st1 = self::book(30, 1.0, 'St1', 'Started', Book::STATUS_FINISHED);
		$st2 = self::book(31, 2.0, 'St2', 'Started', Book::STATUS_READING);
		$recent = [$o2, $s1, $o1, $d1, $st1];
		$volumes = [
			'saga' => [$s1, $s2],
			'other' => [$o1, $o2],
			'done' => [$d1],
			'started' => [$st1, $st2],
		];
		$result = SeriesOrder::upNext($recent, $volumes, [11 => true], 10);
		$this->assertCount(1, $result);
		$this->assertSame(1, $result[0]['previous']->getFileId());
		$this->assertSame(2, $result[0]['next']->getFileId());
	}

	public function testUpNextSkipsFinishedVolumesAndRespectsExcludeAndLimit(): void {
		$a1 = self::book(1, 1.0, 'A1', 'A', Book::STATUS_FINISHED);
		$a2 = self::book(2, 2.0, 'A2', 'A', Book::STATUS_FINISHED);
		$a3 = self::book(3, 3.0, 'A3', 'A');
		$b1 = self::book(4, 1.0, 'B1', 'B', Book::STATUS_FINISHED);
		$b2 = self::book(5, 2.0, 'B2', 'B');
		$volumes = ['a' => [$a1, $a2, $a3], 'b' => [$b1, $b2]];
		// a1 was read most recently (re-read), a2 finished before: the next not finished volume is a3
		$res = SeriesOrder::upNext([$a1, $b1, $a2], $volumes, [], 10);
		$this->assertSame([3, 5], array_map(static fn (array $e): int => $e['next']->getFileId(), $res));
		$this->assertCount(1, SeriesOrder::upNext([$a1, $b1], $volumes, [], 1));
		$this->assertSame([5], array_map(static fn (array $e): int => $e['next']->getFileId(), SeriesOrder::upNext([$a1, $b1], $volumes, [3 => true], 10)));
	}
}
