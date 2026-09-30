<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

/** Owner: W1 */
class ScannerService {
	/** Walks the configured library folders of a user and queues indexing jobs. Returns the number queued. */
	public function scanUser(string $userId): int {
		throw new \RuntimeException('Not implemented: W1');
	}
}
