<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\BackgroundJob;

use OCA\EbookReader\Service\EditorService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Writes the metadata stored in the library into the book file (once, even after several quick edits).
 * Argument: ['userId' => string, 'fileId' => int]. Identical arguments are deduplicated by the job list.
 */
class WriteMetadataJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private EditorService $editor,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * The job argument; always built here so that add/has/remove see byte-identical arguments.
	 * @return array{userId: string, fileId: int}
	 */
	public static function argument(string $userId, int $fileId): array {
		return ['userId' => $userId, 'fileId' => $fileId];
	}

	/** @param array{userId?: string, fileId?: int} $argument */
	#[\Override]
	protected function run($argument): void {
		$userId = $argument['userId'] ?? null;
		$fileId = $argument['fileId'] ?? null;
		if (!is_string($userId) || !is_int($fileId)) {
			return;
		}
		try {
			$this->editor->writePendingMetadata($userId, $fileId);
		} catch (\Throwable $e) {
			$this->logger->warning('WriteMetadataJob failed for file ' . $fileId . ': ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
		}
	}
}
