<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/** Owner: W1. Periodically re-scans all users' library folders. */
class RescanJob extends TimedJob {
	public function __construct(ITimeFactory $time) {
		parent::__construct($time);
		$this->setInterval(6 * 60 * 60);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		throw new \RuntimeException('Not implemented: W1');
	}
}
