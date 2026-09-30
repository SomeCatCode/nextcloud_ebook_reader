<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

interface ExtractorInterface {
	/** Whether this extractor handles the given format (epub|mobi|azw3|fb2|fbz|cbz|cbr). */
	public function supports(string $format): bool;

	/** Extracts metadata from a local file path. */
	public function extract(string $localPath): BookMetadata;
}
