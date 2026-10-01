<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\BackgroundJob;

use OCA\EbookReader\Db\BookMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Removes tombstone rows (deleted_at) older than 30 days and comic pages from the page cache
 * (see ComicController) that were not generated within the last 30 days.
 */
class CleanupTombstonesJob extends TimedJob {
	public const RETENTION_DAYS = 30;

	public function __construct(
		private ITimeFactory $timeFactory,
		private BookMapper $bookMapper,
		private IAppData $appData,
		private LoggerInterface $logger,
	) {
		parent::__construct($timeFactory);
		$this->setInterval(24 * 60 * 60);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		$cutoff = $this->timeFactory->getTime() - self::RETENTION_DAYS * 86400;
		$this->bookMapper->deleteTombstonesOlderThan($cutoff * 1000);
		$this->cleanupComicPages($cutoff);
	}

	private function cleanupComicPages(int $cutoff): void {
		try {
			$folder = $this->appData->getFolder('comic-pages');
		} catch (NotFoundException) {
			return;
		}
		foreach ($folder->getDirectoryListing() as $file) {
			try {
				if ($file->getMTime() < $cutoff) {
					$file->delete();
				}
			} catch (\Throwable $e) {
				$this->logger->debug('Cannot remove cached comic page: ' . $e->getMessage(), ['app' => 'ebookreader']);
			}
		}
	}
}
