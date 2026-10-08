<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\BackgroundJob;

use OCA\EbookReader\Metadata\SidecarService;
use OCA\EbookReader\Service\FileOwnership;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Moves the sidecar files of a user to the place the setting "sidecarLocation" asks for ("beside" the book or in a
 * ".meta" folder per directory) after the setting changed. Argument: ['userId' => string].
 *
 * Idempotent and resumable: it reads the setting when it runs (so a quick change back and forth ends in the right
 * state), only moves sidecars that are in the other place, and queues itself again when its time budget is used up.
 * Only books the user owns are touched: the sidecar of a book in somebody else's (shared) folder lives in their files
 * and follows their setting.
 */
class MoveSidecarsJob extends QueuedJob {
	/** Seconds one run may take before it queues the rest as a new run. */
	public const BUDGET_SECONDS = 240.0;

	public function __construct(
		ITimeFactory $time,
		private LibraryService $library,
		private SettingsService $settings,
		private SidecarService $sidecar,
		private IUserManager $userManager,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * The job argument; always built here so that identical jobs are deduplicated by the job list.
	 * @return array{userId: string}
	 */
	public static function argument(string $userId): array {
		return ['userId' => $userId];
	}

	/** @param array{userId?: string} $argument */
	#[\Override]
	protected function run($argument): void {
		$userId = $argument['userId'] ?? null;
		if (!is_string($userId) || !$this->userManager->userExists($userId)) {
			return;
		}
		try {
			$this->moveAll($userId, self::BUDGET_SECONDS);
		} catch (\Throwable $e) {
			$this->logger->warning('MoveSidecarsJob failed for user ' . $userId . ': ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
		}
	}

	/**
	 * @return array{moved: int, complete: bool} how many sidecars were moved; false = the time budget ended the run early
	 *                                           (a follow-up job is queued)
	 */
	public function moveAll(string $userId, float $budgetSeconds): array {
		$layout = $this->settings->sidecarLocation($userId);
		$deadline = microtime(true) + $budgetSeconds;
		/** @var list<File> $withSidecar */
		$withSidecar = [];
		$this->library->walkLibrary($userId, static function (File $file, string $format, ?string $sidecarState = null) use (&$withSidecar): void {
			// no sidecar in either place, or not the user's own file: nothing to do
			if ($sidecarState !== null && !FileOwnership::isShared($file)) {
				$withSidecar[] = $file;
			}
		});
		$moved = 0;
		foreach ($withSidecar as $file) {
			if (microtime(true) > $deadline) {
				$this->jobList->add(self::class, self::argument($userId));
				return ['moved' => $moved, 'complete' => false];
			}
			if ($this->sidecar->relocate($file, $layout)) {
				$moved++;
			}
		}
		return ['moved' => $moved, 'complete' => true];
	}
}
