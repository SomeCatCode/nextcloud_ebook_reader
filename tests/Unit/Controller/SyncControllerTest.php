<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\SyncController;
use OCA\EbookReader\Db\Annotation;
use OCA\EbookReader\Db\AnnotationMapper;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Progress;
use OCA\EbookReader\Db\ProgressMapper;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Http\SyncCursor;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SyncControllerTest extends TestCase {
	private BookMapper&MockObject $books;
	private ProgressMapper&MockObject $progress;
	private BookSerializer&MockObject $serializer;
	private AnnotationMapper&MockObject $annotations;
	private SyncController $controller;

	protected function setUp(): void {
		$this->books = $this->createMock(BookMapper::class);
		$this->progress = $this->createMock(ProgressMapper::class);
		$this->serializer = $this->createMock(BookSerializer::class);
		$this->annotations = $this->createMock(AnnotationMapper::class);
		$this->serializer->method('serializeMany')->willReturnCallback(
			static fn (string $u, array $books): array => array_map(static fn (Book $b): array => ['fileId' => $b->getFileId()], $books)
		);
		$this->controller = new SyncController($this->createMock(IRequest::class), 'u', $this->books, $this->progress, $this->serializer, $this->annotations);
	}

	private function book(int $id, int $fileId, int $updatedAt, ?int $deletedAt = null): Book {
		$b = new Book();
		$b->setId($id);
		$b->setFileId($fileId);
		$b->setUpdatedAt($updatedAt);
		$b->setDeletedAt($deletedAt);
		return $b;
	}

	private function prog(int $id, int $fileId, int $updatedAt): Progress {
		$p = new Progress();
		$p->setId($id);
		$p->setFileId($fileId);
		$p->setUpdatedAt($updatedAt);
		$p->setLocator('{"href":"a"}');
		return $p;
	}

	public function testSplitsDeletedAndAdvancesCursor(): void {
		$this->books->expects($this->once())->method('findChangedSince')->with('u', 0, 0, 501)->willReturn([
			$this->book(1, 10, 100),
			$this->book(2, 11, 100, 100),
			$this->book(3, 12, 150),
		]);
		$this->progress->method('findChangedSince')->with('u', 0, 0, 501)->willReturn([$this->prog(9, 10, 120)]);

		$data = $this->controller->sync('')->getData();

		$this->assertSame([['fileId' => 10], ['fileId' => 12]], $data['books']);
		$this->assertSame([11], $data['deleted']);
		$this->assertCount(1, $data['progress']);
		$this->assertFalse($data['hasMore']);
		$cursor = SyncCursor::decode($data['cursor']);
		$this->assertSame([150, 3], $cursor->books);
		$this->assertSame([120, 9], $cursor->progress);
	}

	public function testCursorIsPassedAsStrictTupleAndKeptWhenEmpty(): void {
		$cursor = (new SyncCursor([150, 3], [120, 9]))->encode();
		$this->books->expects($this->once())->method('findChangedSince')->with('u', 150, 3, 501)->willReturn([]);
		$this->progress->expects($this->once())->method('findChangedSince')->with('u', 120, 9, 501)->willReturn([]);

		$data = $this->controller->sync($cursor)->getData();
		$this->assertSame($cursor, $data['cursor']);
		$this->assertSame([], $data['books']);
	}

	public function testCapsAt500AndReportsHasMore(): void {
		$rows = [];
		for ($i = 1; $i <= 501; $i++) {
			$rows[] = $this->book($i, 1000 + $i, 500);
		}
		$this->books->method('findChangedSince')->willReturn($rows);
		$this->progress->method('findChangedSince')->willReturn([]);

		$data = $this->controller->sync('')->getData();
		$this->assertCount(500, $data['books']);
		$this->assertTrue($data['hasMore']);
		$this->assertSame([500, 500], SyncCursor::decode($data['cursor'])->books);
	}

	public function testAnnotationsIncludeTombstonesAndAdvanceTheirOwnCursor(): void {
		$live = new Annotation();
		$live->setId(4);
		$live->setUuid('11111111-1111-4111-8111-111111111111');
		$live->setUpdatedAt(300);
		$gone = new Annotation();
		$gone->setId(5);
		$gone->setUuid('22222222-2222-4222-8222-222222222222');
		$gone->setUpdatedAt(310);
		$gone->setDeleted(1);
		$this->books->method('findChangedSince')->willReturn([]);
		$this->progress->method('findChangedSince')->willReturn([]);
		$this->annotations->expects($this->once())->method('findChangedSince')->with('u', 7, 3, 501)->willReturn([$live, $gone]);

		$data = $this->controller->sync((new SyncCursor([0, 0], [0, 0], [7, 3]))->encode())->getData();

		$this->assertCount(2, $data['annotations']);
		$this->assertFalse($data['annotations'][0]['deleted']);
		$this->assertTrue($data['annotations'][1]['deleted']);
		$this->assertSame([310, 5], SyncCursor::decode($data['cursor'])->annotations);
		$this->assertFalse($data['hasMore']);
	}

	public function testAnnotationOverflowReportsHasMore(): void {
		$rows = [];
		for ($i = 1; $i <= 501; $i++) {
			$a = new Annotation();
			$a->setId($i);
			$a->setUpdatedAt(1000);
			$rows[] = $a;
		}
		$this->books->method('findChangedSince')->willReturn([]);
		$this->progress->method('findChangedSince')->willReturn([]);
		$this->annotations->method('findChangedSince')->willReturn($rows);
		$data = $this->controller->sync('')->getData();
		$this->assertCount(500, $data['annotations']);
		$this->assertTrue($data['hasMore']);
		$this->assertSame([1000, 500], SyncCursor::decode($data['cursor'])->annotations);
	}

	public function testInvalidCursorIsBadRequest(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->controller->sync('%%%');
	}
}
