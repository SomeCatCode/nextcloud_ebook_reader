<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Service\CoverService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\TestCase;

class LibraryCoverServiceTest extends TestCase {
	/** @var array<string, string> */
	private array $store = [];

	private function service(): CoverService {
		$this->store = [];
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('fileExists')->willReturnCallback(fn (string $n): bool => isset($this->store[$n]));
		$folder->method('newFile')->willReturnCallback(function (string $n, $content = null): ISimpleFile {
			$this->store[$n] = (string)$content;
			return $this->fileMock($n);
		});
		$folder->method('getFile')->willReturnCallback(fn (string $n): ISimpleFile => $this->fileMock($n));

		$appData = $this->createMock(IAppData::class);
		$created = false;
		$appData->method('getFolder')->willReturnCallback(function () use (&$created, $folder) {
			if (!$created) {
				throw new NotFoundException();
			}
			return $folder;
		});
		$appData->method('newFolder')->willReturnCallback(function () use (&$created, $folder) {
			$created = true;
			return $folder;
		});
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);
		return new CoverService($factory);
	}

	private function fileMock(string $name): ISimpleFile {
		$f = $this->createMock(ISimpleFile::class);
		$f->method('getContent')->willReturnCallback(fn (): string => $this->store[$name] ?? '');
		$f->method('putContent')->willReturnCallback(function ($data) use ($name): void {
			$this->store[$name] = (string)$data;
		});
		$f->method('delete')->willReturnCallback(function () use ($name): void {
			unset($this->store[$name]);
		});
		return $f;
	}

	private function png(int $w, int $h): string {
		$img = imagecreatetruecolor($w, $h);
		ob_start();
		imagepng($img);
		return (string)ob_get_clean();
	}

	public function testStoreScalesAndReturnsEtag(): void {
		$svc = $this->service();
		$etag = $svc->storeCover(42, $this->png(1200, 1800));
		$this->assertSame(md5($this->store['42-large.jpg']), $etag);
		$large = getimagesizefromstring($this->store['42-large.jpg']);
		$small = getimagesizefromstring($this->store['42-small.jpg']);
		$this->assertSame([600, 900], [$large[0], $large[1]]);
		$this->assertSame([200, 300], [$small[0], $small[1]]);
		$this->assertSame('image/jpeg', $large['mime']);
	}

	public function testSmallImagesAreNotUpscaled(): void {
		$svc = $this->service();
		$svc->storeCover(1, $this->png(100, 150));
		$large = getimagesizefromstring($this->store['1-large.jpg']);
		$this->assertSame([100, 150], [$large[0], $large[1]]);
	}

	public function testGetAndDelete(): void {
		$svc = $this->service();
		$this->assertNull($svc->getCover(7, 'small'));
		$svc->storeCover(7, $this->png(300, 300));
		$this->assertNotNull($svc->getCover(7, 'small'));
		$this->assertNotNull($svc->getCover(7, 'large'));
		$this->assertNotNull($svc->getCover(7, 'bogus'), 'unknown size falls back to large');
		$svc->deleteCover(7);
		$this->assertSame([], $this->store);
		$this->assertNull($svc->getCover(7, 'large'));
	}

	public function testInvalidImageIsRejected(): void {
		$svc = $this->service();
		$this->expectException(\InvalidArgumentException::class);
		$svc->storeCover(1, 'this is not an image');
	}
}
