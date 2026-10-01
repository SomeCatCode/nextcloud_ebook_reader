<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\BackgroundJob;

use OCA\EbookReader\Service\TaskService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Fallback runner of an asynchronous edit/convert task. Does nothing if the task was already taken (inline run
 * after the response) - TaskService::run() claims queued -> running atomically.
 * Argument: ['taskId' => int].
 */
class RunTaskJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private TaskService $tasks,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/** @return array{taskId: int} */
	public static function argument(int $taskId): array {
		return ['taskId' => $taskId];
	}

	/** @param array{taskId?: int} $argument */
	#[\Override]
	protected function run($argument): void {
		$taskId = $argument['taskId'] ?? null;
		if (!is_int($taskId)) {
			return;
		}
		try {
			$this->tasks->run($taskId);
		} catch (\Throwable $e) {
			$this->logger->warning('RunTaskJob failed for task ' . $taskId . ': ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
		}
	}
}
