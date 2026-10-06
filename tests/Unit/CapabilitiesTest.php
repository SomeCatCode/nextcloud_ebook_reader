<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit;

use OCA\EbookReader\Capabilities;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

class CapabilitiesTest extends TestCase {
	public function testReportsInstalledAppVersion(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->with('ebookreader')->willReturn('0.8.0');

		$caps = (new Capabilities($appManager))->getCapabilities()['ebookreader'];

		$this->assertSame('0.8.0', $caps['version']);
		$this->assertSame(1, $caps['apiVersion']);
		$this->assertContains('cbr', $caps['formats']);
	}
}
