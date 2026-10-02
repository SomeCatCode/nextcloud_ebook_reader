<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\ConvertController;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\Task;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Service\ConvertException;
use OCA\EbookReader\Service\ConvertService;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\TaskService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Image optimization options of the convert controller: validation, forced task, estimate and bulk endpoint. */
class ConvertControllerOptimizeTest extends TestCase {
	private ConvertService&MockObject $convert;
	private LibraryService&MockObject $library;
	private TaskService&MockObject $tasks;
	private ConvertController $controller;

	protected function setUp(): void {
		$this->convert = $this->createMock(ConvertService::class);
		$this->library = $this->createMock(LibraryService::class);
		$this->tasks = $this->createMock(TaskService::class);
		$this->controller = new ConvertController(
			$this->createMock(IRequest::class),
			'u',
			$this->convert,
			$this->library,
			$this->createMock(BookSerializer::class),
			$this->createMock(LoggerInterface::class),
			$this->tasks,
		);
		$this->library->method('getFileForUser')->willReturn($this->createMock(File::class));
		$this->library->method('canReadContent')->willReturn(true);
	}

	private function task(int $id): Task {
		$t = new Task();
		$t->setId($id);
		return $t;
	}

	private function book(string $format): Book {
		$b = new Book();
		$b->setFormat($format);
		return $b;
	}

	public function testInvalidMaxHeightIsA400(): void {
		$this->tasks->expects($this->never())->method('create');
		$res = $this->controller->convertBook(1, 'cbz', false, true, ['maxHeight' => 1234]);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
	}

	public function testOptimizeAlwaysRunsAsTaskEvenWithoutAsyncFlag(): void {
		$expected = ['maxHeight' => 1920, 'jpegQuality' => 85, 'pngToJpeg' => true];
		$this->convert->expects($this->once())->method('validate')->with('u', 7, 'cbz', true, $expected);
		$this->convert->expects($this->never())->method('convert');
		$this->tasks->expects($this->once())->method('create')
			->with('u', 7, Task::TYPE_CONVERT, ['target' => 'cbz', 'deleteOriginal' => true, 'optimize' => $expected])
			->willReturn($this->task(42));
		$res = $this->controller->convertBook(7, 'cbz', true, false, ['maxHeight' => 1920, 'pngToJpeg' => true]);
		$this->assertSame(Http::STATUS_ACCEPTED, $res->getStatus());
		$this->assertSame(['taskId' => 42], $res->getData());
	}

	public function testInactiveOptimizeOptionsAreAPlainConversion(): void {
		$this->convert->expects($this->once())->method('validate')->with('u', 7, 'cbt', false, null);
		$this->tasks->expects($this->once())->method('create')
			->with('u', 7, Task::TYPE_CONVERT, ['target' => 'cbt', 'deleteOriginal' => false])
			->willReturn($this->task(1));
		$res = $this->controller->convertBook(7, 'cbt', false, true, ['maxHeight' => 0]);
		$this->assertSame(Http::STATUS_ACCEPTED, $res->getStatus());
	}

	public function testValidationFailureOfTheServiceIsReturned(): void {
		$this->convert->method('validate')->willThrowException(new ConvertException('A file with this name already exists', 409));
		$res = $this->controller->convertBook(7, 'cbz', false, true, ['maxHeight' => 2560]);
		$this->assertSame(Http::STATUS_CONFLICT, $res->getStatus());
	}

	public function testEstimateValidatesAndReturnsTheServiceResult(): void {
		$bad = $this->controller->estimate(3, 999, false);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $bad->getStatus());

		$result = ['pages' => 52, 'oversizedPages' => 37, 'currentBytes' => 180, 'estimatedBytes' => 65, 'exact' => false];
		$this->convert->expects($this->once())->method('estimateOptimize')
			->with('u', 3, ['maxHeight' => 2560, 'jpegQuality' => 85, 'pngToJpeg' => false])
			->willReturn($result);
		$res = $this->controller->estimate(3, 2560, false);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame($result, $res->getData());
	}

	public function testBulkRejectsEmptyTooManyAndInactiveRequests(): void {
		$this->tasks->expects($this->never())->method('create');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->optimizeBooks([], 1920)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->optimizeBooks(range(1, ConvertController::MAX_BULK + 1), 1920)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->optimizeBooks([1, 2], 0, false)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->optimizeBooks([1, 2], 777)->getStatus());
	}

	public function testBulkCreatesOneTaskPerBookAndReportsSkippedOnes(): void {
		$formats = [1 => 'cbz', 2 => 'cbr', 3 => 'epub'];
		$this->library->method('getBook')->willReturnCallback(fn (string $u, int $id): Book => $this->book($formats[$id]));
		$this->convert->method('validate')->willReturnCallback(function (string $u, int $id, string $target): void {
			if ($target === 'epub') {
				throw new ConvertException('Only comics can be converted', 415);
			}
		});
		$created = [];
		$this->tasks->method('create')->willReturnCallback(function (string $u, int $fileId, string $type, array $request) use (&$created): Task {
			$created[$fileId] = $request;
			return $this->task(100 + $fileId);
		});
		$res = $this->controller->optimizeBooks([1, 2, 3, 1], 2560, true, true);
		$this->assertSame(Http::STATUS_ACCEPTED, $res->getStatus());
		$this->assertSame([['fileId' => 1, 'taskId' => 101], ['fileId' => 2, 'taskId' => 102]], $res->getData()['tasks']);
		$this->assertSame(3, $res->getData()['skipped'][0]['fileId']);
		$this->assertSame(415, $res->getData()['skipped'][0]['status']);
		// CBR is written as CBZ, the format of the others stays
		$this->assertSame('cbz', $created[1]['target']);
		$this->assertSame('cbz', $created[2]['target']);
		$this->assertTrue($created[1]['deleteOriginal']);
		$this->assertSame(['maxHeight' => 2560, 'jpegQuality' => 85, 'pngToJpeg' => true], $created[1]['optimize']);
	}
}
