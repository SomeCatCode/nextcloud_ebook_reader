<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Service\Compat;
use OCP\App\IAppManager;
use OCP\Files\IFilenameValidator;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * The shims prefer the newer server API when it exists (ocp of the CI branch decides) and fall back otherwise.
 * Both branches return a sensible value, so the assertions hold on every supported ocp version.
 */
final class CompatTest extends TestCase {
	public function testAppEnabledForAnyoneFollowsServer(): void {
		$manager = $this->createMock(IAppManager::class);
		if (method_exists($manager, 'isEnabledForAnyone')) {
			$manager->method('isEnabledForAnyone')->willReturn(true);
		} else {
			$manager->method('isInstalled')->willReturn(true);
		}
		$this->assertTrue(Compat::isAppEnabledForAnyone($manager, 'viewer'));
	}

	public function testSanitizeFilenameDelegatesOrKeepsName(): void {
		$validator = $this->createMock(IFilenameValidator::class);
		if (method_exists($validator, 'sanitizeFilename')) {
			$validator->method('sanitizeFilename')->willReturn('clean');
			$this->assertSame('clean', Compat::sanitizeFilename($validator, 'dirty'));
		} else {
			$this->assertSame('dirty', Compat::sanitizeFilename($validator, 'dirty'));
		}
	}

	public function testShareContentVisibility(): void {
		$share = $this->createMock(IShare::class);
		if (method_exists($share, 'canSeeContent')) {
			$share->method('canSeeContent')->willReturn(false);
			$this->assertFalse(Compat::canSeeShareContent($share));
		} else {
			$this->assertTrue(Compat::canSeeShareContent($share));
		}
	}
}
