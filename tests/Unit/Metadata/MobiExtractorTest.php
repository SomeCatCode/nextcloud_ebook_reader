<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Metadata\DrmProtectedException;
use OCA\EbookReader\Metadata\MobiExtractor;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures.php';

class MobiExtractorTest extends TestCase {
	public function testSupports(): void {
		$e = new MobiExtractor();
		$this->assertTrue($e->supports('mobi'));
		$this->assertTrue($e->supports('azw3'));
		$this->assertFalse($e->supports('epub'));
	}

	public function testExth(): void {
		$m = (new MobiExtractor())->extract(Fixtures::path('book.mobi'));
		$this->assertSame('Das MOBI-Buch', $m->title);
		$this->assertSame(['Karl Kindle'], $m->authors);
		$this->assertSame('Mobi Verlag', $m->publisher);
		$this->assertSame('9783161484100', $m->isbn);
		$this->assertSame('2012-07-08', $m->publishedAt);
		$this->assertSame('de', $m->language);
		$this->assertSame(['Fantasy', 'Drachen', 'Magie'], $m->subjects);
		$this->assertSame('<p>MOBI <b>Beschreibung</b></p>', $m->description);
		$this->assertNotNull($m->coverData);
		$this->assertSame('image/png', $m->coverMime);
	}

	public function testDrmThrows(): void {
		$this->expectException(DrmProtectedException::class);
		(new MobiExtractor())->extract(Fixtures::path('drm.mobi'));
	}

	public function testGarbageThrows(): void {
		$f = Fixtures::temp('.mobi');
		file_put_contents($f, str_repeat('x', 500));
		$this->expectException(\RuntimeException::class);
		(new MobiExtractor())->extract($f);
	}
}
