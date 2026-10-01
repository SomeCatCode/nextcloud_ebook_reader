<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\ProgressController;
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
}
