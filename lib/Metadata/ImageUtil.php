<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

/** Small image helpers. */
final class ImageUtil {
	/** Detects the mime type from the bytes; null if not a supported image. */
	public static function mime(string $data): ?string {
		if (strlen($data) < 12) {
			return null;
		}
		if (str_starts_with($data, "\xFF\xD8\xFF")) {
			return 'image/jpeg';
		}
		if (str_starts_with($data, "\x89PNG\r\n\x1A\n")) {
			return 'image/png';
		}
		if (str_starts_with($data, 'GIF87a') || str_starts_with($data, 'GIF89a')) {
			return 'image/gif';
		}
		if (str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP') {
			return 'image/webp';
		}
		return null;
	}

	/** Whether the file name has an image extension. */
	public static function isImageName(string $name): bool {
		return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
	}
}
