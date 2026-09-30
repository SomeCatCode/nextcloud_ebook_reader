<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

require_once __DIR__ . '/../../fixtures/generate.php';

/** Locates (and, if missing, generates) the fixture books. */
final class Fixtures {
	public static function path(string $name): string {
		$dir = __DIR__ . '/../../fixtures/books';
		if (!is_file($dir . '/' . $name)) {
			\ebr_generate_fixtures($dir);
		}
		return $dir . '/' . $name;
	}

	/** Creates a temp file path that is removed at shutdown. */
	public static function temp(string $suffix = ''): string {
		$p = tempnam(sys_get_temp_dir(), 'ebrt') . $suffix;
		register_shutdown_function(static function () use ($p): void {
			@unlink($p);
		});
		return $p;
	}
}
