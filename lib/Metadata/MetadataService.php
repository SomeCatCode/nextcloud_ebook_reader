<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

use OCP\Files\File;

/** Owner: W1 */
class MetadataService {
	/** @return ?string null = not an e-book */
	public function detectFormat(string $filename, string $mime): ?string {
		throw new \RuntimeException('Not implemented: W1');
	}

	public function extract(File $file, string $format): BookMetadata {
		throw new \RuntimeException('Not implemented: W1');
	}
}
