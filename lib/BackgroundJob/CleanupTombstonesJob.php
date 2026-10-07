<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\BackgroundJob;

use OCA\EbookReader\Db\AnnotationMapper;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\ShelfBookMapper;
use OCA\EbookReader\Db\ShelfMapper;
use OCA\EbookReader\Service\ArchiveCache;
use OCA\EbookReader\Service\TaskService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Removes tombstone rows (deleted_at) older than 30 days (with the shelf assignments of those books, and their annotations only
 * if the file itself is gone: a book that merely became inaccessible, e.g. a revoked share, keeps the annotations of the user, like
 * the reading progress), annotations of purged books whose file is gone, annotation tombstones
 * older than 90 days and comic pages from the page cache
 * (see ComicController) that were not generated within the last 30 days. Also deletes finished tasks older than
 * 24 hours (and fails dead running ones) and trims the local archive cache to its limit.
 */
class CleanupTombstonesJob extends TimedJob {
	public const RETENTION_DAYS = 30;
	public const ANNOTATION_RETENTION_DAYS = 90;

	/** @var array<int, bool> file id => still exists (per run) */
	private array $existsCache = [];

	public function __construct(
		private ITimeFactory $timeFactory,
		private BookMapper $bookMapper,
		private IAppData $appData,
		private LoggerInterface $logger,
		private TaskService $tasks,
		private ArchiveCache $archiveCache,
		private ShelfMapper $shelfMapper,
		private ShelfBookMapper $shelfBookMapper,
		private AnnotationMapper $annotationMapper,
		private IRootFolder $rootFolder,
	) {
		parent::__construct($timeFactory);
		$this->setInterval(24 * 60 * 60);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		$this->existsCache = [];
		$cutoff = $this->timeFactory->getTime() - self::RETENTION_DAYS * 86400;
		try {
			$this->cleanupShelfAssignments($cutoff * 1000);
		} catch (\Throwable $e) {
			$this->logger->warning('Shelf cleanup failed: ' . $e->getMessage(), ['app' => 'ebookreader']);
		}
		try {
			$this->sweepOrphanedAnnotations();
		} catch (\Throwable $e) {
			$this->logger->warning('Annotation sweep failed: ' . $e->getMessage(), ['app' => 'ebookreader']);
		}
		try {
			$this->annotationMapper->deleteTombstonesOlderThan(($this->timeFactory->getTime() - self::ANNOTATION_RETENTION_DAYS * 86400) * 1000);
		} catch (\Throwable $e) {
			$this->logger->warning('Annotation tombstone cleanup failed: ' . $e->getMessage(), ['app' => 'ebookreader']);
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
	 * Drops the shelf assignments and, for deleted files, the annotations of the tombstones that are about to be purged
	 * (tombstones can come back until then, so they are kept as long as the row exists). Annotations of files that still
	 * exist stay: the user lost access (share revoked, folder unmounted) and gets the highlights back with the share.
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
				$gone = array_values(array_filter($fileIds, fn (int $id): bool => !$this->fileExists($id)));
				if ($gone !== []) {
					$this->annotationMapper->deleteByUserAndFiles((string)$userId, $gone);
				}
				$shelfIds = $this->shelfMapper->findIdsByUser((string)$userId);
				$this->shelfBookMapper->deleteFilesFromShelves($shelfIds, $fileIds);
			}
		} while (count($batch) >= 1000);
	}

	/**
	 * Annotations of books whose row was purged earlier while the file still existed (revoked share): they are deleted once the
	 * file is gone for good, because no tombstone row is left that would trigger it.
	 */
	private function sweepOrphanedAnnotations(): void {
		$afterId = 0;
		do {
			$batch = $this->annotationMapper->findWithoutBook($afterId, 1000);
			$byUser = [];
			foreach ($batch as $row) {
				$afterId = max($afterId, $row['id']);
				if (!$this->fileExists($row['file_id'])) {
					$byUser[$row['user_id']][$row['file_id']] = $row['file_id'];
				}
			}
			foreach ($byUser as $userId => $fileIds) {
				$this->annotationMapper->deleteByUserAndFiles((string)$userId, array_values($fileIds));
			}
		} while (count($batch) >= 1000);
	}

	/**
	 * Whether the file still exists in somebody's files (the trash bin does not count). Unknown = exists: annotations are only
	 * deleted when the file is certainly gone.
	 */
	private function fileExists(int $fileId): bool {
		if (isset($this->existsCache[$fileId])) {
			return $this->existsCache[$fileId];
		}
		try {
			$exists = false;
			foreach ($this->rootFolder->getById($fileId) as $node) {
				if (preg_match('#^/[^/]+/files(/|$)#', $node->getPath()) === 1) {
					$exists = true;
					break;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Cannot check file ' . $fileId . ': ' . $e->getMessage(), ['app' => 'ebookreader']);
			$exists = true;
		}
		return $this->existsCache[$fileId] = $exists;
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
