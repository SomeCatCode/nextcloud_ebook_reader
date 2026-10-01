<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\ConvertController;
use OCA\EbookReader\Controller\EditorController;
use OCA\EbookReader\Controller\TaskController;
use OCA\EbookReader\Db\Task;
use OCA\EbookReader\Editor\EditConflictException;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Service\ConvertException;
use OCA\EbookReader\Service\ConvertService;
use OCA\EbookReader\Service\EditorService;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\TaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TaskControllerTest extends TestCase {
	private TaskService&MockObject $tasks;

	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskService::class);
	}

	private function task(): Task {
		$t = new Task();
		$t->setId(11);
		$t->setUserId('u');
		$t->setFileId(5);
		$t->setType(Task::TYPE_EDIT);
		$t->setStatus(Task::STATUS_RUNNING);
		$t->setProgress(0.4);
		$t->setStep('Writing page 180 of 400');
		$t->setResult(null);
		$t->setCreatedAt(1000);
		$t->setUpdatedAt(2000);
		return $t;
	}

	public function testShowReturnsTheTaskOfTheUser(): void {
		$this->tasks->expects($this->once())->method('get')->with('u', 11)->willReturn($this->task());
		$res = (new TaskController($this->createMock(IRequest::class), 'u', $this->tasks))->show(11);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame([
			'id' => 11, 'fileId' => 5, 'type' => 'edit', 'status' => 'running', 'progress' => 0.4,
			'step' => 'Writing page 180 of 400', 'result' => null, 'error' => null, 'createdAt' => 1000, 'updatedAt' => 2000,
		], $res->getData());
	}

	public function testForeignTaskIs404(): void {
		$this->tasks->method('get')->willThrowException(new DoesNotExistException('x'));
		$res = (new TaskController($this->createMock(IRequest::class), 'mallory', $this->tasks))->show(11);
		$this->assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
		$this->assertArrayNotHasKey('fileId', $res->getData());
	}

	public function testActiveListsOnlyTasksOfTheUser(): void {
		$this->tasks->expects($this->once())->method('active')->with('u')->willReturn([$this->task()]);
		$res = (new TaskController($this->createMock(IRequest::class), 'u', $this->tasks))->index(true);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertCount(1, $res->getData()['tasks']);
		$this->assertSame(11, $res->getData()['tasks'][0]['id']);
	}

	public function testAsyncSaveReturns202WithTaskIdAfterValidation(): void {
		$library = $this->createMock(LibraryService::class);
		$library->method('getFileForUser')->willReturn($this->createMock(File::class));
		$library->method('canReadContent')->willReturn(true);
		$editor = $this->createMock(EditorService::class);
		$editor->expects($this->once())->method('validateSave')->with('u', 5, $this->callback(fn (array $r): bool => $r['etag'] === 'e1' && $r['saveAsCopy'] === false));
		$editor->expects($this->never())->method('save');
		$this->tasks->expects($this->once())->method('create')->with('u', 5, 'edit', $this->anything())->willReturn($this->task());
		$this->tasks->expects($this->once())->method('scheduleInline');

		$controller = new EditorController($this->createMock(IRequest::class), 'u', $editor, $library, $this->createMock(BookSerializer::class), $this->createMock(LoggerInterface::class), $this->tasks);
		$res = $controller->save(5, 'e1', false, ['title' => 'x'], null, null, null, null, true);
		$this->assertSame(Http::STATUS_ACCEPTED, $res->getStatus());
		$this->assertSame(['taskId' => 11], $res->getData());
	}

	public function testAsyncSaveConflictIsAnsweredSynchronouslyWith409(): void {
		$library = $this->createMock(LibraryService::class);
		$library->method('getFileForUser')->willReturn($this->createMock(File::class));
		$library->method('canReadContent')->willReturn(true);
		$editor = $this->createMock(EditorService::class);
		$editor->method('validateSave')->willThrowException(new EditConflictException());
		$this->tasks->expects($this->never())->method('create');

		$controller = new EditorController($this->createMock(IRequest::class), 'u', $editor, $library, $this->createMock(BookSerializer::class), $this->createMock(LoggerInterface::class), $this->tasks);
		$this->assertSame(Http::STATUS_CONFLICT, $controller->save(5, 'old', false, ['title' => 'x'], null, null, null, null, true)->getStatus());
	}

	public function testAsyncSaveOnViewOnlyShareCreatesNoTask(): void {
		$library = $this->createMock(LibraryService::class);
		$library->method('getFileForUser')->willReturn($this->createMock(File::class));
		$library->method('canReadContent')->willReturn(false);
		$this->tasks->expects($this->never())->method('create');
		$controller = new EditorController($this->createMock(IRequest::class), 'u', $this->createMock(EditorService::class), $library, $this->createMock(BookSerializer::class), $this->createMock(LoggerInterface::class), $this->tasks);
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->save(5, 'e1', false, null, null, null, null, null, true)->getStatus());
	}

	public function testAsyncConvertReturns202(): void {
		$library = $this->createMock(LibraryService::class);
		$library->method('getFileForUser')->willReturn($this->createMock(File::class));
		$library->method('canReadContent')->willReturn(true);
		$convert = $this->createMock(ConvertService::class);
		$convert->expects($this->once())->method('validate')->with('u', 5, 'epub', true);
		$convert->expects($this->never())->method('convert');
		$this->tasks->expects($this->once())->method('create')->with('u', 5, 'convert', ['target' => 'epub', 'deleteOriginal' => true])->willReturn($this->task());
		$this->tasks->expects($this->once())->method('scheduleInline');
		$controller = new ConvertController($this->createMock(IRequest::class), 'u', $convert, $library, $this->createMock(BookSerializer::class), $this->createMock(LoggerInterface::class), $this->tasks);
		$res = $controller->convertBook(5, 'EPUB', true, true);
		$this->assertSame(Http::STATUS_ACCEPTED, $res->getStatus());
		$this->assertSame(['taskId' => 11], $res->getData());
	}

	public function testAsyncConvertValidationErrorsAreSynchronous(): void {
		$library = $this->createMock(LibraryService::class);
		$library->method('getFileForUser')->willReturn($this->createMock(File::class));
		$library->method('canReadContent')->willReturn(true);
		$convert = $this->createMock(ConvertService::class);
		$convert->method('validate')->willThrowException(new ConvertException('exists', 409));
		$this->tasks->expects($this->never())->method('create');
		$controller = new ConvertController($this->createMock(IRequest::class), 'u', $convert, $library, $this->createMock(BookSerializer::class), $this->createMock(LoggerInterface::class), $this->tasks);
		$this->assertSame(Http::STATUS_CONFLICT, $controller->convertBook(5, 'cbz', false, true)->getStatus());
	}
}
