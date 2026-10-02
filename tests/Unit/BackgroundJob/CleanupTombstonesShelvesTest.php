<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\BackgroundJob;

use OCA\EbookReader\BackgroundJob\CleanupTombstonesJob;
use OCA\EbookReader\Db\AnnotationMapper;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\ShelfBookMapper;
use OCA\EbookReader\Db\ShelfMapper;
use OCA\EbookReader\Service\ArchiveCache;
use OCA\EbookReader\Service\TaskService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanupTombstonesShelvesTest extends TestCase {
	private function job(BookMapper $books, ShelfMapper $shelves, ShelfBookMapper $shelfBooks, ?AnnotationMapper $annotations = null): CleanupTombstonesJob {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1800000000);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willThrowException(new NotFoundException());
		return new CleanupTombstonesJob(
			$time,
			$books,
			$appData,
			$this->createMock(LoggerInterface::class),
			$this->createMock(TaskService::class),
			$this->createMock(ArchiveCache::class),
			$shelves,
			$shelfBooks,
			$annotations ?? $this->createMock(AnnotationMapper::class),
		);
	}

	private function runJob(CleanupTombstonesJob $job): void {
		$m = new \ReflectionMethod($job, 'run');
		$m->invoke($job, null);
	}

	public function testAssignmentsOfPurgedTombstonesAreRemovedBeforeTheRowsAreDeleted(): void {
		$cutoffMs = (1800000000 - 30 * 86400) * 1000;
		$order = [];
		$books = $this->createMock(BookMapper::class);
		$books->method('findTombstonesOlderThan')->with($cutoffMs, 0, 1000)->willReturn([
			['id' => 10, 'user_id' => 'a', 'file_id' => 100],
			['id' => 11, 'user_id' => 'a', 'file_id' => 101],
			['id' => 12, 'user_id' => 'b', 'file_id' => 100],
		]);
		$books->expects($this->once())->method('deleteTombstonesOlderThan')->with($cutoffMs)->willReturnCallback(function () use (&$order): int {
			$order[] = 'rows';
			return 3;
		});
		$shelves = $this->createMock(ShelfMapper::class);
		$shelves->method('findIdsByUser')->willReturnMap([['a', [1, 2]], ['b', [7]]]);
		$shelfBooks = $this->createMock(ShelfBookMapper::class);
		$calls = [];
		$shelfBooks->method('deleteFilesFromShelves')->willReturnCallback(function (array $shelfIds, array $fileIds) use (&$calls, &$order): void {
			$calls[] = [$shelfIds, $fileIds];
			$order[] = 'assignments';
		});

		$this->runJob($this->job($books, $shelves, $shelfBooks));

		// each user only touches their own shelves
		$this->assertSame([[[1, 2], [100, 101]], [[7], [100]]], $calls);
		$this->assertSame(['assignments', 'assignments', 'rows'], $order);
	}

	public function testAnnotationsOfPurgedBooksAndOldAnnotationTombstonesAreRemoved(): void {
		$books = $this->createMock(BookMapper::class);
		$books->method('findTombstonesOlderThan')->willReturn([
			['id' => 10, 'user_id' => 'a', 'file_id' => 100],
			['id' => 11, 'user_id' => 'a', 'file_id' => 101],
			['id' => 12, 'user_id' => 'b', 'file_id' => 100],
		]);
		$shelves = $this->createMock(ShelfMapper::class);
		$shelves->method('findIdsByUser')->willReturn([]);
		$annotations = $this->createMock(AnnotationMapper::class);
		$calls = [];
		$annotations->method('deleteByUserAndFiles')->willReturnCallback(function (string $user, array $files) use (&$calls): void {
			$calls[] = [$user, $files];
		});
		// annotation tombstones are kept 90 days, books 30
		$annotations->expects($this->once())->method('deleteTombstonesOlderThan')->with((1800000000 - 90 * 86400) * 1000);

		$this->runJob($this->job($books, $shelves, $this->createMock(ShelfBookMapper::class), $annotations));

		$this->assertSame([['a', [100, 101]], ['b', [100]]], $calls);
	}

	public function testNothingToPurgeTouchesNoAssignments(): void {
		$books = $this->createMock(BookMapper::class);
		$books->method('findTombstonesOlderThan')->willReturn([]);
		$shelfBooks = $this->createMock(ShelfBookMapper::class);
		$shelfBooks->expects($this->never())->method('deleteFilesFromShelves');
		$this->runJob($this->job($books, $this->createMock(ShelfMapper::class), $shelfBooks));
	}

	public function testPagesThroughManyTombstones(): void {
		$page1 = [];
		for ($i = 1; $i <= 1000; $i++) {
			$page1[] = ['id' => $i, 'user_id' => 'a', 'file_id' => $i];
		}
		$books = $this->createMock(BookMapper::class);
		$books->method('findTombstonesOlderThan')->willReturnCallback(static fn (int $cutoff, int $after): array => $after === 0 ? $page1 : [['id' => 1001, 'user_id' => 'a', 'file_id' => 5000]]);
		$shelves = $this->createMock(ShelfMapper::class);
		$shelves->method('findIdsByUser')->willReturn([1]);
		$shelfBooks = $this->createMock(ShelfBookMapper::class);
		$shelfBooks->expects($this->exactly(2))->method('deleteFilesFromShelves');
		$this->runJob($this->job($books, $shelves, $shelfBooks));
	}

	public function testShelfFailureDoesNotPreventPurging(): void {
		$books = $this->createMock(BookMapper::class);
		$books->method('findTombstonesOlderThan')->willThrowException(new \RuntimeException('boom'));
		$books->expects($this->once())->method('deleteTombstonesOlderThan');
		$this->runJob($this->job($books, $this->createMock(ShelfMapper::class), $this->createMock(ShelfBookMapper::class)));
	}
}
