<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\AppInfo\Application;
use OCA\EbookReader\BackgroundJob\RunTaskJob;
use OCA\EbookReader\Db\Task;
use OCA\EbookReader\Db\TaskMapper;
use OCA\EbookReader\Editor\EditorException;
use OCA\EbookReader\Http\BookSerializer;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Long running edits and conversions (table ebookreader_tasks).
 *
 * The controller validates the request synchronously, calls create() and answers 202. The task then runs
 *  - in the same PHP process after the response has been sent (scheduleInline(): shutdown function +
 *    fastcgi_finish_request()), or
 *  - in RunTaskJob (always queued as a fallback).
 * Both call run(); the atomic queued -> running UPDATE makes sure only one of them executes the task.
 */
class TaskService {
	public const CONFIG_ASYNC_INLINE = 'async_inline';
	/** Progress is written to the database at most this often (seconds) */
	public const PROGRESS_INTERVAL = 1.0;
	public const STEP_WAITING = 'Waiting for background job';
	/** Finished tasks are deleted after this many seconds */
	public const RETENTION_SECONDS = 24 * 3600;
	/** Running tasks without any update for this long are considered dead */
	public const STALE_SECONDS = 6 * 3600;

	public function __construct(
		private TaskMapper $mapper,
		private EditorService $editor,
		private ConvertService $convert,
		private LibraryService $library,
		private BookSerializer $serializer,
		private IJobList $jobList,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Creates a queued task and queues the RunTaskJob fallback.
	 *
	 * @param 'edit'|'convert'|'embed' $type
	 * @param array<string, mixed> $request what the task will execute (edit: EditRequest array, convert: {target, deleteOriginal})
	 */
	public function create(string $userId, int $fileId, string $type, array $request): Task {
		$now = self::nowMs();
		$task = new Task();
		$task->setUserId($userId);
		$task->setFileId($fileId);
		$task->setType($type);
		$task->setStatus(Task::STATUS_QUEUED);
		$task->setProgress(0.0);
		$task->setStep('Queued');
		$task->setRequest(json_encode($request, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}');
		$task->setCreatedAt($now);
		$task->setUpdatedAt($now);
		$task = $this->mapper->insert($task);
		$this->jobList->add(RunTaskJob::class, RunTaskJob::argument((int)$task->getId()));
		return $task;
	}

	/**
	 * @throws DoesNotExistException also for tasks of other users (the caller answers 404)
	 */
	public function get(string $userId, int $taskId): Task {
		return $this->mapper->findByUserAndId($userId, $taskId);
	}

	/** @return list<Task> */
	public function active(string $userId): array {
		return $this->mapper->findActiveByUser($userId);
	}

	/**
	 * Runs the task in this process as soon as the HTTP response has been sent. Without fastcgi_finish_request()
	 * (e.g. mod_php) the client would wait for the whole task, so nothing runs inline then and the task waits for
	 * RunTaskJob.
	 *
	 * @return bool whether an inline run was scheduled
	 */
	public function scheduleInline(Task $task): bool {
		$id = (int)$task->getId();
		if (!$this->inlineEnabled() || !$this->canFinishRequest()) {
			$this->mapper->updateQueuedStep($id, self::STEP_WAITING, self::nowMs());
			return false;
		}
		register_shutdown_function(function () use ($id): void {
			try {
				ignore_user_abort(true);
				@set_time_limit(0);
				if (function_exists('fastcgi_finish_request')) {
					fastcgi_finish_request();
				}
				$this->run($id);
			} catch (\Throwable $e) {
				$this->logger->error('Inline task run failed: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			}
		});
		return true;
	}

	/**
	 * Executes a queued task (no-op if somebody else already took it). Never throws: failures are stored in the task.
	 */
	public function run(int $taskId): void {
		try {
			$task = $this->mapper->findById($taskId);
		} catch (DoesNotExistException) {
			return;
		}
		if (!$this->mapper->claim($taskId, self::nowMs())) {
			return;
		}
		@set_time_limit(0);
		$userId = $task->getUserId();
		$fileId = $task->getFileId();
		$request = $task->getRequestArray();
		$progress = $this->progressCallback($taskId);
		try {
			$file = $this->library->getFileForUser($userId, $fileId);
			if (!$this->library->canReadContent($file)) {
				throw new EditorException('Download of this file is disabled', 403);
			}
			if ($task->getType() === Task::TYPE_EDIT) {
				$res = $this->editor->save($userId, $fileId, $request, $progress);
				$result = [
					'book' => $this->serializer->serializeWithProgress($userId, $res['book']),
					'warnings' => $res['warnings'],
				];
			} elseif ($task->getType() === Task::TYPE_EMBED) {
				$res = $this->editor->embedMetadata($userId, $fileId, $progress);
				$result = [
					'book' => $this->serializer->serializeWithProgress($userId, $res['book']),
					'warnings' => $res['warnings'],
					'written' => $res['written'],
				];
			} elseif ($task->getType() === Task::TYPE_CONVERT) {
				$res = $this->convert->convert(
					$userId,
					$fileId,
					strtolower(is_string($request['target'] ?? null) ? $request['target'] : ''),
					(bool)($request['deleteOriginal'] ?? false),
					$progress,
				);
				$result = [
					'book' => $this->serializer->serializeWithProgress($userId, $res['book']),
					'fileId' => $res['fileId'],
					'path' => $res['path'],
				];
			} else {
				throw new EditorException('Unknown task type', 400);
			}
			$this->mapper->finish($taskId, Task::STATUS_DONE, self::encode($result), null, self::nowMs());
		} catch (EditorException|ConvertException $e) {
			$this->fail($taskId, $e->getMessage(), $e->getCode());
		} catch (DoesNotExistException|NotFoundException) {
			$this->fail($taskId, 'Not found', 404);
		} catch (\Throwable $e) {
			$this->logger->error('Task ' . $taskId . ' failed: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			$this->fail($taskId, 'Internal error', 500);
		}
	}

	/**
	 * Callback for editors/converter: writes progress and step to the database at most once per second. The final state
	 * (done/failed) is written by run() itself.
	 *
	 * @return callable(float, string): void
	 */
	public function progressCallback(int $taskId): callable {
		$last = 0.0;
		return function (float $fraction, string $step) use ($taskId, &$last): void {
			$now = microtime(true);
			if ($now - $last < self::PROGRESS_INTERVAL) {
				return;
			}
			$last = $now;
			try {
				$this->mapper->updateProgress($taskId, $fraction, $step, self::nowMs());
			} catch (\Throwable $e) {
				// progress is informational only
				$this->logger->debug('Cannot store task progress: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		};
	}

	/** Deletes old finished tasks and marks dead running ones as failed (CleanupTombstonesJob). */
	public function cleanup(): void {
		$now = self::nowMs();
		$this->mapper->deleteFinishedOlderThan($now - self::RETENTION_SECONDS * 1000);
		$this->mapper->failStale($now - self::STALE_SECONDS * 1000, $now);
	}

	protected function canFinishRequest(): bool {
		return function_exists('fastcgi_finish_request');
	}

	private function inlineEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::CONFIG_ASYNC_INLINE, true);
	}

	private function fail(int $taskId, string $message, int $code): void {
		$code = $code >= 400 && $code <= 599 ? $code : 500;
		try {
			$this->mapper->finish($taskId, Task::STATUS_FAILED, self::encode(['code' => $code]), $message, self::nowMs());
		} catch (\Throwable $e) {
			$this->logger->error('Cannot store the failure of task ' . $taskId . ': ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}

	/** @param array<string, mixed> $data */
	private static function encode(array $data): string {
		return json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
	}

	private static function nowMs(): int {
		return (int)floor(microtime(true) * 1000.0);
	}
}
