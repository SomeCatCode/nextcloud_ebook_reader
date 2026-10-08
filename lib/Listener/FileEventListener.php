<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Listener;

use OCA\EbookReader\BackgroundJob\ScanFileJob;
use OCA\EbookReader\Metadata\MetadataService;
use OCA\EbookReader\Metadata\SidecarService;
use OCA\EbookReader\Service\LibraryService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
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
		private SidecarService $sidecar,
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
			} elseif ($event instanceof NodeCopiedEvent) {
				$this->onCopied($event->getSource(), $event->getTarget());
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
			if ($node->getName() === SidecarService::META_DIR) {
				return; // the hidden sidecar folder holds no books
			}
			if ($created && $this->library->isInLibrary($uid, $node)) {
				$this->jobList->add(ScanFileJob::class, ['userId' => $uid, 'fileId' => $node->getId()]);
			}
			return;
		}
		if ($node instanceof File) {
			$book = SidecarService::bookOf($node);
			if ($book !== null) {
				$this->onSidecarChanged($uid, $book[0], $book[1], $node->getPath());
				return;
			}
			$this->indexNode($uid, $node);
		}
	}

	/**
	 * A sidecar (beside the book or in a .meta folder) was created, written, deleted or renamed by somebody else (our
	 * own changes are guarded): the matching book is indexed again, which reads (or no longer finds) the sidecar.
	 *
	 * @param Folder $parent the folder that holds the book
	 */
	private function onSidecarChanged(string $uid, Folder $parent, string $bookName, string $sidecarPath): void {
		if (SidecarService::isGuarded($sidecarPath)) {
			return;
		}
		try {
			if (!$parent->nodeExists($bookName)) {
				return;
			}
			$book = $parent->get($bookName);
		} catch (\Throwable) {
			return;
		}
		if ($book instanceof File) {
			$this->indexNode($uid, $book);
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
			if ($node->getName() === SidecarService::META_DIR) {
				return;
			}
			$path = self::pathOf($node);
			if ($path !== null) {
				$this->library->moveFolder($path[0], $path[1], null);
			}
			return;
		}
		$sidecarOf = $node instanceof File ? SidecarService::bookOf($node) : null;
		if ($node instanceof File && $sidecarOf !== null) {
			$path = self::pathOf($node);
			if ($path !== null) {
				$this->onSidecarChanged($path[0], $sidecarOf[0], $sidecarOf[1], $node->getPath());
			}
			return;
		}
		if ($node instanceof File && $this->metadata->detectFormat($node->getName(), $node->getMimeType()) !== null) {
			// the sidecar is deleted with its book (it goes to the trash bin as well)
			$this->sidecar->deleteFor($node->getParent(), $node->getName());
		}
		$this->library->removeFileForAllUsers($node->getId());
	}

	private function onRenamed(Node $source, Node $target): void {
		$src = self::pathOf($source);
		$dst = self::pathOf($target);
		if ($target instanceof Folder) {
			if ($target->getName() === SidecarService::META_DIR) {
				return;
			}
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
		if (SidecarService::isSidecarFile($target) || ($source instanceof File && SidecarService::isSidecarFile($source))) {
			$this->onSidecarRenamed($source, $target, $src, $dst);
			return;
		}
		$format = $this->metadata->detectFormat($target->getName(), $target->getMimeType());
		if ($format !== null) {
			// the sidecar travels with the book (skipped if the target name is taken); before indexing, which reads it
			$this->sidecar->moveAlong($source->getParent(), $source->getName(), $target->getParent(), $target->getName());
		}
		$stays = $dst !== null && $format !== null && $this->library->isInLibrary($dst[0], $target);
		if ($stays) {
			$this->indexNode($dst[0], $target);
		}
		if ($src !== null && (!$stays || $dst[0] !== $src[0])) {
			$this->library->removeFile($src[0], $target->getId());
		}
	}

	/**
	 * A book was copied (Files app, WebDAV COPY): the copy gets the sidecar of the original, before it is indexed again.
	 * (A copied folder takes its sidecars with it, beside the books or in its .meta folder.)
	 */
	private function onCopied(Node $source, Node $target): void {
		if (!$source instanceof File || !$target instanceof File || SidecarService::isSidecarFile($target)) {
			return;
		}
		if ($this->metadata->detectFormat($target->getName(), $target->getMimeType()) === null) {
			return;
		}
		if (!$this->sidecar->copyAlong($source->getParent(), $source->getName(), $target->getParent(), $target->getName())) {
			return;
		}
		$dst = self::pathOf($target);
		if ($dst !== null) {
			$this->indexNode($dst[0], $target);
		}
	}

	/**
	 * A sidecar was renamed or moved by somebody else: the books at the old and the new name are indexed again.
	 *
	 * @param ?array{0: string, 1: string} $src
	 * @param ?array{0: string, 1: string} $dst
	 */
	private function onSidecarRenamed(Node $source, Node $target, ?array $src, ?array $dst): void {
		$to = $target instanceof File ? SidecarService::bookOf($target) : null;
		if ($dst !== null && $to !== null) {
			$this->onSidecarChanged($dst[0], $to[0], $to[1], $target->getPath());
		}
		$from = $source instanceof File ? SidecarService::bookOf($source) : null;
		if ($src !== null && $from !== null) {
			$this->onSidecarChanged($src[0], $from[0], $from[1], $source->getPath());
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
