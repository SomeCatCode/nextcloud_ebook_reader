<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\BackgroundJob;

use OCA\EbookReader\Service\LibraryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Owner: W1. Argument: ['userId' => string, 'fileId' => int].
 * The file id may also be a folder: then an indexing job is queued for every e-book below it.
 */
class ScanFileJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private LibraryService $library,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/** @param array{userId?: string, fileId?: int} $argument */
	#[\Override]
	protected function run($argument): void {
		$userId = $argument['userId'] ?? null;
		$fileId = $argument['fileId'] ?? null;
		if (!is_string($userId) || !is_int($fileId)) {
			return;
		}
		if (!$this->userManager->userExists($userId)) {
			return;
		}
		try {
			$node = $this->library->getNodeForUser($userId, $fileId);
		} catch (NotFoundException) {
			$this->library->removeFile($userId, $fileId);
			return;
		}
		try {
			if ($node instanceof File) {
				if ($this->library->isInLibrary($userId, $node)) {
					$this->library->indexFile($userId, $node);
				} else {
					$this->library->removeFile($userId, $fileId);
				}
			} elseif ($node instanceof Folder) {
				$this->library->queueFolder($userId, $node);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('ScanFileJob failed for file ' . $fileId . ': ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
		}
	}
}
