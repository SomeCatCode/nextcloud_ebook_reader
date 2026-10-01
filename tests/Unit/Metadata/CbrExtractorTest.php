<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Metadata\CbrExtractor;
use OCA\EbookReader\Metadata\CbzExtractor;
use OCA\EbookReader\Metadata\ComicArchive;
use OCA\EbookReader\Metadata\MetadataService;
use OCA\EbookReader\Metadata\TarArchive;
use OCA\EbookReader\Service\ArchiveTools;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures.php';

class CbrExtractorTest extends TestCase {
	/** @return array{0: string, 1: string} the unpacked directory and a CBT made from the fixture CBZ */
	private function cbtFromFixture(string $fixture): array {
		$dir = sys_get_temp_dir() . '/ebr-cbt-' . bin2hex(random_bytes(4));
		mkdir($dir);
		$zip = new \ZipArchive();
		$zip->open(Fixtures::path($fixture));
		$zip->extractTo($dir);
		$zip->close();
		$files = [];
		foreach (scandir($dir) ?: [] as $f) {
			if ($f !== '.' && $f !== '..') {
				$files[$f] = $dir . '/' . $f;
			}
		}
		$cbt = Fixtures::temp('.cbt');
		TarArchive::write($cbt, $files);
		register_shutdown_function(static function () use ($files, $dir): void {
			array_map('unlink', $files);
			@rmdir($dir);
		});
		return [$dir, $cbt];
	}

	public function testSupports(): void {
		$e = new CbrExtractor();
		foreach (['cbr', 'cb7', 'cbt'] as $f) {
			$this->assertTrue($e->supports($f));
		}
		$this->assertFalse($e->supports('cbz'));
	}

	public function testCbtMetadataAndCoverMatchCbz(): void {
		[, $cbt] = $this->cbtFromFixture('comic.cbz');
		$m = (new CbrExtractor(new ArchiveTools([])))->extract($cbt, 'cbt');
		$ref = (new CbzExtractor())->extract(Fixtures::path('comic.cbz'));
		$this->assertSame('Comic Title', $m->title);
		$this->assertSame('Comic Series', $m->series);
		$this->assertSame(12.0, $m->seriesIndex);
		$this->assertSame(['Alice Writer', 'Bob Writer'], $m->authors);
		$this->assertSame($ref->genres, $m->genres);
		$this->assertSame($ref->description, $m->description);
		$this->assertSame($ref->publishedAt, $m->publishedAt);
		$this->assertNotNull($m->coverData);
		$this->assertSame($ref->coverData, $m->coverData, 'cover is the FrontCover page');
	}

	public function testCbtWithoutComicInfoUsesFirstNaturalImage(): void {
		[, $cbt] = $this->cbtFromFixture('plain.cbz');
		$m = (new CbrExtractor(new ArchiveTools([])))->extract($cbt, 'cbt');
		$ref = (new CbzExtractor())->extract(Fixtures::path('plain.cbz'));
		$this->assertNull($m->title);
		$this->assertSame($ref->coverData, $m->coverData);
	}

	public function testCbrWithoutToolGivesEmptyMetadataAndFilenameFallback(): void {
		$tools = new ArchiveTools([]);
		$this->assertEquals(0, count((new CbrExtractor($tools))->extract(Fixtures::path('plain.cbz'), 'cbr')->authors));
		$service = new MetadataService(null, null, $tools);
		$m = $service->extractLocal(Fixtures::path('plain.cbz'), 'cbr', 'Autor - Nur Dateiname.cbr');
		$this->assertSame('Nur Dateiname', $m->title);
		$this->assertNull($m->coverData);
	}

	public function testComicArchivePagesAreNaturallySorted(): void {
		[, $cbt] = $this->cbtFromFixture('plain.cbz');
		$a = ComicArchive::open($cbt, 'cbt');
		$pages = $a->pages();
		$sorted = $pages;
		usort($sorted, static fn (string $x, string $y): int => strnatcasecmp($x, $y));
		$this->assertSame($sorted, $pages);
		$this->assertNotEmpty($pages);
		$a->close();
	}

	public function testCb7WithRealSevenZipIfInstalled(): void {
		$tools = new ArchiveTools();
		if (!$tools->canRead('cb7') || !$tools->canWrite('cb7')) {
			$this->markTestSkipped('no 7z installed');
		}
		[$dir] = $this->cbtFromFixture('comic.cbz');
		$cb7 = sys_get_temp_dir() . '/ebr-' . bin2hex(random_bytes(4)) . '.cb7';
		try {
			$tools->createSevenZip($cb7, $dir);
			$m = (new CbrExtractor($tools))->extract($cb7, 'cb7');
			$this->assertSame('Comic Title', $m->title);
			$this->assertNotNull($m->coverData);
			$this->assertSame((new CbzExtractor())->extract(Fixtures::path('comic.cbz'))->coverData, $m->coverData);
		} finally {
			@unlink($cb7);
		}
	}
}
