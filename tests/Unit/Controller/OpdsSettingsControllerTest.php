<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\OpdsSettingsController;
use OCA\EbookReader\Service\OpdsSettings;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OpdsSettingsControllerTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private IConfig&MockObject $config;
	private IGroupManager&MockObject $groups;

	private function controller(?string $user, bool $admin = false): OpdsSettingsController {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->config = $this->createMock(IConfig::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->groups->method('isAdmin')->willReturn($admin);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://nc/index.php/apps/ebookreader/opds');
		return new OpdsSettingsController(
			$this->createMock(IRequest::class),
			$user,
			new OpdsSettings($this->appConfig, $this->config, $urls),
			$this->groups,
		);
	}

	public function testDefaultsAreAllowedButOff(): void {
		$c = $this->controller('alice');
		$this->appConfig->method('getValueBool')->with('ebookreader', 'opds_enabled', true)->willReturn(true);
		$this->config->method('getUserValue')->with('alice', 'ebookreader', 'opds_enabled', '0')->willReturn('0');
		$this->assertSame(
			['enabled' => false, 'allowed' => true, 'isAdmin' => false, 'url' => 'https://nc/index.php/apps/ebookreader/opds'],
			$c->get()->getData(),
		);
	}

	public function testUserEnablesCatalogForThemself(): void {
		$c = $this->controller('alice');
		$this->config->expects($this->once())->method('setUserValue')->with('alice', 'ebookreader', 'opds_enabled', '1');
		$this->appConfig->expects($this->never())->method('setValueBool');
		$c->put(true);
	}

	public function testOnlyAdminsChangeTheGlobalSwitch(): void {
		$c = $this->controller('alice');
		$this->appConfig->expects($this->never())->method('setValueBool');
		$this->expectException(OCSForbiddenException::class);
		$c->put(null, false);
	}

	public function testAdminDisallowsCatalog(): void {
		$c = $this->controller('root', true);
		$this->appConfig->expects($this->once())->method('setValueBool')->with('ebookreader', 'opds_enabled', false);
		$c->put(null, false);
	}

	public function testNotLoggedIn(): void {
		$this->expectException(OCSForbiddenException::class);
		$this->controller(null)->get();
	}

	public function testCatalogRequiresBothSwitches(): void {
		$c = $this->controller('alice');
		$this->appConfig->method('getValueBool')->willReturnOnConsecutiveCalls(true, false, true);
		$this->config->method('getUserValue')->willReturnOnConsecutiveCalls('1', '0');
		$settings = new OpdsSettings($this->appConfig, $this->config, $this->createMock(IURLGenerator::class));
		$this->assertTrue($settings->isActiveFor('alice'));
		$this->assertFalse($settings->isActiveFor('alice'), 'admin disabled');
		$this->assertFalse($settings->isActiveFor('alice'), 'user did not enable');
		$this->assertNotNull($c);
	}
}
