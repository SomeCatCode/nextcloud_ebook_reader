<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\BackgroundJob;

use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\ShelfBookMapper;
use OCA\EbookReader\Db\ShelfMapper;
use OCA\EbookReader\Service\ArchiveCache;
use OCA\EbookReader\Service\TaskService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Removes tombstone rows (deleted_at) older than 30 days and comic pages from the page cache
 * (see ComicController) that were not generated within the last 30 days. Also deletes finished tasks older than
 * 24 hours (and fails dead running ones) and trims the local archive cache to its limit.
 */
class CleanupTombstonesJob extends TimedJob {
	public const RETENTION_DAYS = 30;

	public function __construct(
		private ITimeFactory $timeFactory,
		private BookMapper $bookMapper,
		private IAppData $appData,
		private LoggerInterface $logger,
		private TaskService $tasks,
		private ArchiveCache $archiveCache,
		private ShelfMapper $shelfMapper,
		private ShelfBookMapper $shelfBookMapper,
	) {
		parent::__construct($timeFactory);
		$this->setInterval(24 * 60 * 60);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		$cutoff = $this->timeFactory->getTime() - self::RETENTION_DAYS * 86400;
		try {
			$this->cleanupShelfAssignments($cutoff * 1000);
		} catch (\Throwable $e) {
			$this->logger->warning('Shelf cleanup failed: ' . $e->getMessage(), ['app' => 'ebookreader']);
		}
		$this->bookMapper->deleteTombstonesOlderThan($cutoff * 1000);
		$this->cleanupComicPages($cutoff);
		try {
			$this->tasks->cleanup();
		} catch (\Throwable $e) {
			$this->logger->warning('Task cleanup failed: ' . $e->getMessage(), ['app' => 'ebookreader']);
		}
		try {
			$this->archiveCache->cleanup();
		} catch (\Throwable $e) {
			$this->logger->warning('Archive cache cleanup failed: ' . $e->getMessage(), ['app' => 'ebookreader']);
		}
	}

	/**
	 * Drops the shelf assignments of the tombstones that are about to be purged (tombstones can come back until then,
	 * so the assignments are kept as long as the row exists).
	 */
	private function cleanupShelfAssignments(int $cutoffMs): void {
		$afterId = 0;
		do {
			$batch = $this->bookMapper->findTombstonesOlderThan($cutoffMs, $afterId, 1000);
			$byUser = [];
			foreach ($batch as $row) {
				$byUser[$row['user_id']][] = $row['file_id'];
				$afterId = max($afterId, $row['id']);
			}
			foreach ($byUser as $userId => $fileIds) {
				$shelfIds = $this->shelfMapper->findIdsByUser((string)$userId);
				$this->shelfBookMapper->deleteFilesFromShelves($shelfIds, $fileIds);
			}
		} while (count($batch) >= 1000);
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
