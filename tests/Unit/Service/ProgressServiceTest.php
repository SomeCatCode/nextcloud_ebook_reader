<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Db\Annotation;
use OCA\EbookReader\Db\AnnotationMapper;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Progress;
use OCA\EbookReader\Db\ProgressMapper;
use OCA\EbookReader\Service\ProgressService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProgressServiceTest extends TestCase {
	private const NOW_MS = 1_800_000_000_123;

	private ProgressMapper&MockObject $progressMapper;
	private BookMapper&MockObject $bookMapper;
	private ProgressService $service;

	protected function setUp(): void {
		$this->progressMapper = $this->createMock(ProgressMapper::class);
		$this->bookMapper = $this->createMock(BookMapper::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(\DateTimeImmutable::createFromFormat('U.u', '1800000000.123000'));
		$this->service = new ProgressService($this->progressMapper, $this->bookMapper, $time);
	}

	private function locator(float $total = 0.5): array {
		return ['href' => 'ch1.xhtml', 'locations' => ['progression' => 0.1, 'totalProgression' => $total, 'cfi' => 'epubcfi(/6/2)']];
	}

	private function stored(int $clientTs, float $pct): Progress {
		$p = new Progress();
		$p->setUserId('u');
		$p->setFileId(7);
		$p->setLocator(json_encode($this->locator($pct)));
		$p->setPercentage($pct);
		$p->setClientUpdatedAt($clientTs);
		$p->setUpdatedAt(1);
		return $p;
	}

	private function book(bool $manual = false, string $status = 'unread'): Book {
		$b = new Book();
		$b->setUserId('u');
		$b->setFileId(7);
		$b->setReadStatus($status);
		$b->setReadStatusManual($manual);
		return $b;
	}

	public function testInsertsNewProgress(): void {
		$this->progressMapper->method('findByUserAndFile')->willThrowException(new DoesNotExistException(''));
		$this->progressMapper->expects($this->once())->method('insert')->willReturnArgument(0);
		$this->bookMapper->method('findByUserAndFile')->willThrowException(new DoesNotExistException(''));

		$r = $this->service->put('u', 7, $this->locator(), 0.5, 'phone', 1000);
		$this->assertSame('ok', $r['status']);
		$this->assertSame(1000, $r['progress']->getClientUpdatedAt());
		$this->assertSame(self::NOW_MS, $r['progress']->getUpdatedAt());
	}

	public function testNewerClientWins(): void {
		$this->progressMapper->method('findByUserAndFile')->willReturn($this->stored(1000, 0.2));
		$this->progressMapper->expects($this->once())->method('update')->willReturnArgument(0);
		$this->bookMapper->method('findByUserAndFile')->willThrowException(new DoesNotExistException(''));
		$r = $this->service->put('u', 7, $this->locator(0.4), 0.4, null, 2000);
		$this->assertSame('ok', $r['status']);
		$this->assertSame(0.4, $r['progress']->getPercentage());
	}

	public function testOlderClientConflicts(): void {
		$this->progressMapper->method('findByUserAndFile')->willReturn($this->stored(3000, 0.2));
		$this->progressMapper->expects($this->never())->method('update');
		$r = $this->service->put('u', 7, $this->locator(0.9), 0.9, null, 2000);
		$this->assertSame('conflict', $r['status']);
		$this->assertSame(3000, $r['progress']->getClientUpdatedAt());
	}

	public function testTieHigherPercentageWins(): void {
		$this->progressMapper->method('findByUserAndFile')->willReturn($this->stored(2000, 0.5));
		$this->progressMapper->method('update')->willReturnArgument(0);
		$this->bookMapper->method('findByUserAndFile')->willThrowException(new DoesNotExistException(''));
		$this->assertSame('ok', $this->service->put('u', 7, $this->locator(0.6), 0.6, null, 2000)['status']);
		$this->assertSame('conflict', $this->service->put('u', 7, $this->locator(0.4), 0.4, null, 2000)['status']);
	}

	public function testFutureTimestampIsClamped(): void {
		$this->progressMapper->method('findByUserAndFile')->willThrowException(new DoesNotExistException(''));
		$this->progressMapper->method('insert')->willReturnArgument(0);
		$this->bookMapper->method('findByUserAndFile')->willThrowException(new DoesNotExistException(''));
		$r = $this->service->put('u', 7, $this->locator(), 0.5, null, self::NOW_MS + 6 * 60 * 1000);
		$this->assertSame(self::NOW_MS, $r['progress']->getClientUpdatedAt());
		$r = $this->service->put('u', 7, $this->locator(), 0.5, null, self::NOW_MS + 4 * 60 * 1000);
		$this->assertSame(self::NOW_MS + 240000, $r['progress']->getClientUpdatedAt());
	}

	#[DataProvider('readStatusProvider')]
	public function testReadStatus(float $pct, bool $manual, string $before, string $expected, bool $expectedManual): void {
		$this->progressMapper->method('findByUserAndFile')->willThrowException(new DoesNotExistException(''));
		$this->progressMapper->method('insert')->willReturnArgument(0);
		$book = $this->book($manual, $before);
		$this->bookMapper->method('findByUserAndFile')->willReturn($book);
		$book->setUpdatedAt(1);
		$this->service->put('u', 7, $this->locator($pct), $pct, null, 1000);
		$this->assertSame($expected, $book->getReadStatus());
		$this->assertSame($expectedManual, $book->getReadStatusManual());
		$this->assertSame($expected !== $before ? self::NOW_MS : 1, $book->getUpdatedAt());
	}

	public static function readStatusProvider(): array {
		return [
			'reading' => [0.3, false, 'unread', 'reading', false],
			'finished' => [0.98, false, 'reading', 'finished', false],
			'zero stays unread' => [0.0, false, 'unread', 'unread', false],
			'back to zero is unread' => [0.0, false, 'reading', 'unread', false],
			'below 1 % of finished is reading' => [0.5, false, 'finished', 'reading', false],
			'progress overrides manual unread' => [0.99, true, 'unread', 'finished', false],
			'progress overrides manual finished' => [0.2, true, 'finished', 'reading', false],
			'zero overrides manual finished' => [0.0, true, 'finished', 'unread', false],
			'manual reading survives zero' => [0.0, true, 'reading', 'reading', true],
			'manual reading ends with progress' => [0.4, true, 'reading', 'reading', false],
		];
	}

	public function testConflictDoesNotChangeStatus(): void {
		$this->progressMapper->method('findByUserAndFile')->willReturn($this->stored(3000, 0.5));
		$this->bookMapper->expects($this->never())->method('findByUserAndFile');
		$this->bookMapper->expects($this->never())->method('update');
		$this->service->put('u', 7, $this->locator(1.0), 1.0, null, 2000);
	}

	public function testFinishedUpdatesExistingRow(): void {
		$row = $this->stored(self::NOW_MS + 60_000, 0.4);
		$this->progressMapper->method('findByUserAndFile')->willReturn($row);
		$this->progressMapper->expects($this->once())->method('update')->willReturnArgument(0);
		$this->progressMapper->expects($this->never())->method('insert');

		$p = $this->service->applyReadStatus('u', 7, 'finished');

		$this->assertSame($row, $p);
		$this->assertSame(1.0, $row->getPercentage());
		$this->assertSame(['href' => '', 'locations' => ['totalProgression' => 1]], $row->getLocatorArray());
		// a client clock slightly ahead must not win over the explicit status change
		$this->assertSame(self::NOW_MS + 60_001, $row->getClientUpdatedAt());
		$this->assertSame(self::NOW_MS, $row->getUpdatedAt());
		$this->assertNull($row->getDevice());
	}

	public function testFinishedCreatesRow(): void {
		$this->progressMapper->method('findByUserAndFile')->willThrowException(new DoesNotExistException(''));
		$this->progressMapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$p = $this->service->applyReadStatus('u', 7, 'finished');

		$this->assertNotNull($p);
		$this->assertSame('u', $p->getUserId());
		$this->assertSame(7, $p->getFileId());
		$this->assertSame(1.0, $p->getPercentage());
		$this->assertSame(self::NOW_MS, $p->getClientUpdatedAt());
		$this->assertSame(self::NOW_MS, $p->getUpdatedAt());
	}

	public function testUnreadResetsExistingRowToStart(): void {
		$row = $this->stored(1000, 0.7);
		$this->progressMapper->method('findByUserAndFile')->willReturn($row);
		$this->progressMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$this->service->applyReadStatus('u', 7, 'unread');

		$this->assertSame(0.0, $row->getPercentage());
		$this->assertSame(['href' => '', 'locations' => ['position' => 1, 'totalProgression' => 0]], $row->getLocatorArray());
		$this->assertSame(self::NOW_MS, $row->getClientUpdatedAt());
		$this->assertSame(self::NOW_MS, $row->getUpdatedAt());
	}

	public function testUnreadWithoutRowCreatesNothing(): void {
		$this->progressMapper->method('findByUserAndFile')->willThrowException(new DoesNotExistException(''));
		$this->progressMapper->expects($this->never())->method('insert');
		$this->assertNull($this->service->applyReadStatus('u', 7, 'unread'));
	}

	public function testReadingAndConsistentRowsStayUntouched(): void {
		$row = $this->stored(1000, 0.4);
		$done = $this->stored(1000, 1.0);
		$this->progressMapper->method('findByUserAndFile')->willReturnOnConsecutiveCalls($row, $done);
		$this->progressMapper->expects($this->never())->method('update');
		$this->assertSame($row, $this->service->applyReadStatus('u', 7, 'reading'));
		$this->assertSame($done, $this->service->applyReadStatus('u', 7, 'finished'));
		$this->assertSame(1, $done->getUpdatedAt());
	}

	public function testLocatorWithEmptyHrefNeedsTotalProgression(): void {
		$this->progressMapper->method('findByUserAndFile')->willThrowException(new DoesNotExistException(''));
		$this->progressMapper->method('insert')->willReturnArgument(0);
		$this->bookMapper->method('findByUserAndFile')->willThrowException(new DoesNotExistException(''));
		$r = $this->service->put('u', 7, ['href' => '', 'locations' => ['totalProgression' => 1]], 1.0, null, 1000);
		$this->assertSame('ok', $r['status']);
	}

	#[DataProvider('invalidLocatorProvider')]
	public function testInvalidLocatorRejected(array $locator): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->put('u', 7, $locator, 0.5, null, 1000);
	}

	public static function invalidLocatorProvider(): array {
		return [
			'no href' => [['locations' => []]],
			'empty href without total' => [['href' => '', 'locations' => ['position' => 1]]],
			'href not string' => [['href' => 5]],
			'progression > 1' => [['href' => 'a', 'locations' => ['progression' => 1.5]]],
			'negative total' => [['href' => 'a', 'locations' => ['totalProgression' => -0.1]]],
			'locations list' => [['href' => 'a', 'locations' => [1, 2]]],
			'position float' => [['href' => 'a', 'locations' => ['position' => 1.5]]],
			'too big' => [['href' => 'a', 'title' => str_repeat('x', 5000)]],
		];
	}

	public function testInvalidPercentageRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->put('u', 7, $this->locator(), 1.5, null, 1000);
	}

	public function testRemapAfterEdit(): void {
		$loc = static fn (string $href, float $total): string => json_encode([
			'href' => $href,
			'locations' => ['cfi' => 'x', 'progression' => 0.2, 'totalProgression' => $total],
		]);
		$moved = $this->stored(1000, 0.4);
		$moved->setLocator($loc('old.xhtml#f', 0.4));
		$removed = $this->stored(1000, 0.5);
		$removed->setLocator($loc('gone.xhtml', 0.5));
		$untouched = $this->stored(1000, 0.6);
		$this->progressMapper->method('findByFileId')->willReturn([$moved, $removed, $untouched]);
		$this->progressMapper->expects($this->exactly(2))->method('update');

		$this->service->remapAfterEdit(7, ['old.xhtml' => 'new.xhtml', 'gone.xhtml' => null]);

		$this->assertSame(['href' => 'new.xhtml', 'locations' => ['progression' => 0.2, 'totalProgression' => 0.4]], $moved->getLocatorArray());
		$this->assertSame(['href' => '', 'locations' => ['totalProgression' => 0.5]], $removed->getLocatorArray());
		$this->assertSame(self::NOW_MS, $moved->getUpdatedAt());
		$this->assertSame(1, $untouched->getUpdatedAt());
	}

	public function testRemapAfterEditRemapsTheAnnotationsOfAllUsersToo(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(\DateTimeImmutable::createFromFormat('U.u', '1800000000.123000'));
		$annotationMapper = $this->createMock(AnnotationMapper::class);
		$service = new ProgressService($this->progressMapper, $this->bookMapper, $time, $annotationMapper);
		$this->progressMapper->method('findByFileId')->willReturn([]);

		$annotation = static function (string $user, string $href): Annotation {
			$a = new Annotation();
			$a->setUserId($user);
			$a->setFileId(7);
			$a->setLocator((string)json_encode(['href' => $href, 'locations' => ['cfi' => 'epubcfi(/6/4)', 'progression' => 0.2, 'totalProgression' => 0.4]]));
			$a->setClientUpdatedAt(555);
			$a->setUpdatedAt(1);
			return $a;
		};
		$alice = $annotation('alice', 'old.xhtml#f');
		$bob = $annotation('bob', 'old.xhtml');
		$removed = $annotation('bob', 'gone.xhtml');
		$untouched = $annotation('alice', 'other.xhtml');
		$annotationMapper->method('findLiveByFileId')->with(7)->willReturn([$alice, $bob, $removed, $untouched]);
		$annotationMapper->expects($this->exactly(3))->method('update');

		$service->remapAfterEdit(7, ['old.xhtml' => 'new.xhtml', 'gone.xhtml' => null]);

		$this->assertSame(['href' => 'new.xhtml', 'locations' => ['progression' => 0.2, 'totalProgression' => 0.4]], $alice->getLocatorArray(), 'same href mapping and CFI handling as progress');
		$this->assertSame('new.xhtml', $bob->getLocatorArray()['href']);
		$this->assertSame(['href' => '', 'locations' => ['totalProgression' => 0.4]], $removed->getLocatorArray());
		$this->assertSame(self::NOW_MS, $alice->getUpdatedAt(), '/sync delivers the remapped annotation');
		$this->assertSame(555, $alice->getClientUpdatedAt());
		$this->assertSame(1, $untouched->getUpdatedAt());
		$this->assertSame('other.xhtml', $untouched->getLocatorArray()['href']);
	}
}
