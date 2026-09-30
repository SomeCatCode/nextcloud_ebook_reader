<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Listener;

use OCA\EbookReader\BackgroundJob\ScanFileJob;
use OCA\EbookReader\Metadata\MetadataService;
use OCA\EbookReader\Service\LibraryService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Owner: W1. Keeps the index incremental. Never throws into the files stack.
 * @template-implements IEventListener<Event>
 */
class FileEventListener implements IEventListener {
	/** Files above this size are indexed by a background job instead of inline. */
	public const INLINE_LIMIT = 20 * 1024 * 1024;

	public function __construct(
		private LibraryService $library,
		private MetadataService $metadata,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		try {
			if ($event instanceof NodeCreatedEvent || $event instanceof NodeWrittenEvent) {
				$this->onChanged($event->getNode(), $event instanceof NodeCreatedEvent);
			} elseif ($event instanceof NodeDeletedEvent) {
				$this->onDeleted($event->getNode());
			} elseif ($event instanceof NodeRenamedEvent) {
				$this->onRenamed($event->getSource(), $event->getTarget());
			}
		} catch (\Throwable $e) {
			$this->logger->warning('E-book index update failed: ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
		}
	}

	private function onChanged(Node $node, bool $created): void {
		$path = self::pathOf($node);
		if ($path === null) {
			return;
		}
		$uid = $path[0];
		if ($node instanceof Folder) {
			if ($created && $this->library->isInLibrary($uid, $node)) {
				$this->jobList->add(ScanFileJob::class, ['userId' => $uid, 'fileId' => $node->getId()]);
			}
			return;
		}
		if ($node instanceof File) {
			$this->indexNode($uid, $node);
		}
	}

	private function indexNode(string $uid, File $file): void {
		if ($this->metadata->detectFormat($file->getName(), $file->getMimeType()) === null) {
			return;
		}
		if (!$this->library->isInLibrary($uid, $file)) {
			return;
		}
		$fileId = $file->getId();
		if ($file->getSize() > self::INLINE_LIMIT) {
			$this->jobList->add(ScanFileJob::class, ['userId' => $uid, 'fileId' => $fileId]);
			return;
		}
		try {
			$this->library->indexFile($uid, $file);
		} catch (\Throwable $e) {
			$this->logger->info('Inline indexing failed, queueing job: ' . $e->getMessage(), ['app' => 'ebookreader']);
			$this->jobList->add(ScanFileJob::class, ['userId' => $uid, 'fileId' => $fileId]);
		}
	}

	private function onDeleted(Node $node): void {
		if ($node instanceof Folder) {
			$path = self::pathOf($node);
			if ($path !== null) {
				$this->library->moveFolder($path[0], $path[1], null);
			}
			return;
		}
		$this->library->removeFileForAllUsers($node->getId());
	}

	private function onRenamed(Node $source, Node $target): void {
		$src = self::pathOf($source);
		$dst = self::pathOf($target);
		if ($target instanceof Folder) {
			if ($src !== null) {
				$newPrefix = $dst !== null && $dst[0] === $src[0] ? $dst[1] : null;
				$this->library->moveFolder($src[0], $src[1], $newPrefix);
			}
			if ($dst !== null && $this->library->isInLibrary($dst[0], $target)) {
				$this->jobList->add(ScanFileJob::class, ['userId' => $dst[0], 'fileId' => $target->getId()]);
			}
			return;
		}
		if (!$target instanceof File) {
			return;
		}
		$format = $this->metadata->detectFormat($target->getName(), $target->getMimeType());
		$stays = $dst !== null && $format !== null && $this->library->isInLibrary($dst[0], $target);
		if ($stays) {
			$this->indexNode($dst[0], $target);
		}
		if ($src !== null && (!$stays || $dst[0] !== $src[0])) {
			$this->library->removeFile($src[0], $target->getId());
		}
	}

	/** @return ?array{0: string, 1: string} user id and user-relative path */
	private static function pathOf(Node $node): ?array {
		if (preg_match('#^/([^/]+)/files(/.*)?$#', $node->getPath(), $m) !== 1) {
			return null;
		}
		return [$m[1], $m[2] ?? '/'];
	}
}
