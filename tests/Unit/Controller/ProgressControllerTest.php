<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\ProgressController;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Progress;
use OCA\EbookReader\Db\ProgressMapper;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\ProgressService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProgressControllerTest extends TestCase {
	private ProgressService&MockObject $service;
	private LibraryService&MockObject $library;
	private ProgressController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(ProgressService::class);
		$this->library = $this->createMock(LibraryService::class);
		$this->controller = new ProgressController(
			$this->createMock(IRequest::class),
			'u',
			$this->service,
			$this->createMock(ProgressMapper::class),
			$this->createMock(BookMapper::class),
			$this->library,
			$this->createMock(BookSerializer::class),
		);
	}

	private function progress(): Progress {
		$p = new Progress();
		$p->setFileId(5);
		$p->setLocator('{"href":"a"}');
		$p->setPercentage(0.5);
		$p->setClientUpdatedAt(10);
		return $p;
	}

	public function testGetMissingIsOkWithNull(): void {
		$this->service->method('get')->willReturn(null);
		$r = $this->controller->show(5);
		$this->assertSame(Http::STATUS_OK, $r->getStatus());
		$this->assertNull($r->getData());
	}

	public function testPutOk(): void {
		$this->service->method('put')->willReturn(['status' => 'ok', 'progress' => $this->progress()]);
		$r = $this->controller->put(5, ['href' => 'a'], 0.5, 10);
		$this->assertSame(Http::STATUS_OK, $r->getStatus());
		$this->assertSame(5, $r->getData()['fileId']);
	}

	public function testPutConflictIs409WithCurrent(): void {
		$this->service->method('put')->willReturn(['status' => 'conflict', 'progress' => $this->progress()]);
		$r = $this->controller->put(5, ['href' => 'a'], 0.5, 5);
		$this->assertSame(Http::STATUS_CONFLICT, $r->getStatus());
		$this->assertSame(10, $r->getData()['current']['clientUpdatedAt']);
	}

	public function testPutInaccessibleFileIs404(): void {
		$this->library->method('getFileForUser')->willThrowException(new NotFoundException());
		$this->service->expects($this->never())->method('put');
		$this->expectException(OCSNotFoundException::class);
		$this->controller->put(5, ['href' => 'a'], 0.5, 10);
	}

	public function testPutInvalidLocatorIs400(): void {
		$this->service->method('put')->willThrowException(new \InvalidArgumentException('bad'));
		$this->expectException(OCSBadRequestException::class);
		$this->controller->put(5, [], 0.5, 10);
	}

	public function testBatchCollectsPerItemResults(): void {
		$this->library->method('getFileForUser')->willReturnCallback(function (string $u, int $fileId) {
			if ($fileId === 6) {
				throw new NotFoundException();
			}
			return $this->createMock(\OCP\Files\File::class);
		});
		$this->service->method('put')->willReturnCallback(function (string $u, int $fileId) {
			if ($fileId === 7) {
				throw new \InvalidArgumentException('bad locator');
			}
			return ['status' => $fileId === 8 ? 'conflict' : 'ok', 'progress' => $this->progress()];
		});
		$item = static fn (int $id): array => ['fileId' => $id, 'locator' => ['href' => 'a'], 'percentage' => 0.5, 'clientUpdatedAt' => 10];

		$data = $this->controller->batch([$item(5), $item(6), $item(7), $item(8)])->getData();

		$this->assertSame(['ok', 'error', 'error', 'conflict'], array_column($data['results'], 'status'));
		$this->assertSame([5, 6, 7, 8], array_column($data['results'], 'fileId'));
	}

	public function testBatchRejectsMalformedAndOversized(): void {
		try {
			$this->controller->batch([['fileId' => 1]]);
			$this->fail('expected exception');
		} catch (OCSBadRequestException) {
			$this->addToAssertionCount(1);
		}
		$this->expectException(OCSBadRequestException::class);
		$this->controller->batch(array_fill(0, 101, ['fileId' => 1]));
	}

	public function testRecentSkipsFinishedAndUnstartedBooks(): void {
		$rows = [];
		foreach ([1 => 0.5, 2 => 0.0, 3 => 0.99, 4 => 0.1] as $id => $pct) {
			$p = new Progress();
			$p->setFileId($id);
			$p->setPercentage($pct);
			$rows[] = $p;
		}
		$progressMapper = $this->createMock(ProgressMapper::class);
		$progressMapper->method('findRecent')->willReturn($rows);
		$books = [];
		foreach ([1 => 'reading', 2 => 'unread', 3 => 'finished', 4 => 'reading'] as $id => $status) {
			$b = new \OCA\EbookReader\Db\Book();
			$b->setFileId($id);
			$b->setReadStatus($status);
			$books[] = $b;
		}
		$bookMapper = $this->createMock(BookMapper::class);
		$bookMapper->method('findByUserAndFiles')->willReturn($books);
		$serializer = $this->createMock(BookSerializer::class);
		$serializer->expects($this->once())->method('serializeMany')
			->with('u', $this->callback(static fn (array $list): bool => array_map(static fn ($b): int => $b->getFileId(), $list) === [1, 4]))
			->willReturn([]);
		$controller = new ProgressController($this->createMock(IRequest::class), 'u', $this->service, $progressMapper, $bookMapper, $this->library, $serializer);
		$controller->recent(10);
	}

	public function testRecentOffersTheNextVolumeOfAFinishedSeries(): void {
		$rows = [];
		foreach ([1 => 1.0, 4 => 0.3] as $id => $pct) {
			$p = new Progress();
			$p->setFileId($id);
			$p->setPercentage($pct);
			$rows[] = $p;
		}
		$progressMapper = $this->createMock(ProgressMapper::class);
		$progressMapper->method('findRecent')->willReturn($rows);
		$mk = static function (int $id, string $status, ?string $series, ?float $index): Book {
			$b = new Book();
			$b->setFileId($id);
			$b->setReadStatus($status);
			$b->setSeries($series);
			$b->setSeriesIndex($index);
			return $b;
		};
		$v1 = $mk(1, 'finished', 'Saga', 1.0);
		$v2 = $mk(2, 'unread', 'Saga', 2.0);
		$other = $mk(4, 'reading', null, null);
		$bookMapper = $this->createMock(BookMapper::class);
		$bookMapper->method('findByUserAndFiles')->willReturn([$v1, $other]);
		$this->library->expects($this->once())->method('volumesOfSeries')->with('u', ['Saga'])->willReturn(['saga' => [$v1, $v2]]);
		$serializer = $this->createMock(BookSerializer::class);
		$serializer->method('serializeMany')->willReturnCallback(static fn (string $u, array $list): array => array_map(static fn (Book $b): array => ['fileId' => $b->getFileId()], $list));
		$controller = new ProgressController($this->createMock(IRequest::class), 'u', $this->service, $progressMapper, $bookMapper, $this->library, $serializer);

		$data = $controller->recent(10)->getData();

		$this->assertSame([['fileId' => 4]], $data['books']);
		$this->assertSame([['previousFileId' => 1, 'book' => ['fileId' => 2]]], $data['upNext']);
	}
}
