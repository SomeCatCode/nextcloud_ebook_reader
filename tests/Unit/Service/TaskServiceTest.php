<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\BackgroundJob\RunTaskJob;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\Task;
use OCA\EbookReader\Db\TaskMapper;
use OCA\EbookReader\Editor\EditConflictException;
use OCA\EbookReader\Editor\EditForbiddenException;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Service\ConvertException;
use OCA\EbookReader\Service\ConvertService;
use OCA\EbookReader\Service\EditorService;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\TaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/DoctrineStubs.php';

class TaskServiceTest extends TestCase {
	private TaskMapper&MockObject $mapper;
	private EditorService&MockObject $editor;
	private ConvertService&MockObject $convert;
	private LibraryService&MockObject $library;
	private BookSerializer&MockObject $serializer;
	private IJobList&MockObject $jobList;
	private IAppConfig&MockObject $appConfig;
	private TaskService $service;
	private bool $inline = true;

	protected function setUp(): void {
		$this->mapper = $this->createMock(TaskMapper::class);
		$this->editor = $this->createMock(EditorService::class);
		$this->convert = $this->createMock(ConvertService::class);
		$this->library = $this->createMock(LibraryService::class);
		$this->library->method('getFileForUser')->willReturn($this->createMock(File::class));
		$this->library->method('canReadContent')->willReturn(true);
		$this->serializer = $this->createMock(BookSerializer::class);
		$this->serializer->method('serializeWithProgress')->willReturn(['fileId' => 5]);
		$this->jobList = $this->createMock(IJobList::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueBool')->willReturnCallback(fn (): bool => $this->inline);
		$this->service = new TaskService($this->mapper, $this->editor, $this->convert, $this->library, $this->serializer, $this->jobList, $this->appConfig, $this->createMock(LoggerInterface::class));
	}

	private function task(string $type = Task::TYPE_EDIT, array $request = ['etag' => 'e1']): Task {
		$t = new Task();
		$t->setId(11);
		$t->setUserId('u');
		$t->setFileId(5);
		$t->setType($type);
		$t->setStatus(Task::STATUS_QUEUED);
		$t->setRequest((string)json_encode($request));
		return $t;
	}

	public function testCreateInsertsQueuedTaskAndQueuesTheFallbackJob(): void {
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(function (Task $t): Task {
			$this->assertSame('u', $t->getUserId());
			$this->assertSame(5, $t->getFileId());
			$this->assertSame('edit', $t->getType());
			$this->assertSame(Task::STATUS_QUEUED, $t->getStatus());
			$this->assertSame(0.0, $t->getProgress());
			$this->assertSame(['etag' => 'e1'], $t->getRequestArray());
			$this->assertGreaterThan(0, $t->getCreatedAt());
			$t->setId(11);
			return $t;
		});
		$this->jobList->expects($this->once())->method('add')->with(RunTaskJob::class, ['taskId' => 11]);
		$task = $this->service->create('u', 5, 'edit', ['etag' => 'e1']);
		$this->assertSame(11, $task->getId());
	}

	public function testGetOnlyReturnsOwnTasks(): void {
		$this->mapper->method('findByUserAndId')->willReturnCallback(function (string $user, int $id): Task {
			if ($user !== 'u') {
				throw new DoesNotExistException('x');
			}
			return $this->task();
		});
		$this->assertSame(11, $this->service->get('u', 11)->getId());
		$this->expectException(DoesNotExistException::class);
		$this->service->get('mallory', 11);
	}

	public function testRunEditLifecycleDone(): void {
		$task = $this->task();
		$this->mapper->method('findById')->willReturn($task);
		$this->mapper->expects($this->once())->method('claim')->with(11)->willReturn(true);
		$book = new Book();
		$this->editor->expects($this->once())->method('save')
			->with('u', 5, ['etag' => 'e1'], $this->isCallable())
			->willReturnCallback(function ($u, $f, $r, callable $progress) use ($book): array {
				$progress(0.5, 'Writing page 1 of 2');
				return ['book' => $book, 'warnings' => ['w']];
			});
		$this->mapper->expects($this->once())->method('updateProgress')->with(11, 0.5, 'Writing page 1 of 2');
		$this->mapper->expects($this->once())->method('finish')->with(
			11,
			Task::STATUS_DONE,
			$this->callback(function (string $json): bool {
				$d = json_decode($json, true);
				return $d['warnings'] === ['w'] && $d['book']['fileId'] === 5;
			}),
			null,
			$this->anything(),
		);
		$this->service->run(11);
	}

	public function testRunConvertLifecycleDone(): void {
		$this->mapper->method('findById')->willReturn($this->task(Task::TYPE_CONVERT, ['target' => 'CBZ', 'deleteOriginal' => true]));
		$this->mapper->method('claim')->willReturn(true);
		$book = new Book();
		$book->setFileId(8);
		$book->setPath('/Books/x.cbz');
		$this->convert->expects($this->once())->method('convert')
			->with('u', 5, 'cbz', true, $this->isCallable())
			->willReturn(['book' => $book, 'fileId' => 8, 'path' => '/Books/x.cbz']);
		$this->mapper->expects($this->once())->method('finish')->with(
			11,
			Task::STATUS_DONE,
			$this->callback(fn (string $j): bool => (json_decode($j, true)['fileId'] ?? null) === 8),
			null,
			$this->anything(),
		);
		$this->service->run(11);
	}

	public function testClaimLostMeansNothingRuns(): void {
		$this->mapper->method('findById')->willReturn($this->task());
		$this->mapper->method('claim')->willReturn(false);
		$this->editor->expects($this->never())->method('save');
		$this->mapper->expects($this->never())->method('finish');
		$this->service->run(11);
	}

	public function testOnlyOneOfTwoRunnersExecutesTheTask(): void {
		// the mapper hands out the claim exactly once, like the atomic UPDATE ... WHERE status = 'queued'
		$claimed = false;
		$this->mapper->method('findById')->willReturn($this->task());
		$this->mapper->method('claim')->willReturnCallback(function () use (&$claimed): bool {
			if ($claimed) {
				return false;
			}
			$claimed = true;
			return true;
		});
		$this->editor->expects($this->once())->method('save')->willReturn(['book' => new Book(), 'warnings' => []]);
		$this->mapper->expects($this->once())->method('finish');
		$this->service->run(11); // inline run after the response
		$this->service->run(11); // RunTaskJob later
	}

	public function testUnknownTaskIsIgnored(): void {
		$this->mapper->method('findById')->willThrowException(new DoesNotExistException('x'));
		$this->mapper->expects($this->never())->method('claim');
		$this->service->run(99);
	}

	public function testConflictIsStoredAsFailedWithCode409(): void {
		$this->mapper->method('findById')->willReturn($this->task());
		$this->mapper->method('claim')->willReturn(true);
		$this->editor->method('save')->willThrowException(new EditConflictException());
		$this->mapper->expects($this->once())->method('finish')->with(
			11,
			Task::STATUS_FAILED,
			'{"code":409}',
			$this->stringContains('changed'),
			$this->anything(),
		);
		$this->service->run(11);
	}

	public function testForbiddenAndConvertErrorsKeepTheirCodes(): void {
		$this->mapper->method('findById')->willReturnOnConsecutiveCalls($this->task(), $this->task(Task::TYPE_CONVERT, ['target' => 'cbz']));
		$this->mapper->method('claim')->willReturn(true);
		$this->editor->method('save')->willThrowException(new EditForbiddenException());
		$this->convert->method('convert')->willThrowException(new ConvertException('exists', 409));
		$seen = [];
		$this->mapper->method('finish')->willReturnCallback(function (int $id, string $status, ?string $result, ?string $error) use (&$seen): void {
			$seen[] = [$status, $result, $error];
		});
		$this->service->run(11);
		$this->service->run(11);
		$this->assertSame(Task::STATUS_FAILED, $seen[0][0]);
		$this->assertSame('{"code":403}', $seen[0][1]);
		$this->assertSame([Task::STATUS_FAILED, '{"code":409}', 'exists'], $seen[1]);
	}

	public function testUnexpectedThrowableBecomesGenericFailure(): void {
		$this->mapper->method('findById')->willReturn($this->task());
		$this->mapper->method('claim')->willReturn(true);
		$this->editor->method('save')->willThrowException(new \Error('boom with secrets /var/www'));
		$this->mapper->expects($this->once())->method('finish')->with(11, Task::STATUS_FAILED, '{"code":500}', 'Internal error', $this->anything());
		$this->service->run(11);
	}

	public function testViewOnlyShareFailsTheTaskWith403(): void {
		$library = $this->createMock(LibraryService::class);
		$library->method('getFileForUser')->willReturn($this->createMock(File::class));
		$library->method('canReadContent')->willReturn(false);
		$service = new TaskService($this->mapper, $this->editor, $this->convert, $library, $this->serializer, $this->jobList, $this->appConfig, $this->createMock(LoggerInterface::class));
		$this->mapper->method('findById')->willReturn($this->task());
		$this->mapper->method('claim')->willReturn(true);
		$this->editor->expects($this->never())->method('save');
		$this->mapper->expects($this->once())->method('finish')->with(11, Task::STATUS_FAILED, '{"code":403}', $this->anything(), $this->anything());
		$service->run(11);
	}

	public function testProgressWritesAreThrottledToOncePerSecond(): void {
		$this->mapper->expects($this->once())->method('updateProgress');
		$cb = $this->service->progressCallback(11);
		for ($i = 0; $i < 500; $i++) {
			$cb($i / 500, 'Writing page ' . $i);
		}
	}

	public function testProgressFailureDoesNotAbortTheTask(): void {
		$this->mapper->method('updateProgress')->willThrowException(new \RuntimeException('db down'));
		$cb = $this->service->progressCallback(11);
		$cb(0.1, 'x');
		$this->addToAssertionCount(1);
	}

	public function testWithoutFastcgiTheTaskWaitsForTheBackgroundJob(): void {
		if (function_exists('fastcgi_finish_request')) {
			$this->markTestSkipped('fastcgi_finish_request exists in this SAPI');
		}
		$this->mapper->expects($this->once())->method('updateQueuedStep')->with(11, TaskService::STEP_WAITING);
		$this->assertFalse($this->service->scheduleInline($this->task()));
	}

	public function testInlineCanBeDisabledByConfig(): void {
		$this->inline = false;
		$this->mapper->expects($this->once())->method('updateQueuedStep')->with(11, TaskService::STEP_WAITING);
		$this->assertFalse($this->service->scheduleInline($this->task()));
	}

	public function testCleanupDeletesOldFinishedTasksAndFailsDeadOnes(): void {
		$this->mapper->expects($this->once())->method('deleteFinishedOlderThan')->with($this->callback(
			fn (int $cutoff): bool => abs($cutoff - (microtime(true) * 1000 - 24 * 3600 * 1000)) < 5000,
		));
		$this->mapper->expects($this->once())->method('failStale');
		$this->service->cleanup();
	}

	public function testMapperClaimIsAConditionalUpdate(): void {
		// mapper level: UPDATE ... WHERE id = ? AND status = 'queued'; only an affected row count of 1 wins
		$params = [];
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $col, $val): string => $col . '=' . (string)$val);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('where')->willReturnCallback(function (string $w) use ($qb, &$params): IQueryBuilder {
			$params[] = $w;
			return $qb;
		});
		$qb->method('andWhere')->willReturnCallback(function (string $w) use ($qb, &$params): IQueryBuilder {
			$params[] = $w;
			return $qb;
		});
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeStatement')->willReturnOnConsecutiveCalls(1, 0);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new TaskMapper($db);
		$this->assertTrue($mapper->claim(11, 1000));
		$this->assertFalse($mapper->claim(11, 1001), 'second claim updates no row');
		$this->assertSame(['id=11', 'status=queued', 'id=11', 'status=queued'], $params);
	}
}
