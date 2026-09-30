<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCP\Files\SimpleFS\ISimpleFile;

/** Owner: W1 */
class CoverService {
	/** Stores small (200px) and large (600px) JPEGs, returns the etag. */
	public function storeCover(int $fileId, string $imageData): string {
		throw new \RuntimeException('Not implemented: W1');
	}

	/** @param string $size small|large */
	public function getCover(int $fileId, string $size): ?ISimpleFile {
		throw new \RuntimeException('Not implemented: W1');
	}

	public function deleteCover(int $fileId): void {
		throw new \RuntimeException('Not implemented: W1');
	}
}
