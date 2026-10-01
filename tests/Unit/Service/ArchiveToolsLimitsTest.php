<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Metadata\ComicArchive;
use OCA\EbookReader\Metadata\UnsafeArchiveException;
use OCA\EbookReader\Service\ArchiveTools;
use PHPUnit\Framework\TestCase;

/** Regression tests of the archive tool hardening (security audit 2026-10-01, B2). */
class ArchiveToolsLimitsTest extends TestCase {
	private const LISTING = "Path = 001.jpg\nFolder = -\nSize = 1000\nPacked Size = 10\nAttributes = A\n\n"
		. "Path = sub\nFolder = +\nSize = 0\nAttributes = D\n\n"
		. "Path = sub\\002.png\r\nFolder = -\r\nSize = 2500\r\n\r\n"
		. "Path = ../evil.jpg\nFolder = -\nSize = 99999999999\n\n"
		. "Path = nosize.jpg\nFolder = -\n";

	public function testSizesAreParsedFrom7zSltListing(): void {
		$entries = ArchiveTools::parseSevenZipEntries(self::LISTING);
		$this->assertSame([
			['name' => '001.jpg', 'size' => 1000],
			['name' => 'sub/002.png', 'size' => 2500],
			['name' => 'nosize.jpg', 'size' => null],
		], $entries);
		$this->assertSame(['001.jpg', 'sub/002.png', 'nosize.jpg'], ArchiveTools::parseSevenZipList(self::LISTING));
	}

	public function testHeaderBlockIsNotCounted(): void {
		$out = "7-Zip 23.01\n\n--\nPath = a.cb7\nType = 7z\nPhysical Size = 100\n\n----------\nPath = 1.jpg\nSize = 5\nFolder = -\n\nPath = d\nFolder = +\nSize = 0\n";
		$this->assertSame([['name' => '1.jpg', 'size' => 5]], ArchiveTools::parseSevenZipEntries($out));
	}

	public function testLineListingsHaveNoSizes(): void {
		$this->assertSame([['name' => 'a/1.jpg', 'size' => null]], ArchiveTools::parseEntries('unrar', "a/1.jpg\n../x\n"));
	}

	public function testTotalSizeCapIsEnforced(): void {
		$entries = ArchiveTools::parseSevenZipEntries(self::LISTING);
		ArchiveTools::checkLimits($entries, 10, 3500);
		$this->expectException(UnsafeArchiveException::class);
		ArchiveTools::checkLimits($entries, 10, 3499);
	}

	public function testDeclaredHugeSizeIsRefusedWithDefaultCap(): void {
		$listing = "Path = bomb.jpg\nFolder = -\nSize = " . (3 * 1024 * 1024 * 1024) . "\n";
		$this->expectException(UnsafeArchiveException::class);
		ArchiveTools::checkLimits(ArchiveTools::parseSevenZipEntries($listing));
	}

	public function testEntryCountCapIsEnforced(): void {
		$entries = [];
		for ($i = 0; $i < ArchiveTools::MAX_ENTRIES + 1; $i++) {
			$entries[] = ['name' => $i . '.jpg', 'size' => 1];
		}
		$this->assertSame(5000, ArchiveTools::MAX_ENTRIES);
		ArchiveTools::checkLimits(array_slice($entries, 0, 5000));
		$this->expectException(UnsafeArchiveException::class);
		ArchiveTools::checkLimits($entries);
	}

	public function testOnlyAbsoluteSearchDirectoriesAreUsed(): void {
		$this->assertSame(['/usr/bin', 'C:\\Tools', 'D:/x', '\\\\srv\\share'], ArchiveTools::absoluteDirs(['', '.', 'bin', '../bin', '/usr/bin', 'C:\\Tools', 'D:/x', '\\\\srv\\share', 'C:rel']));
	}

	public function testRelativeDirectoryIsNotSearched(): void {
		$dir = sys_get_temp_dir() . '/ebr-rel-' . bin2hex(random_bytes(4));
		mkdir($dir);
		$exe = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
		$prev = getcwd();
		try {
			touch($dir . '/unrar' . $exe);
			chmod($dir . '/unrar' . $exe, 0755);
			chdir($dir);
			$this->assertFalse((new ArchiveTools(['.', '']))->available()['unrar']);
		} finally {
			chdir($prev === false ? sys_get_temp_dir() : $prev);
			@unlink($dir . '/unrar' . $exe);
			@rmdir($dir);
		}
	}

	public function testContainerTypeIsForcedByMagicBytes(): void {
		$this->assertSame('7z', ArchiveTools::typeFromMagic("7z\xBC\xAF\x27\x1C\x00\x04"));
		$this->assertSame('rar', ArchiveTools::typeFromMagic("Rar!\x1A\x07\x00"));
		$this->assertSame('rar', ArchiveTools::typeFromMagic("Rar!\x1A\x07\x01\x00"));
		$this->assertSame('zip', ArchiveTools::typeFromMagic("PK\x03\x04...."));
		$this->assertNull(ArchiveTools::typeFromMagic('GIF89a..'));
		$this->assertSame(['/usr/bin/7z', 'l', '-slt', '-ba', '-bd', '-y', '-trar', '--', '/tmp/a.cbr'],
			ArchiveTools::commandFor('sevenZip', 'list', '/usr/bin/7z', '/tmp/a.cbr', null, 'rar'));
		$this->assertSame(['/usr/bin/7z', 'e', '-so', '-bd', '-y', '-t7z', '--', '/tmp/a.cb7', '1.jpg'],
			ArchiveTools::commandFor('sevenZip', 'extract', '/usr/bin/7z', '/tmp/a.cb7', '1.jpg', '7z'));
		// the type switch does not exist for the other tools
		$this->assertSame(['unrar', 'lb', '-p-', '--', '/tmp/a.cbr'], ArchiveTools::commandFor('unrar', 'list', 'unrar', '/tmp/a.cbr', null, 'rar'));
	}

	public function testUnknownMagicIsRefusedWith7z(): void {
		$tools = new ArchiveTools();
		if (!$tools->available()['sevenZip']) {
			$this->markTestSkipped('no 7z installed');
		}
		$f = tempnam(sys_get_temp_dir(), 'ebrx') . '.cb7';
		file_put_contents($f, 'this is not an archive at all');
		try {
			$this->expectException(\RuntimeException::class);
			$tools->list($f, 'cb7');
		} finally {
			@unlink($f);
		}
	}

	public function testRealSevenZipListingEnforcesEntryCap(): void {
		$tools = new ArchiveTools();
		if (!$tools->available()['sevenZip']) {
			$this->markTestSkipped('no 7z installed');
		}
		$dir = sys_get_temp_dir() . '/ebr-cap-' . bin2hex(random_bytes(4));
		mkdir($dir . '/src', 0777, true);
		for ($i = 0; $i < ArchiveTools::MAX_ENTRIES + 1; $i++) {
			file_put_contents($dir . '/src/' . $i . '.jpg', 'x');
		}
		$archive = $dir . '/many.cb7';
		try {
			$tools->createSevenZip($archive, $dir . '/src');
			$this->expectException(UnsafeArchiveException::class);
			ComicArchive::open($archive, 'cb7', $tools);
		} finally {
			array_map('unlink', glob($dir . '/src/*') ?: []);
			@rmdir($dir . '/src');
			@unlink($archive);
			@rmdir($dir);
		}
	}
}
