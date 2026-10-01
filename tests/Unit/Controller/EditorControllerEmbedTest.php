<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\EditorController;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\Task;
use OCA\EbookReader\Editor\EditorException;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Service\EditorService;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\TaskService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** POST /books/{fileId}/metadata/embed */
class EditorControllerEmbedTest extends TestCase {
	private EditorService&MockObject $editor;
	private LibraryService&MockObject $library;
	private TaskService&MockObject $tasks;
	private BookSerializer&MockObject $serializer;
	private EditorController $controller;

	protected function setUp(): void {
		$this->editor = $this->createMock(EditorService::class);
		$this->library = $this->createMock(LibraryService::class);
		$this->tasks = $this->createMock(TaskService::class);
		$this->serializer = $this->createMock(BookSerializer::class);
		$this->controller = new EditorController(
			$this->createMock(IRequest::class),
			'u',
			$this->editor,
			$this->library,
			$this->serializer,
			$this->createMock(LoggerInterface::class),
			$this->tasks,
		);
		$this->library->method('getFileForUser')->willReturn($this->createMock(File::class));
		$this->library->method('canReadContent')->willReturn(true);
	}

	public function testEmbedsSynchronously(): void {
		$this->editor->expects($this->once())->method('checkEmbed')->with('u', 5);
		$this->editor->expects($this->once())->method('embedMetadata')->with('u', 5)->willReturn(['book' => new Book(), 'warnings' => [], 'written' => true]);
		$this->serializer->method('serializeWithProgress')->willReturn(['fileId' => 5]);
		$res = $this->controller->embedMetadata(5);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertTrue($res->getData()['written']);
	}

	public function testAsyncQueuesATaskAfterTheSynchronousChecks(): void {
		$this->editor->expects($this->once())->method('checkEmbed');
		$this->editor->expects($this->never())->method('embedMetadata');
		$task = new Task();
		$task->setId(12);
		$this->tasks->expects($this->once())->method('create')->with('u', 5, Task::TYPE_EMBED, [])->willReturn($task);
		$this->tasks->expects($this->once())->method('scheduleInline')->with($task);
		$res = $this->controller->embedMetadata(5, true);
		$this->assertSame(Http::STATUS_ACCEPTED, $res->getStatus());
		$this->assertSame(['taskId' => 12], $res->getData());
	}

	public function testChecksFailBeforeAnythingIsQueued(): void {
		$this->editor->method('checkEmbed')->willThrowException(new EditorException('Format', 415));
		$this->tasks->expects($this->never())->method('create');
		$res = $this->controller->embedMetadata(5, true);
		$this->assertSame(415, $res->getStatus());
	}

	public function testViewOnlySharesAreRefused(): void {
		$library = $this->createMock(LibraryService::class);
		$library->method('getFileForUser')->willReturn($this->createMock(File::class));
		$library->method('canReadContent')->willReturn(false);
		$controller = new EditorController($this->createMock(IRequest::class), 'u', $this->editor, $library, $this->serializer, $this->createMock(LoggerInterface::class), $this->tasks);
		$this->editor->expects($this->never())->method('embedMetadata');
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->embedMetadata(5)->getStatus());
	}
}
