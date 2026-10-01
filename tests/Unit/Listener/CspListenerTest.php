<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Listener;

use OCA\EbookReader\Listener\CspListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CspListenerTest extends TestCase {
	/** @return array<string, array{string, bool}> */
	public static function paths(): array {
		return [
			'app root' => ['/apps/ebookreader', true],
			'app route' => ['/apps/ebookreader/read/12', true],
			'app prefix lookalike' => ['/apps/ebookreader-evil', false],
			'files' => ['/apps/files', true],
			'files slash' => ['/apps/files/', true],
			'files sub' => ['/apps/files/files/3', true],
			'files lookalike' => ['/apps/files_sharing', false],
			'files lookalike 2' => ['/apps/files2', false],
			'f route' => ['/f/123', true],
			'f lookalike' => ['/foo', false],
			'other' => ['/apps/viewer', false],
		];
	}

	#[DataProvider('paths')]
	public function testMatchesPathWithBoundary(string $path, bool $expected): void {
		$all = ['/apps/ebookreader', '/apps/files', '/f'];
		$this->assertSame($expected, CspListener::matchesPath($path, $all));
	}
}
