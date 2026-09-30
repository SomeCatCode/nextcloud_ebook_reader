<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Preview;

use OCP\Files\File;
use OCP\IImage;
use OCP\Preview\IProviderV2;

/** Owner: W1. Delivers the extracted cover as preview. */
class EbookCoverProvider implements IProviderV2 {
	public function getMimeType(): string {
		return '/^application\/(epub\+zip|x-mobipocket-ebook|vnd\.amazon\.mobi8-ebook|x-fictionbook\+xml|x-zip-compressed-fb2|vnd\.comicbook\+zip|vnd\.comicbook-rar)$/';
	}

	public function isAvailable(File $file): bool {
		return false;
	}

	public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage {
		return null;
	}
}
