<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\EditorController;
use OCA\EbookReader\Db\Task;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Service\EditorService;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\TaskService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** POST /books/bulk-metadata */
class EditorControllerBulkMetadataTest extends TestCase {
	private EditorService&MockObject $editor;
	private TaskService&MockObject $tasks;
	private EditorController $controller;
	private bool $writesFiles = false;

	protected function setUp(): void {
		$this->editor = $this->createMock(EditorService::class);
		$this->tasks = $this->createMock(TaskService::class);
		$this->editor->method('normalizeBulkMetadata')->willReturnCallback(static fn (array $b): array => array_filter($b, static fn ($v): bool => $v !== null));
		$this->editor->method('metadataTargetWritesFiles')->willReturnCallback(fn (): bool => $this->writesFiles);
		$this->controller = new EditorController(
			$this->createMock(IRequest::class),
			'u',
			$this->editor,
			$this->createMock(LibraryService::class),
			$this->createMock(BookSerializer::class),
			$this->createMock(LoggerInterface::class),
			$this->tasks,
		);
	}

	private function expectTask(): void {
		$task = new Task();
		$task->setId(21);
		$this->tasks->expects($this->once())->method('create')->with('u', 1, Task::TYPE_BULK, $this->anything())->willReturn($task);
		$this->tasks->expects($this->once())->method('scheduleInline')->with($task);
		$this->editor->expects($this->never())->method('bulkMetadata');
	}

	public function testSmallRequestRunsSynchronously(): void {
		$result = ['updated' => 2, 'unchanged' => 0, 'failed' => [], 'writeQueued' => false];
		$this->editor->expects($this->once())->method('bulkMetadata')->willReturn($result);
		$this->tasks->expects($this->never())->method('create');
		$res = $this->controller->bulkMetadata([1, 2], publisher: ['mode' => 'clear']);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame($result, $res->getData());
	}

	public function testAsyncFlagQueuesATask(): void {
		$this->expectTask();
		$res = $this->controller->bulkMetadata([1, 2], publisher: ['mode' => 'clear'], async: true);
		$this->assertSame(Http::STATUS_ACCEPTED, $res->getStatus());
		$this->assertSame(['taskId' => 21], $res->getData());
	}

	public function testMoreThan50BooksWrittenIntoFilesRunAsTask(): void {
		$this->writesFiles = true;
		$this->expectTask();
		$res = $this->controller->bulkMetadata(range(1, 51), publisher: ['mode' => 'clear']);
		$this->assertSame(Http::STATUS_ACCEPTED, $res->getStatus());
	}

	public function testFiftyBooksStaySynchronous(): void {
		$this->writesFiles = true;
		$this->editor->expects($this->once())->method('bulkMetadata')->willReturn(['updated' => 50, 'unchanged' => 0, 'failed' => [], 'writeQueued' => true]);
		$res = $this->controller->bulkMetadata(range(1, 50), publisher: ['mode' => 'clear']);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
	}

	public function testManyBooksWithSidecarTargetStaySynchronous(): void {
		$this->writesFiles = false;
		$this->editor->expects($this->once())->method('bulkMetadata')->willReturn(['updated' => 80, 'unchanged' => 0, 'failed' => [], 'writeQueued' => false]);
		$res = $this->controller->bulkMetadata(range(1, 80), publisher: ['mode' => 'clear']);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
	}
}
