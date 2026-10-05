<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Listener;

use OCA\EbookReader\Db\AnnotationMapper;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\ProgressMapper;
use OCA\EbookReader\Db\ShelfBookMapper;
use OCA\EbookReader\Db\ShelfMapper;
use OCA\EbookReader\Db\TagMapper;
use OCA\EbookReader\Db\TaskMapper;
use OCA\EbookReader\Service\CoverService;
use OCA\EbookReader\Service\ShareService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Owner: W1. Deletes books, tags, shelves, progress, annotations and orphaned covers of a deleted user.
 * @template-implements IEventListener<Event>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private BookMapper $bookMapper,
		private TagMapper $tagMapper,
		private ProgressMapper $progressMapper,
		private TaskMapper $taskMapper,
		private CoverService $covers,
		private IAppData $appData,
		private LoggerInterface $logger,
		private ShelfMapper $shelfMapper,
		private ShelfBookMapper $shelfBookMapper,
		private AnnotationMapper $annotationMapper,
		private ?ShareService $sharing = null,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$userId = $event->getUid();
		$fileIds = [];
		$bookIds = [];
		$this->step($userId, 'collect books', function () use ($userId, &$fileIds, &$bookIds): void {
			$fileIds = $this->bookMapper->findDistinctFileIdsByUser($userId);
			$bookIds = array_map(static fn ($b): int => $b->getId(), $this->bookMapper->findAllByUser($userId));
		});
		if ($bookIds !== []) {
			$this->step($userId, 'delete tags', fn () => $this->tagMapper->deleteByBooks($bookIds));
		}
		$this->step($userId, 'delete books', fn () => $this->bookMapper->deleteByUser($userId));
		$this->step($userId, 'delete progress', fn () => $this->progressMapper->deleteByUser($userId));
		$this->step($userId, 'delete annotations', fn () => $this->annotationMapper->deleteByUser($userId));
		$this->step($userId, 'delete tasks', fn () => $this->taskMapper->deleteByUser($userId));
		if ($this->sharing !== null) {
			$this->step($userId, 'delete share records', fn () => $this->sharing?->deleteAllForUser($userId));
		}
		$this->step($userId, 'delete shelves', function () use ($userId): void {
			$ids = $this->shelfMapper->findIdsByUser($userId);
			if ($ids !== []) {
				$this->shelfBookMapper->deleteByShelves($ids);
			}
			$this->shelfMapper->deleteByUser($userId);
		});

		// Covers and comic page caches are shared between users of the same file: only drop them when nobody else has an active row.
		$orphans = [];
		foreach ($fileIds as $fileId) {
			$this->step($userId, 'check file ' . $fileId, function () use ($fileId, &$orphans): void {
				if ($this->bookMapper->countActiveByFileId($fileId) === 0) {
					$orphans[] = $fileId;
				}
			});
		}
		foreach ($orphans as $fileId) {
			$this->step($userId, 'delete cover of file ' . $fileId, fn () => $this->covers->deleteCover($fileId));
		}
		if ($orphans !== []) {
			$this->step($userId, 'delete comic page cache', fn () => $this->deleteComicPages($orphans));
		}
	}

	/** @param list<int> $fileIds */
	private function deleteComicPages(array $fileIds): void {
		try {
			$folder = $this->appData->getFolder('comic-pages');
		} catch (NotFoundException) {
			return;
		}
		$prefixes = array_map(static fn (int $id): string => $id . '-', $fileIds);
		foreach ($folder->getDirectoryListing() as $entry) {
			$name = $entry->getName();
			foreach ($prefixes as $prefix) {
				if (str_starts_with($name, $prefix)) {
					try {
						$entry->delete();
					} catch (\Throwable $e) {
						$this->logger->debug('Cannot delete comic page cache ' . $name . ': ' . $e->getMessage(), ['app' => 'ebookreader']);
					}
					break;
				}
			}
		}
	}

	private function step(string $userId, string $what, callable $fn): void {
		try {
			$fn();
		} catch (\Throwable $e) {
			$this->logger->error('Cleanup step "' . $what . '" failed for deleted user ' . $userId . ': ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
		}
	}
}
