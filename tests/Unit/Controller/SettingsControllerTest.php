<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\SettingsController;
use OCA\EbookReader\Service\SettingsService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SettingsControllerTest extends TestCase {
	public static function setUpBeforeClass(): void {
		// OCP\Files\IRootFolder extends a server-internal interface that is not part of nextcloud/ocp.
		if (!interface_exists('OC\Hooks\Emitter')) {
			eval('namespace OC\Hooks; interface Emitter { public function listen($scope, $method, callable $callback); public function removeListener($scope = null, $method = null, ?callable $callback = null); }');
		}
	}

	private SettingsService&MockObject $settings;
	private IRequest&MockObject $request;
	private SettingsController $controller;

	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->request = $this->createMock(IRequest::class);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturnCallback(function (string $path) {
			return match ($path) {
				'/Books', '/Comics' => $this->createMock(Folder::class),
				'/file.epub' => $this->createMock(File::class),
				default => throw new NotFoundException(),
			};
		});
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		$this->controller = new SettingsController($this->request, 'u', $this->settings, $root);
	}

	public function testValidFoldersAreNormalisedAndStored(): void {
		$this->request->method('getParams')->willReturn(['libraryFolders' => []]);
		$this->settings->expects($this->once())->method('set')
			->with('u', ['libraryFolders' => ['/Books', '/Comics']])
			->willReturn(['libraryFolders' => ['/Books', '/Comics']]);
		$this->controller->put(['Books/', '/Comics', '/Books']);
	}

	#[DataProvider('badFoldersProvider')]
	public function testInvalidFoldersRejected(array $folders): void {
		$this->request->method('getParams')->willReturn(['libraryFolders' => []]);
		$this->settings->expects($this->never())->method('set');
		$this->expectException(OCSBadRequestException::class);
		$this->controller->put($folders);
	}

	public static function badFoldersProvider(): array {
		return [
			'missing' => [['/Nope']],
			'file' => [['/file.epub']],
			'not string' => [[5]],
			'traversal' => [['/Books/../etc']],
			'assoc' => [['a' => '/Books']],
		];
	}

	public function testPatternAndGenreValidation(): void {
		$this->request->method('getParams')->willReturn(['filenamePattern' => '']);
		$this->expectException(OCSBadRequestException::class);
		$this->controller->put(null, null, '   ');
	}

	public function testGenreListNullResetsAndListIsValidated(): void {
		$this->request->method('getParams')->willReturn(['genreList' => null]);
		$this->settings->expects($this->once())->method('set')->with('u', ['genreList' => null])->willReturn([]);
		$this->controller->put(null, null, null, null);
	}

	public function testInvalidGenreEntryRejected(): void {
		$this->request->method('getParams')->willReturn(['genreList' => []]);
		$this->expectException(OCSBadRequestException::class);
		$this->controller->put(null, null, null, ['ok', 3]);
	}
}
