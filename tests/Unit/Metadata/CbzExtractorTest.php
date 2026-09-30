<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Metadata\CbzExtractor;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures.php';

class CbzExtractorTest extends TestCase {
	public function testSupports(): void {
		$this->assertTrue((new CbzExtractor())->supports('cbz'));
		$this->assertFalse((new CbzExtractor())->supports('cbr'));
	}

	public function testComicInfo(): void {
		$m = (new CbzExtractor())->extract(Fixtures::path('comic.cbz'));
		$this->assertSame('Comic Title', $m->title);
		$this->assertSame('Comic Series', $m->series);
		$this->assertSame(12.0, $m->seriesIndex);
		$this->assertSame(['Alice Writer', 'Bob Writer'], $m->authors);
		$this->assertSame('Comic Publisher', $m->publisher);
		$this->assertSame('2018-09-03', $m->publishedAt);
		$this->assertSame('en', $m->language);
		$this->assertSame(['Superhero', 'Action'], $m->genres);
		$this->assertSame(['Lieblingsserie', 'Klassiker'], $m->tags);
		$this->assertSame('<p>A comic summary.</p><p>With two paragraphs.</p>', $m->description);
	}

	public function testCoverIsFrontCoverPage(): void {
		$m = (new CbzExtractor())->extract(Fixtures::path('comic.cbz'));
		$this->assertNotNull($m->coverData);
		$img = imagecreatefromstring((string)$m->coverData);
		$this->assertNotFalse($img);
		$rgb = imagecolorat($img, 1, 1);
		$this->assertSame([0, 0, 255], [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255], 'third page (blue) is FrontCover');
	}

	public function testCoverIsFirstImageInNaturalOrder(): void {
		$m = (new CbzExtractor())->extract(Fixtures::path('plain.cbz'));
		$this->assertNull($m->title);
		$this->assertNotNull($m->coverData);
		$img = imagecreatefromstring((string)$m->coverData);
		$this->assertNotFalse($img);
		$rgb = imagecolorat($img, 1, 1);
		$this->assertSame(0xFFFFFF, $rgb & 0xFFFFFF, 'page2 (white) sorts before page10 (black)');
	}
}
