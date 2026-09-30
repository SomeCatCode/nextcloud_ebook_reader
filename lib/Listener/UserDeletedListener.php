<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Listener;

use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\ProgressMapper;
use OCA\EbookReader\Db\TagMapper;
use OCA\EbookReader\Service\CoverService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Owner: W1. Deletes books, tags, progress and orphaned covers of a deleted user.
 * @template-implements IEventListener<Event>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private BookMapper $bookMapper,
		private TagMapper $tagMapper,
		private ProgressMapper $progressMapper,
		private CoverService $covers,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$userId = $event->getUid();
		try {
			$fileIds = $this->bookMapper->findDistinctFileIdsByUser($userId);
			$bookIds = array_map(static fn ($b): int => $b->getId(), $this->bookMapper->findAllByUser($userId));
			if ($bookIds !== []) {
				$this->tagMapper->deleteByBooks($bookIds);
			}
			$this->bookMapper->deleteByUser($userId);
			$this->progressMapper->deleteByUser($userId);
			foreach ($fileIds as $fileId) {
				if ($this->bookMapper->countActiveByFileId($fileId) === 0) {
					$this->covers->deleteCover($fileId);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->error('Cleanup of e-book data failed for deleted user ' . $userId . ': ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
		}
	}
}
