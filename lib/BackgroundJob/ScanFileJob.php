<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/** Owner: W1. Argument: ['userId' => string, 'fileId' => int] */
class ScanFileJob extends QueuedJob {
	public function __construct(ITimeFactory $time) {
		parent::__construct($time);
	}

	/** @param array{userId?: string, fileId?: int} $argument */
	protected function run($argument): void {
		throw new \RuntimeException('Not implemented: W1');
	}
}
