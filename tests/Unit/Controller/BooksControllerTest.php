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
	private BooksController $controller;

	protected function setUp(): void {
		$this->library = $this->createMock(LibraryService::class);
		$this->mapper = $this->createMock(BookMapper::class);
		$this->serializer = $this->createMock(BookSerializer::class);
		$this->serializer->method('serializeWithProgress')->willReturn(['fileId' => 5]);
		$this->request = $this->createMock(IRequest::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(\DateTimeImmutable::createFromFormat('U.u', '1800000000.500000'));
		$this->controller = new BooksController($this->request, 'u', $this->library, $this->mapper, $this->serializer, $time);
	}

	private function book(): Book {
		$b = new Book();
		$b->setFileId(5);
		$b->setUpdatedAt(1);
		return $b;
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

		$this->controller->patchAppData(5, 4, 'finished');

		$this->assertSame(4, $book->getRating());
		$this->assertSame('finished', $book->getReadStatus());
		$this->assertTrue($book->getReadStatusManual());
		$this->assertSame(1800000000500, $book->getUpdatedAt());
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
		$this->expectException(OCSBadRequestException::class);
		$this->controller->patchAppData(5, null, 'done');
	}
}
