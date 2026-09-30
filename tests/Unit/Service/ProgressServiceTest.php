<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

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
	public function testReadStatus(float $pct, bool $manual, string $before, string $expected): void {
		$this->progressMapper->method('findByUserAndFile')->willThrowException(new DoesNotExistException(''));
		$this->progressMapper->method('insert')->willReturnArgument(0);
		$book = $this->book($manual, $before);
		$this->bookMapper->method('findByUserAndFile')->willReturn($book);
		$this->service->put('u', 7, $this->locator($pct), $pct, null, 1000);
		$this->assertSame($expected, $book->getReadStatus());
		if ($expected !== $before) {
			$this->assertSame(self::NOW_MS, $book->getUpdatedAt());
		}
	}

	public static function readStatusProvider(): array {
		return [
			'reading' => [0.3, false, 'unread', 'reading'],
			'finished' => [0.98, false, 'reading', 'finished'],
			'manual stays' => [0.99, true, 'unread', 'unread'],
			'zero stays unread' => [0.0, false, 'unread', 'unread'],
		];
	}

	#[DataProvider('invalidLocatorProvider')]
	public function testInvalidLocatorRejected(array $locator): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->put('u', 7, $locator, 0.5, null, 1000);
	}

	public static function invalidLocatorProvider(): array {
		return [
			'no href' => [['locations' => []]],
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
}
