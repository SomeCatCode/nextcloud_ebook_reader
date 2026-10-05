<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Service\ArchiveTools;
use PHPUnit\Framework\TestCase;

class ArchiveToolsTest extends TestCase {
	public function testCommandBuildingUsesArgvWithoutShellSyntax(): void {
		$this->assertSame(['/usr/bin/7z', 'l', '-slt', '-ba', '-bd', '-y', '--', '/tmp/a.cb7'],
			ArchiveTools::commandFor('sevenZip', 'list', '/usr/bin/7z', '/tmp/a.cb7'));
		$this->assertSame(['/usr/bin/7z', 'e', '-so', '-bd', '-y', '--', '/tmp/a.cb7', 'dir/1.jpg'],
			ArchiveTools::commandFor('sevenZip', 'extract', '/usr/bin/7z', '/tmp/a.cb7', 'dir/1.jpg'));
		$this->assertSame(['unrar', 'lb', '-p-', '--', '/tmp/a.cbr'], ArchiveTools::commandFor('unrar', 'list', 'unrar', '/tmp/a.cbr'));
		$this->assertSame(['unrar', 'p', '-inul', '-p-', '--', '/tmp/a.cbr', '1.jpg'], ArchiveTools::commandFor('unrar', 'extract', 'unrar', '/tmp/a.cbr', '1.jpg'));
		$this->assertSame(['bsdtar', '-tf', '/tmp/a.cbr'], ArchiveTools::commandFor('bsdtar', 'list', 'bsdtar', '/tmp/a.cbr'));
		$this->assertSame(['bsdtar', '-xOf', '/tmp/a.cbr', '--', '1.jpg'], ArchiveTools::commandFor('bsdtar', 'extract', 'bsdtar', '/tmp/a.cbr', '1.jpg'));
	}

	public function testExtractNeedsEntry(): void {
		$this->expectException(\InvalidArgumentException::class);
		ArchiveTools::commandFor('unrar', 'extract', 'unrar', '/tmp/a.cbr');
	}

	public function testParseSevenZipTechnicalListing(): void {
		$out = "Path = pages/001.jpg\nFolder = -\nSize = 10\nAttributes = A\n\n"
			. "Path = pages\nFolder = +\nSize = 0\nAttributes = D\n\n"
			. "Path = pages\\002.png\r\nFolder = -\r\nSize = 12\r\n\r\n"
			. "Path = ../evil.jpg\nFolder = -\n\n"
			. "Path = ComicInfo.xml\nFolder = -\nSize = 99\n";
		$this->assertSame(['pages/001.jpg', 'pages/002.png', 'ComicInfo.xml'], ArchiveTools::parseSevenZipList($out));
	}

	public function testParseSevenZipListingWithHeaderBlock(): void {
		$out = "\n7-Zip 23.01\n\nListing archive: a.cb7\n\n--\nPath = a.cb7\nType = 7z\nPhysical Size = 100\n\n----------\nPath = 1.jpg\nSize = 5\nFolder = -\n\nPath = dir\nFolder = +\n";
		$this->assertSame(['1.jpg'], ArchiveTools::parseSevenZipList($out));
	}

	public function testParseLineListsOfUnrarAndBsdtar(): void {
		$out = "Chapter 1/001.jpg\r\nChapter 1/\nChapter 1\\002.jpg\n\n*wild.jpg\n@list.jpg\n/abs.jpg\n../up.jpg\nComicInfo.xml\n";
		$expected = ['Chapter 1/001.jpg', 'Chapter 1/002.jpg', 'ComicInfo.xml'];
		$this->assertSame($expected, ArchiveTools::parseList('unrar', $out));
		$this->assertSame($expected, ArchiveTools::parseList('bsdtar', $out));
	}

	public function testSafeEntryNames(): void {
		$this->assertTrue(ArchiveTools::isSafeEntryName('a/b c.jpg'));
		$this->assertTrue(ArchiveTools::isSafeEntryName('-1.jpg'));
		foreach (['', '/etc/passwd', '../x', 'a/../../x', 'C:\\x', 'a*.jpg', 'a?.jpg', '@list', "a\nb", 'a[1].jpg'] as $bad) {
			$this->assertFalse(ArchiveTools::isSafeEntryName($bad), $bad);
		}
	}

	public function testDetectionInGivenDirectories(): void {
		$dir = sys_get_temp_dir() . '/ebr-bin-' . bin2hex(random_bytes(4));
		mkdir($dir);
		$exe = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
		try {
			$none = new ArchiveTools([$dir]);
			$this->assertSame(['sevenZip' => false, 'unrar' => false, 'bsdtar' => false], $none->available());
			$this->assertFalse($none->canRead('cbr'));
			$this->assertFalse($none->canRead('cb7'));
			$this->assertTrue($none->canRead('cbz'));
			$this->assertTrue($none->canRead('cbt'));
			$this->assertFalse($none->canWrite('cb7'));
			$this->assertFalse($none->canWrite('cbr'));
			try {
				$none->list('/tmp/x.cbr', 'cbr');
				$this->fail('expected exception');
			} catch (\RuntimeException) {
				$this->addToAssertionCount(1);
			}

			touch($dir . '/7za' . $exe);
			touch($dir . '/unrar' . $exe);
			chmod($dir . '/7za' . $exe, 0755);
			chmod($dir . '/unrar' . $exe, 0755);
			$tools = new ArchiveTools([$dir]);
			$this->assertSame(['sevenZip' => true, 'unrar' => true, 'bsdtar' => false], $tools->available());
			$this->assertTrue($tools->canRead('cbr'));
			$this->assertTrue($tools->canRead('cb7'));
			$this->assertTrue($tools->canWrite('cb7'));
		} finally {
			array_map('unlink', glob($dir . '/*') ?: []);
			rmdir($dir);
		}
	}

