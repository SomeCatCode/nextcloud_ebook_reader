<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Service\ArchiveCache;
use OCP\Files\File;
use OCP\Files\Storage\IStorage;
use OCP\IAppConfig;
use OCP\ITempManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ArchiveCacheTest extends TestCase {
	private string $dir;
	private int $limitMb = 2048;
	private ArchiveCache $cache;
	/** @var list<string> */
	private array $tmp = [];

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/ebr-cache-test-' . bin2hex(random_bytes(4));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(fn (): int => $this->limitMb);
		$this->cache = new ArchiveCache($this->createMock(ITempManager::class), $appConfig, $this->createMock(LoggerInterface::class));
		$this->cache->useDirectory($this->dir);
	}

	protected function tearDown(): void {
		$this->cache->release();
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			@unlink($f);
		}
		@rmdir($this->dir);
		foreach ($this->tmp as $f) {
			@unlink($f);
		}
	}

	/**
	 * @param int $fopenCalls counts how often the content was read through the file API
	 */
	private function remoteFile(int $id, string $etag, string $content, ?int &$fopenCalls = null, string $name = 'book.epub'): File&MockObject {
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(false);
		$storage->method('instanceOfStorage')->willReturn(false);
		$file = $this->createMock(File::class);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getId')->willReturn($id);
		$file->method('getEtag')->willReturn($etag);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturn(strlen($content));
		$counter = &$fopenCalls;
		$file->method('fopen')->willReturnCallback(static function () use ($content, &$counter) {
			if ($counter !== null) {
				$counter++;
			}
			$h = fopen('php://memory', 'r+b');
			fwrite($h, $content);
			rewind($h);
			return $h;
		});
		return $file;
	}

	public function testLocalStorageWithoutEncryptionIsPassedThrough(): void {
		$path = tempnam(sys_get_temp_dir(), 'ebr');
		$this->tmp[] = $path;
		file_put_contents($path, 'x');
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('instanceOfStorage')->willReturn(false);
		$storage->method('getLocalFile')->willReturn($path);
		$file = $this->createMock(File::class);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/x.epub');
		$file->expects($this->never())->method('fopen');

		$this->assertSame($path, $this->cache->localPath($file));
		$this->assertTrue($this->cache->isDirect($file));
		$this->assertDirectoryDoesNotExist($this->dir);
		$this->cache->release($path);
	}

	public function testEncryptedLocalStorageIsCopied(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('instanceOfStorage')->willReturn(true);
		$storage->expects($this->never())->method('getLocalFile');
		$file = $this->createMock(File::class);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getId')->willReturn(3);
		$file->method('getEtag')->willReturn('e1');
		$file->method('getName')->willReturn('b.epub');
		$file->method('getSize')->willReturn(5);
		$file->method('fopen')->willReturnCallback(static function () {
			$h = fopen('php://memory', 'r+b');
			fwrite($h, 'plain');
			rewind($h);
			return $h;
		});
		$path = $this->cache->localPath($file);
		$this->assertSame('plain', file_get_contents($path));
		$this->cache->release($path);
	}

	public function testCopiedOncePerEtag(): void {
		$reads = 0;
		$file = $this->remoteFile(7, 'abc123', 'PKcontent', $reads);
		$p1 = $this->cache->localPath($file);
		$p2 = $this->cache->localPath($file);
		$this->assertSame($p1, $p2);
		$this->assertSame(1, $reads, 'the content is read through the file API once');
		$this->assertStringEndsWith('/7-abc123.epub', str_replace('\\', '/', $p1));
		$this->assertSame('PKcontent', file_get_contents($p1));
		$this->cache->release($p1);
		$this->cache->release($p2);

		// a later request (nothing held) still finds the copy
		$p3 = $this->cache->localPath($file);
		$this->assertSame(1, $reads);
		$this->cache->release($p3);
		$this->assertSame([], glob($this->dir . '/*.part.*') ?: []);
	}

	public function testCorruptCopyIsReplaced(): void {
		$reads = 0;
		$file = $this->remoteFile(7, 'abc', 'complete', $reads);
		$p = $this->cache->localPath($file);
		$this->cache->release($p);
		file_put_contents($p, 'part'); // wrong size, e.g. truncated by a full disk
		$p = $this->cache->localPath($file);
		$this->assertSame('complete', file_get_contents($p));
		$this->assertSame(2, $reads);
		$this->cache->release($p);
	}

	public function testOldEtagOfTheSameFileIsRemovedImmediately(): void {
		$old = $this->cache->localPath($this->remoteFile(7, 'old', 'one'));
		$this->cache->release($old);
		$other = $this->cache->localPath($this->remoteFile(70, 'old', 'other file'));
		$this->cache->release($other);
		$new = $this->cache->localPath($this->remoteFile(7, 'new', 'two'));
		$this->cache->release($new);
		$this->assertFileDoesNotExist($old);
		$this->assertFileExists($new);
		$this->assertFileExists($other, 'files with another id that merely share a prefix stay');
	}

	public function testEntryInUseIsNotRemovedByAnotherEtag(): void {
		$old = $this->cache->localPath($this->remoteFile(7, 'old', 'one'));
		$new = $this->cache->localPath($this->remoteFile(7, 'new', 'two'));
		$this->assertFileExists($old, 'a path that is still in use is kept');
		$this->cache->release($old);
		$this->cache->release($new);
	}

	public function testLruEvictionWithTinyLimit(): void {
		$this->limitMb = 1;
		$mb = str_repeat('a', 600 * 1024);
		$a = $this->cache->localPath($this->remoteFile(1, 'e', $mb));
		$this->cache->release($a);
		touch($a, time() - 300);
		$b = $this->cache->localPath($this->remoteFile(2, 'e', $mb));
		$this->cache->release($b);
		// 1.2 MB > 1 MB limit: the oldest entry (a) goes, the newest stays
		$this->assertFileDoesNotExist($a);
		$this->assertFileExists($b);

		// an entry that is being used survives the cleanup, even if it is the oldest
		touch($b, time() - 600);
		$held = $this->cache->localPath($this->remoteFile(2, 'e', $mb));
		$c = $this->cache->localPath($this->remoteFile(3, 'e', $mb));
		$this->assertFileExists($held);
		$this->assertFileExists($c);
		$this->cache->release($held);
		$this->cache->release($c);
		$this->assertSame($held, $b);
	}

	public function testCleanupTrimsToTheLimitAndRemovesStalePartFiles(): void {
		$mb = str_repeat('a', 700 * 1024);
		$paths = [];
		$this->limitMb = 100;
		foreach ([1, 2, 3] as $id) {
			$paths[$id] = $this->cache->localPath($this->remoteFile($id, 'e', $mb));
			$this->cache->release($paths[$id]);
		}
		touch($paths[1], time() - 1000);
		touch($paths[2], time() - 500);
		$part = $this->dir . '/9-x.epub.part.abc';
		file_put_contents($part, 'junk');
		touch($part, time() - 7200);
		$this->limitMb = 1;
		$freed = $this->cache->cleanup();
		$this->assertGreaterThan(0, $freed);
		$this->assertFileDoesNotExist($part);
		$this->assertFileDoesNotExist($paths[1]);
		$this->assertFileDoesNotExist($paths[2]);
		$this->assertFileExists($paths[3]);
	}

	public function testFits(): void {
		$this->limitMb = 1;
		$this->assertTrue($this->cache->fits($this->remoteFile(1, 'e', 'small')));
		$this->assertFalse($this->cache->fits($this->remoteFile(2, 'e', str_repeat('a', 2 * 1024 * 1024))));
	}

	public function testUnreadableFileThrowsAndLeavesNothingBehind(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(false);
		$file = $this->createMock(File::class);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getId')->willReturn(4);
		$file->method('getEtag')->willReturn('e');
		$file->method('getName')->willReturn('a.epub');
		$file->method('getSize')->willReturn(10);
		$file->method('fopen')->willReturn(false);
		$this->expectException(\RuntimeException::class);
		try {
			$this->cache->localPath($file);
		} finally {
			$this->assertSame([], glob($this->dir . '/*.epub') ?: []);
			$this->assertSame([], glob($this->dir . '/*.part.*') ?: []);
		}
	}
}
