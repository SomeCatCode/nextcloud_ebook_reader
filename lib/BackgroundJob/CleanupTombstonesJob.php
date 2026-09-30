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

/** Owner: W1. Removes tombstone rows (deleted_at) older than 30 days. */
class CleanupTombstonesJob extends TimedJob {
	public const RETENTION_DAYS = 30;

	public function __construct(
		private ITimeFactory $timeFactory,
		private BookMapper $bookMapper,
	) {
		parent::__construct($timeFactory);
		$this->setInterval(24 * 60 * 60);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		$cutoff = ($this->timeFactory->getTime() - self::RETENTION_DAYS * 86400) * 1000;
		$this->bookMapper->deleteTombstonesOlderThan($cutoff);
	}
}
