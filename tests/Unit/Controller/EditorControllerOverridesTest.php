<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\EditorController;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Editor\InvalidEditRequestException;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Service\EditorService;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\TaskService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** DELETE /books/{fileId}/overrides */
class EditorControllerOverridesTest extends TestCase {
	private EditorService&MockObject $editor;
	private BookSerializer&MockObject $serializer;
	private EditorController $controller;

	protected function setUp(): void {
		$this->editor = $this->createMock(EditorService::class);
		$this->serializer = $this->createMock(BookSerializer::class);
		$this->controller = new EditorController(
			$this->createMock(IRequest::class),
			'u',
			$this->editor,
			$this->createMock(LibraryService::class),
			$this->serializer,
			$this->createMock(LoggerInterface::class),
			$this->createMock(TaskService::class),
		);
	}

	public function testResetsOneFieldAndReturnsTheBook(): void {
		$book = new Book();
		$this->editor->expects($this->once())->method('resetOverrides')->with('u', 5, 'title')->willReturn($book);
		$this->serializer->method('serializeWithProgress')->willReturn(['fileId' => 5, 'overrides' => []]);
		$res = $this->controller->resetOverrides(5, 'title');
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame([], $res->getData()['overrides']);
	}

	public function testResetsAllWhenFieldIsOmitted(): void {
		$this->editor->expects($this->once())->method('resetOverrides')->with('u', 5, null)->willReturn(new Book());
		$this->serializer->method('serializeWithProgress')->willReturn(['fileId' => 5, 'overrides' => []]);
		$this->assertSame(Http::STATUS_OK, $this->controller->resetOverrides(5)->getStatus());
	}

	public function testUnknownFieldIsABadRequest(): void {
		$this->editor->method('resetOverrides')->willThrowException(new InvalidEditRequestException('Unknown field: rating'));
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->resetOverrides(5, 'rating')->getStatus());
	}
}
