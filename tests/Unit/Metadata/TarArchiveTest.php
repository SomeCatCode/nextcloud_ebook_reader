<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Metadata\TarArchive;
use OCA\EbookReader\Metadata\UnsafeArchiveException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures.php';

class TarArchiveTest extends TestCase {
	/** @param array<string, string> $entries */
	private function writeTar(array $entries): string {
		$dir = sys_get_temp_dir() . '/ebr-tar-' . bin2hex(random_bytes(4));
		mkdir($dir);
		$files = [];
		foreach ($entries as $name => $data) {
			$f = $dir . '/' . md5($name);
			file_put_contents($f, $data);
			$files[$name] = $f;
		}
		$tar = Fixtures::temp('.tar');
		TarArchive::write($tar, $files);
		foreach ($files as $f) {
			unlink($f);
		}
		rmdir($dir);
		return $tar;
	}

	public function testRoundtripKeepsOrderAndContent(): void {
		$tar = $this->writeTar(['0002.jpg' => 'bb', '0001.jpg' => str_repeat('a', 1000), 'ComicInfo.xml' => '<ComicInfo/>', 'empty.txt' => '']);
		$a = TarArchive::open($tar);
		$this->assertSame(['0002.jpg', '0001.jpg', 'ComicInfo.xml', 'empty.txt'], $a->names());
		$this->assertSame(str_repeat('a', 1000), $a->read('0001.jpg'));
		$this->assertSame('<ComicInfo/>', $a->read('ComicInfo.xml'));
		$this->assertSame('', $a->read('empty.txt'));
		$this->assertNull($a->read('missing'));
		$a->close();
	}

	public function testLongNamesUsePaxHeader(): void {
		$name = 'chapter-' . str_repeat('x', 150) . '/page.jpg';
		$a = TarArchive::open($this->writeTar([$name => 'img', 'short.jpg' => 'x']));
		$this->assertSame([$name, 'short.jpg'], $a->names());
		$this->assertSame('img', $a->read($name));
	}

	public function testReadsTarsWrittenByPharData(): void {
		if (!class_exists(\PharData::class)) {
			$this->markTestSkipped('phar extension not available');
		}
		$tar = sys_get_temp_dir() . '/ebr-phar-' . bin2hex(random_bytes(4)) . '.tar';
		$phar = new \PharData($tar);
		$phar->addFromString('dir/page 1.jpg', 'one');
		$phar->addFromString('ComicInfo.xml', '<x/>');
		unset($phar);
		try {
			$a = TarArchive::open($tar);
			$this->assertSame(['dir/page 1.jpg', 'ComicInfo.xml'], $a->names());
			$this->assertSame('one', $a->read('dir/page 1.jpg'));
		} finally {
			@unlink($tar);
		}
	}

	public function testPharDataReadsOurTar(): void {
		if (!class_exists(\PharData::class)) {
			$this->markTestSkipped('phar extension not available');
		}
		$tar = $this->writeTar(['0001.jpg' => 'one', '0002.jpg' => 'two']);
		$copy = $tar . '.tar';
		copy($tar, $copy);
		try {
			$phar = new \PharData($copy);
			$this->assertSame('two', file_get_contents('phar://' . $copy . '/0002.jpg'));
			$this->assertCount(2, iterator_to_array($phar));
		} finally {
			@unlink($copy);
		}
	}

	public function testUnsafeNamesAreDropped(): void {
		$a = TarArchive::open($this->writeTar(['../evil.jpg' => 'x', 'ok.jpg' => 'y', '/abs.jpg' => 'z']));
		$this->assertSame(['ok.jpg'], $a->names());
	}

	public function testRejectsNonTar(): void {
		$bad = Fixtures::temp('.tar');
		file_put_contents($bad, str_repeat('not a tar file ', 100));
		$this->expectException(UnsafeArchiveException::class);
		TarArchive::open($bad);
	}
}
