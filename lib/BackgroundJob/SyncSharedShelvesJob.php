<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\BackgroundJob;

use OCA\EbookReader\Service\ShareService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Keeps shared shelves live: smart shelves change with the library (new books, edited metadata, read status), so every
 * 15 minutes the file shares of all shelf shares are brought in line with the current books. Manual shelves are synced
 * right away on changes; the job also repairs them (books deleted or no longer shareable).
 */
class SyncSharedShelvesJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ShareService $sharing,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(15 * 60);
	}

	#[\Override]
	protected function run($argument): void {
		try {
			$this->sharing->syncAll();
		} catch (\Throwable $e) {
			$this->logger->warning('Shared shelf sync failed: ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
		}
	}
}
