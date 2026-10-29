<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Service\ComicPageCache;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ComicPageCacheTest extends TestCase {
	private const MB = 1024 * 1024;

	/** @var list<string> */
	private array $deleted = [];

	private function cache(int $limitMb, ?IMemcache $memcache = null): ComicPageCache {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn($limitMb);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($memcache ?? $this->createMock(IMemcache::class));
		return new ComicPageCache($this->createMock(IAppData::class), $factory, $config, $this->createMock(LoggerInterface::class));
	}

	private function entry(string $name, int $mtime, int $size): ISimpleFile {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn($name);
		$file->method('getMTime')->willReturn($mtime);
		$file->method('getSize')->willReturn($size);
		$file->method('delete')->willReturnCallback(function () use ($name): void {
			$this->deleted[] = $name;
		});
		return $file;
	}

	/** @param list<ISimpleFile> $entries */
	private function folder(array $entries): ISimpleFolder {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getDirectoryListing')->willReturn($entries);
		return $folder;
	}

	public function testTrimRemovesTheOldestEntriesUntilBelowTheLimit(): void {
		$folder = $this->folder([
			$this->entry('new', 300, 4 * self::MB),
			$this->entry('oldest', 100, 4 * self::MB),
			$this->entry('middle', 200, 4 * self::MB),
		]);
		// 12 MB in a 10 MB cache: trimmed to 80 % (8 MB), the oldest entry is enough
		$freed = $this->cache(10)->trim($folder);
		$this->assertSame(['oldest'], $this->deleted);
		$this->assertSame(4 * self::MB, $freed);
	}

	public function testTrimKeepsEverythingBelowTheLimit(): void {
		$folder = $this->folder([$this->entry('a', 100, 4 * self::MB)]);
		$this->assertSame(0, $this->cache(10)->trim($folder));
		$this->assertSame([], $this->deleted);
	}

	public function testPutTrimsOnlyAfterEnoughBytesWereWritten(): void {
		$memcache = $this->createMock(IMemcache::class);
		$memcache->method('inc')->willReturnOnConsecutiveCalls(1000, 80 * self::MB);
		$memcache->method('cas')->willReturn(true);
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->expects($this->exactly(2))->method('newFile');
		$folder->expects($this->once())->method('getDirectoryListing')->willReturn([]);
		$cache = $this->cache(10, $memcache);
		$cache->put($folder, 'a.jpg', 'x');
		$cache->put($folder, 'b.jpg', 'y');
	}
}
