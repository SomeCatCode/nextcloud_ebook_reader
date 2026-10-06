<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\BackgroundJob;

use OCA\EbookReader\Service\ShareService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Argument: ['shelfShareId' => int]. Finishes the sync of a shared shelf whose book changes were too many for the request.
 */
class SyncShelfShareJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private ShareService $sharing,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/** @param array{shelfShareId?: int} $argument */
	#[\Override]
	protected function run($argument): void {
		$id = $argument['shelfShareId'] ?? null;
		if (!is_int($id)) {
			return;
		}
		try {
			$this->sharing->syncById($id);
		} catch (\Throwable $e) {
			$this->logger->warning('Shelf share sync failed for share ' . $id . ': ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
		}
	}
}
