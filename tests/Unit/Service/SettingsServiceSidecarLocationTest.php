<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\BackgroundJob\MoveSidecarsJob;
use OCA\EbookReader\Capabilities;
use OCA\EbookReader\Controller\SettingsController;
use OCA\EbookReader\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** The per-user setting "sidecarLocation": storage, validation and the background job that follows a change. */
class SettingsServiceSidecarLocationTest extends TestCase {
	private IJobList&MockObject $jobs;
	private ?string $stored = null;

	public static function setUpBeforeClass(): void {
		// OCP\Files\IRootFolder extends a server-internal interface that is not part of nextcloud/ocp.
		if (!interface_exists('OC\Hooks\Emitter')) {
			eval('namespace OC\Hooks; interface Emitter { public function listen($scope, $method, callable $callback); public function removeListener($scope = null, $method = null, ?callable $callback = null); }');
		}
	}

	protected function setUp(): void {
		$this->stored = null;
		$this->jobs = $this->createMock(IJobList::class);
	}

	private function service(): SettingsService {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(fn (): string => $this->stored ?? '');
		$config->method('setUserValue')->willReturnCallback(function (string $u, string $a, string $k, string $v): void {
			$this->stored = $v;
		});
		return new SettingsService($config, $this->jobs);
	}

	public function testDefaultIsBeside(): void {
		$s = $this->service();
		$this->assertSame('beside', $s->get('u')['sidecarLocation']);
		$this->assertSame('beside', $s->sidecarLocation('u'));
	}

	public function testChangeIsStoredAndStartsTheMoveJobOnce(): void {
		$this->jobs->expects($this->once())->method('add')->with(MoveSidecarsJob::class, ['userId' => 'u']);
		$s = $this->service();
		$this->assertSame('meta', $s->set('u', ['sidecarLocation' => 'meta'])['sidecarLocation']);
		$this->assertSame('meta', $s->sidecarLocation('u'));
		// the same value again changes nothing: no second job
		$s->set('u', ['sidecarLocation' => 'meta']);
	}

	public function testOtherSettingsDoNotStartTheJobAndKeepTheLocation(): void {
		$this->jobs->expects($this->once())->method('add');
		$s = $this->service();
		$s->set('u', ['sidecarLocation' => 'meta']);
		$out = $s->set('u', ['filenamePattern' => '{title}']);
		$this->assertSame('meta', $out['sidecarLocation']);
		$this->assertSame('{title}', $out['filenamePattern']);
	}

	public function testSwitchingBackStartsTheJobAgain(): void {
		$this->jobs->expects($this->exactly(2))->method('add');
		$s = $this->service();
		$s->set('u', ['sidecarLocation' => 'meta']);
		$s->set('u', ['sidecarLocation' => 'beside']);
	}

	public function testUnknownValuesFallBackToBesideWithoutAJob(): void {
		$this->jobs->expects($this->never())->method('add');
		$s = $this->service();
		$this->assertSame('beside', $s->set('u', ['sidecarLocation' => 'cloud'])['sidecarLocation']);
		$this->stored = json_encode(['sidecarLocation' => ['meta']]);
		$this->assertSame('beside', $s->sidecarLocation('u'));
		$this->stored = 'not json';
		$this->assertSame('beside', $s->sidecarLocation('u'));
	}

	public function testControllerValidatesTheValue(): void {
		$settings = $this->createMock(SettingsService::class);
		$request = $this->createMock(IRequest::class);
		$controller = new SettingsController($request, 'u', $settings, $this->createMock(IRootFolder::class));

		$request->method('getParams')->willReturn(['sidecarLocation' => 'meta']);
		$settings->expects($this->once())->method('set')->with('u', ['sidecarLocation' => 'meta'])->willReturn([]);
		$controller->put(sidecarLocation: 'meta');

		$this->expectException(OCSBadRequestException::class);
		$bad = $this->createMock(IRequest::class);
		$bad->method('getParams')->willReturn(['sidecarLocation' => 'elsewhere']);
		(new SettingsController($bad, 'u', $this->createMock(SettingsService::class), $this->createMock(IRootFolder::class)))->put(sidecarLocation: 'elsewhere');
	}

	public function testControllerRejectsNull(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['sidecarLocation' => null]);
		$this->expectException(OCSBadRequestException::class);
		(new SettingsController($request, 'u', $this->createMock(SettingsService::class), $this->createMock(IRootFolder::class)))->put();
	}

	public function testCapabilitiesAnnounceTheFeatures(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('0.10.0');
		$caps = (new Capabilities($appManager))->getCapabilities()['ebookreader'];
		$this->assertContains('folders', $caps['features']);
		$this->assertContains('sidecar-meta', $caps['features']);
	}
}