	public function testErrorDetailIsFirstNonEmptyLine(): void {
		$this->assertSame(': ERROR: Unsupported Method : 001.jpg', ArchiveTools::errorDetail("\n  ERROR: Unsupported Method : 001.jpg\r\nSub items Errors: 1\n"));
		$this->assertSame('', ArchiveTools::errorDetail(" \n\n"));
		$this->assertSame(202, strlen(ArchiveTools::errorDetail(str_repeat('x', 500))));
	}

	/**
	 * Fake tools in a temp dir: a 7z that lists the RAR but cannot unpack it (like p7zip without the
	 * RAR codec), optionally a bsdtar that can. Shell scripts, so not on Windows.
	 *
	 * @return array{0: string, 1: string} bin dir, archive path
	 */
	private function fakeRarSetup(bool $withBsdtar): array {
		if (PHP_OS_FAMILY === 'Windows') {
			$this->markTestSkipped('fake tools are shell scripts');
		}
		$dir = sys_get_temp_dir() . '/ebr-fake-' . bin2hex(random_bytes(4));
		mkdir($dir);
		// a RAR 1.5-4.x signature is all the tool selection looks at
		$archive = $dir . '/book.cbr';
		file_put_contents($archive, "Rar!\x1A\x07\x00" . str_repeat("\0", 32));
		$sevenZip = "#!/bin/sh\n"
			. "if [ \"\$1\" = l ]; then printf 'Path = 001.jpg\\nFolder = -\\nSize = 9\\n\\nPath = ComicInfo.xml\\nFolder = -\\nSize = 12\\n'; exit 0; fi\n"
			. "echo 'ERROR: Unsupported Method : 001.jpg' >&2\nexit 2\n";
		file_put_contents($dir . '/7z', $sevenZip);
		chmod($dir . '/7z', 0755);
		if ($withBsdtar) {
			file_put_contents($dir . '/bsdtar', "#!/bin/sh\nprintf 'page data'\n");
			chmod($dir . '/bsdtar', 0755);
		}
		return [$dir, $archive];
	}

	private function removeDir(string $dir): void {
		array_map('unlink', glob($dir . '/*') ?: []);
		rmdir($dir);
	}

	public function testExtractFallsBackToAnotherToolWhenTheListingToolCannotUnpack(): void {
		[$dir, $archive] = $this->fakeRarSetup(true);
		try {
			$tools = new ArchiveTools([$dir]);
			$this->assertSame(['001.jpg', 'ComicInfo.xml'], $tools->list($archive, 'cbr'));
			$this->assertSame('page data', $tools->extract($archive, '001.jpg'));
			$this->assertSame('page data', $tools->extract($archive, 'ComicInfo.xml'));
		} finally {
			$this->removeDir($dir);
		}
	}

	public function testExtractFailureNamesTheToolError(): void {
		[$dir, $archive] = $this->fakeRarSetup(false);
		try {
			$tools = new ArchiveTools([$dir]);
			$this->assertSame(['001.jpg', 'ComicInfo.xml'], $tools->list($archive, 'cbr'));
			try {
				$tools->extract($archive, '001.jpg');
				$this->fail('expected exception');
			} catch (\RuntimeException $e) {
				$this->assertStringContainsString('Unsupported Method', $e->getMessage());
				$this->assertStringContainsString('install unrar or bsdtar', $e->getMessage());
			}
		} finally {
			$this->removeDir($dir);
		}
	}

	public function testRealSevenZipRoundtripIfInstalled(): void {
		$tools = new ArchiveTools();
		if (!$tools->available()['sevenZip']) {
			$this->markTestSkipped('no 7z installed');
		}
		$dir = sys_get_temp_dir() . '/ebr-7z-' . bin2hex(random_bytes(4));
		mkdir($dir . '/src', 0777, true);
		file_put_contents($dir . '/src/0001.jpg', 'page one');
		file_put_contents($dir . '/src/0002.jpg', str_repeat('2', 5000));
		file_put_contents($dir . '/src/ComicInfo.xml', '<ComicInfo/>');
		$archive = $dir . '/out.cb7';
		try {
			$tools->createSevenZip($archive, $dir . '/src');
			$names = $tools->list($archive, 'cb7');
			sort($names);
			$this->assertSame(['0001.jpg', '0002.jpg', 'ComicInfo.xml'], $names);
			$this->assertSame('page one', $tools->extract($archive, '0001.jpg'));
			$this->assertSame(str_repeat('2', 5000), $tools->extract($archive, '0002.jpg'));
		} finally {
			array_map('unlink', glob($dir . '/src/*') ?: []);
			@rmdir($dir . '/src');
			@unlink($archive);
			@rmdir($dir);
		}
	}
}
