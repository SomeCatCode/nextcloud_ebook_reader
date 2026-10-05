<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\BooksController;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\ProgressService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BooksControllerTest extends TestCase {
	private LibraryService&MockObject $library;
	private BookMapper&MockObject $mapper;
	private BookSerializer&MockObject $serializer;
	private IRequest&MockObject $request;
	private ProgressService&MockObject $progress;
	private BooksController $controller;

	protected function setUp(): void {
		$this->library = $this->createMock(LibraryService::class);
		$this->mapper = $this->createMock(BookMapper::class);
		$this->serializer = $this->createMock(BookSerializer::class);
		$this->serializer->method('serializeWithProgress')->willReturn(['fileId' => 5]);
		$this->request = $this->createMock(IRequest::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(\DateTimeImmutable::createFromFormat('U.u', '1800000000.500000'));
		$this->progress = $this->createMock(ProgressService::class);
		$this->controller = new BooksController($this->request, 'u', $this->library, $this->mapper, $this->serializer, $time, $this->progress);
	}

	private function book(): Book {
		$b = new Book();
		$b->setFileId(5);
		$b->setUpdatedAt(1);
		return $b;
	}

	public function testDeleteCallsServiceAndReturnsId(): void {
		$this->library->method('getBook')->willReturn($this->book());
		$this->library->expects($this->once())->method('deleteFileForUser')->with('u', 5);
		$this->assertSame(['deleted' => 5], $this->controller->destroy(5)->getData());
	}

	public function testDeleteWithoutPermissionIs403(): void {
		$this->library->method('getBook')->willReturn($this->book());
		$this->library->method('deleteFileForUser')->willThrowException(new \OCP\Files\NotPermittedException());
		$this->expectException(\OCP\AppFramework\OCS\OCSForbiddenException::class);
		$this->controller->destroy(5);
	}

	public function testBulkDeleteReportsPerFileResults(): void {
		$this->library->method('getBook')->willReturnCallback(function (string $u, int $id): Book {
			if ($id === 3) {
				throw new DoesNotExistException('');
			}
			return $this->book();
		});
		$this->library->method('deleteFileForUser')->willReturnCallback(function (string $u, int $id): void {
			if ($id === 2) {
				throw new \OCP\Files\NotPermittedException();
			}
		});
		$data = $this->controller->destroyMany([1, 2, 3, 1])->getData();
		$this->assertSame([1], $data['deleted']);
		$this->assertSame([['fileId' => 2, 'error' => 'forbidden'], ['fileId' => 3, 'error' => 'not_found']], $data['failed']);
	}

	public function testBulkDeleteRejectsTooManyFiles(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->controller->destroyMany(range(1, 101));
	}

	public function testShowUnknownBookIs404(): void {
		$this->library->method('getBook')->willThrowException(new DoesNotExistException(''));
		$this->expectException(OCSNotFoundException::class);
		$this->controller->show(5);
	}

	public function testPatchRatingAndStatus(): void {
		$book = $this->book();
		$this->library->method('getBook')->willReturn($book);
		$this->request->method('getParams')->willReturn(['rating' => 4, 'readStatus' => 'finished']);
		$this->mapper->expects($this->once())->method('update');
		$this->progress->expects($this->once())->method('applyReadStatus')->with('u', 5, 'finished');

		$this->controller->patchAppData(5, 4, 'finished');

		$this->assertSame(4, $book->getRating());
		$this->assertSame('finished', $book->getReadStatus());
		$this->assertTrue($book->getReadStatusManual());
		$this->assertSame(1800000000500, $book->getUpdatedAt());
	}

	public function testPatchStatusReturnsBookSerializedAfterProgressChange(): void {
		$book = $this->book();
		$this->library->method('getBook')->willReturn($book);
		$this->request->method('getParams')->willReturn(['readStatus' => 'unread']);
		$order = [];
		$this->progress->method('applyReadStatus')->willReturnCallback(function () use (&$order): null {
			$order[] = 'progress';
			return null;
		});
		$serializer = $this->createMock(BookSerializer::class);
		$serializer->method('serializeWithProgress')->willReturnCallback(function () use (&$order): array {
			$order[] = 'serialize';
			return ['fileId' => 5, 'progress' => ['percentage' => 0.0]];
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new \DateTimeImmutable());
		$controller = new BooksController($this->request, 'u', $this->library, $this->mapper, $serializer, $time, $this->progress);

		$data = $controller->patchAppData(5, null, 'unread')->getData();

		$this->assertSame(['progress', 'serialize'], $order);
		$this->assertSame(0.0, $data['progress']['percentage']);
		$this->assertSame('unread', $book->getReadStatus());
	}

	public function testPatchRatingOnlyKeepsProgress(): void {
		$this->library->method('getBook')->willReturn($this->book());
		$this->request->method('getParams')->willReturn(['rating' => 2]);
		$this->progress->expects($this->never())->method('applyReadStatus');
		$this->controller->patchAppData(5, 2, null);
	}

	public function testPatchNullRatingClears(): void {
		$book = $this->book();
		$book->setRating(3);
		$this->library->method('getBook')->willReturn($book);
		$this->request->method('getParams')->willReturn(['rating' => null]);
		$this->controller->patchAppData(5, null, null);
		$this->assertNull($book->getRating());
	}

	public function testPatchWithoutFieldsChangesNothing(): void {
		$this->library->method('getBook')->willReturn($this->book());
		$this->request->method('getParams')->willReturn([]);
		$this->mapper->expects($this->never())->method('update');
		$this->controller->patchAppData(5, null, null);
	}

	public function testPatchRejectsBadRating(): void {
		$this->library->method('getBook')->willReturn($this->book());
		$this->request->method('getParams')->willReturn(['rating' => 6]);
		$this->expectException(OCSBadRequestException::class);
		$this->controller->patchAppData(5, 6, null);
	}

	public function testPatchRejectsBadStatus(): void {
		$this->library->method('getBook')->willReturn($this->book());
		$this->request->method('getParams')->willReturn(['readStatus' => 'done']);
		$this->progress->expects($this->never())->method('applyReadStatus');
		$this->expectException(OCSBadRequestException::class);
		$this->controller->patchAppData(5, null, 'done');
	}

	public function testIndexForwardsIncludeExcludeAndMatch(): void {
		$this->library->expects($this->once())->method('findBooks')
			->with('u', $this->callback(static fn (\OCA\EbookReader\Service\BookQuery $q): bool => $q->include === [['type' => 'genre', 'name' => 'Krimi']]
				&& $q->exclude === [['type' => 'tag', 'name' => 'Horror']] && $q->match === 'any'))
			->willReturn(['books' => [], 'total' => 0]);
		$this->serializer->method('serializeMany')->willReturn([]);
		$this->controller->index(include: ['genre:Krimi'], exclude: 'tag:Horror', match: 'any');
	}
}
