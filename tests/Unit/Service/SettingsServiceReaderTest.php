<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Service\SettingsService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/** Regression test A7: reader preferences are an allowlist of typed keys. */
class SettingsServiceReaderTest extends TestCase {
	private function service(?string &$stored = null): SettingsService {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(static function () use (&$stored): string {
			return $stored ?? '';
		});
		$config->method('setUserValue')->willReturnCallback(static function (string $u, string $a, string $k, string $v) use (&$stored): void {
			$stored = $v;
		});
		return new SettingsService($config);
	}

	public function testUnknownKeysAreDropped(): void {
		$stored = null;
		$s = $this->service($stored)->set('u', ['reader' => ['theme' => 'dark', 'evil' => '<script>', 'nested' => ['a' => 1], 'fontSize' => 120]]);
		$this->assertSame('dark', $s['reader']['theme']);
		$this->assertSame(120, $s['reader']['fontSize']);
		$this->assertArrayNotHasKey('evil', $s['reader']);
		$this->assertArrayNotHasKey('nested', $s['reader']);
		$this->assertStringNotContainsString('evil', (string)$stored);
	}

	public function testWrongTypesAndRangesKeepTheDefault(): void {
		$s = $this->service()->set('u', ['reader' => [
			'theme' => 'neon', 'fontSize' => '120', 'lineHeight' => 99, 'margin' => -5, 'maxColumns' => 1.0e9,
			'fontFamily' => ['x'], 'flow' => 'scrolled', 'comicRtl' => 'yes',
		]]);
		$reader = $s['reader'];
		$this->assertSame(SettingsService::DEFAULT_READER['theme'], $reader['theme']);
		$this->assertSame(SettingsService::DEFAULT_READER['fontSize'], $reader['fontSize']);
		$this->assertSame(SettingsService::DEFAULT_READER['lineHeight'], $reader['lineHeight']);
		$this->assertSame(SettingsService::DEFAULT_READER['margin'], $reader['margin']);
		$this->assertSame(SettingsService::DEFAULT_READER['maxColumns'], $reader['maxColumns']);
		$this->assertSame('', $reader['fontFamily']);
		$this->assertSame('scrolled', $reader['flow']);
		$this->assertArrayNotHasKey('comicRtl', $reader);
	}

	public function testComicKeysAndFontFamilyAreAccepted(): void {
		$s = $this->service()->set('u', ['reader' => ['comicSpread' => 'double', 'comicRtl' => true, 'comicZoom' => 'fit-width', 'fontFamily' => 'Georgia, serif']]);
		$this->assertSame('double', $s['reader']['comicSpread']);
		$this->assertTrue($s['reader']['comicRtl']);
		$this->assertSame('fit-width', $s['reader']['comicZoom']);
		$this->assertSame('Georgia, serif', $s['reader']['fontFamily']);
	}

	public function testStoredUnknownKeysAreIgnoredOnRead(): void {
		$stored = json_encode(['reader' => ['theme' => 'sepia', 'junk' => 1]]);
		$s = $this->service($stored)->get('u');
		$this->assertSame('sepia', $s['reader']['theme']);
		$this->assertArrayNotHasKey('junk', $s['reader']);
	}

	public function testMetadataWriteModeIsValidatedAndDefaultsToBackground(): void {
		$stored = null;
		$svc = $this->service($stored);
		$this->assertSame('background', $svc->get('u')['metadataWriteMode']);
		$this->assertSame('never', $svc->set('u', ['metadataWriteMode' => 'never'])['metadataWriteMode']);
		$this->assertSame('immediate', $svc->set('u', ['metadataWriteMode' => 'immediate'])['metadataWriteMode']);
		// unknown values fall back to the default
		$this->assertSame('background', $svc->set('u', ['metadataWriteMode' => 'sometimes'])['metadataWriteMode']);
	}
}
