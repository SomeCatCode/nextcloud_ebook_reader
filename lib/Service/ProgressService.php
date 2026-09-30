<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\Db\Progress;

/** Owner: W2 */
class ProgressService {
	public function get(string $userId, int $fileId): ?Progress {
		throw new \RuntimeException('Not implemented: W2');
	}

	/**
	 * @param array<string, mixed> $locator
	 * @return array{status: string, progress: Progress} status = ok|conflict
	 */
	public function put(string $userId, int $fileId, array $locator, float $percentage, ?string $device, int $clientUpdatedAt): array {
		throw new \RuntimeException('Not implemented: W2');
	}

	/**
	 * @param array<string, ?string> $itemMap old href => new href|null
	 */
	public function remapAfterEdit(int $fileId, array $itemMap): void {
		throw new \RuntimeException('Not implemented: W2');
	}
}
