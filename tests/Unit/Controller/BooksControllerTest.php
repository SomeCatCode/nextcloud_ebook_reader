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

	public function testDeleteOfSharedBookIs403WithOwnerHint(): void {
		$this->library->method('getBook')->willReturn($this->book());
		$this->library->method('deleteFileForUser')->willThrowException(new \OCA\EbookReader\Service\SharedBookException('alice'));
		try {
			$this->controller->destroy(5);
			$this->fail('expected OCSForbiddenException');
		} catch (\OCP\AppFramework\OCS\OCSForbiddenException $e) {
			$this->assertStringContainsString('alice', $e->getMessage());
		}
	}

	public function testBulkDeleteReportsSharedBooks(): void {
		$this->library->method('getBook')->willReturn($this->book());
		$this->library->method('deleteFileForUser')->willThrowException(new \OCA\EbookReader\Service\SharedBookException('alice'));
		$data = $this->controller->destroyMany([7])->getData();
		$this->assertSame([], $data['deleted']);
		$this->assertSame([['fileId' => 7, 'error' => 'shared']], $data['failed']);
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

	public function testPatchCompletionAndAgeRating(): void {
		$book = $this->book();
		$book->applyFileAgeRating(12);
		$this->library->method('getBook')->willReturn($book);
		$this->request->method('getParams')->willReturn(['completion' => 'ongoing', 'ageRating' => 16]);
		$this->mapper->expects($this->once())->method('update');
		$this->progress->expects($this->never())->method('applyReadStatus');

		$this->controller->patchAppData(5, completion: 'ongoing', ageRating: 16);

		$this->assertSame('ongoing', $book->getCompletion());
		$this->assertSame(16, $book->getAgeRating());
		$this->assertTrue($book->getAgeRatingManual());
		$this->assertSame(12, $book->getAgeRatingFile());
		$this->assertSame(1800000000500, $book->getUpdatedAt());
	}

	public function testPatchExplicitNullClearsCompletionAndSetsManualNoAgeRating(): void {
		$book = $this->book();
		$book->setCompletion('completed');
		$book->applyFileAgeRating(18);
		$this->library->method('getBook')->willReturn($book);
		$this->request->method('getParams')->willReturn(['completion' => null, 'ageRating' => null]);
		$this->mapper->expects($this->once())->method('update');

		$this->controller->patchAppData(5);

		$this->assertNull($book->getCompletion());
		$this->assertNull($book->getAgeRating());
		$this->assertTrue($book->getAgeRatingManual());
	}

	public function testPatchResetAgeRatingUsesTheFileValue(): void {
		$book = $this->book();
		$book->applyFileAgeRating(6);
		$book->setManualAgeRating(18);
		$this->library->method('getBook')->willReturn($book);
		$this->request->method('getParams')->willReturn(['resetAgeRating' => true]);
		$this->mapper->expects($this->once())->method('update');

		$this->controller->patchAppData(5, resetAgeRating: true);

		$this->assertSame(6, $book->getAgeRating());
		$this->assertFalse($book->getAgeRatingManual());
	}

	public function testPatchSameCompletionDoesNotBumpUpdatedAt(): void {
		$book = $this->book();
		$book->setCompletion('ongoing');
		$this->library->method('getBook')->willReturn($book);
		$this->request->method('getParams')->willReturn(['completion' => 'ongoing']);
		$this->mapper->expects($this->never())->method('update');
		$this->controller->patchAppData(5, completion: 'ongoing');
		$this->assertSame(1, $book->getUpdatedAt());
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function badFlags(): array {
		return [
			'completion' => [['completion' => 'finished']],
			'age' => [['ageRating' => 15]],
			'age and reset' => [['ageRating' => 12, 'resetAgeRating' => true]],
		];
	}

	/** @param array<string, mixed> $params */
	#[\PHPUnit\Framework\Attributes\DataProvider('badFlags')]
	public function testPatchRejectsBadFlags(array $params): void {
		$this->library->method('getBook')->willReturn($this->book());
		$this->request->method('getParams')->willReturn($params);
		$this->mapper->expects($this->never())->method('update');
		$this->expectException(OCSBadRequestException::class);
		$this->controller->patchAppData(5, completion: $params['completion'] ?? null, ageRating: $params['ageRating'] ?? null, resetAgeRating: $params['resetAgeRating'] ?? false);
	}

	public function testBulkAppDataReportsPerFileResults(): void {
		$a = $this->book();
		$b = new Book();
		$b->setFileId(6);
		$b->setCompletion('completed');
		$this->mapper->method('findByUserAndFiles')->willReturn([$a, $b]);
		$this->request->method('getParams')->willReturn(['fileIds' => [5, 6, 7], 'completion' => 'completed']);
		$this->mapper->expects($this->once())->method('update')->with($a);

		$data = $this->controller->patchAppDataMany([5, 6, 7, 5], completion: 'completed')->getData();

		$this->assertSame(['updated' => 1, 'unchanged' => 1, 'failed' => [['fileId' => 7, 'error' => 'not_found']]], $data);
		$this->assertSame('completed', $a->getCompletion());
		$this->assertSame(1800000000500, $a->getUpdatedAt());
	}

	public function testBulkAppDataNeedsAField(): void {
		$this->request->method('getParams')->willReturn(['fileIds' => [5]]);
		$this->expectException(OCSBadRequestException::class);
		$this->controller->patchAppDataMany([5]);
	}

	public function testBulkAppDataRejectsTooManyFiles(): void {
		$this->request->method('getParams')->willReturn(['completion' => 'ongoing']);
		$this->expectException(OCSBadRequestException::class);
		$this->controller->patchAppDataMany(range(1, 501), completion: 'ongoing');
	}

	public function testNextReturnsTheNextVolumeOrNull(): void {
		$book = $this->book();
		$next = new Book();
		$next->setFileId(9);
		$this->library->method('getBook')->willReturn($book);
		$this->library->method('nextVolume')->willReturnOnConsecutiveCalls($next, null);
		$serializer = $this->createMock(BookSerializer::class);
		$serializer->method('serializeWithProgress')->willReturnCallback(static fn (string $u, Book $b): array => ['fileId' => $b->getFileId()]);
		$controller = new BooksController($this->request, 'u', $this->library, $this->mapper, $serializer, $this->createMock(ITimeFactory::class), $this->progress);

		$this->assertSame(['book' => ['fileId' => 9]], $controller->next(5)->getData());
		$this->assertSame(['book' => null], $controller->next(5)->getData());
	}
}
