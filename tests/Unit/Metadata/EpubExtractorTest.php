<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Metadata\EpubExtractor;
use OCA\EbookReader\Metadata\ImageUtil;
use OCA\EbookReader\Metadata\UnsafeArchiveException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures.php';

class EpubExtractorTest extends TestCase {
	public function testSupports(): void {
		$e = new EpubExtractor();
		$this->assertTrue($e->supports('epub'));
		$this->assertFalse($e->supports('mobi'));
	}

	public function testEpub2WithNcx(): void {
		$m = (new EpubExtractor())->extract(Fixtures::path('epub2.epub'));
		$this->assertSame('Der Test-Roman', $m->title);
		$this->assertSame(['Erika Mustermann'], $m->authors, 'illustrator must not be listed as author');
		$this->assertSame('de', $m->language);
		$this->assertSame('Testverlag', $m->publisher);
		$this->assertSame('2019-05-17', $m->publishedAt);
		$this->assertSame('9783161484100', $m->isbn);
		$this->assertSame('Die Testreihe', $m->series);
		$this->assertSame(2.0, $m->seriesIndex);
		$this->assertSame(['Fantasy', 'Lieblingsbuch'], $m->subjects);
		$this->assertSame('<p>Ein <b>spannender</b> Roman.</p><p>Zweiter Absatz.</p>', $m->description);
		$this->assertNotNull($m->coverData);
		$this->assertSame('image/png', $m->coverMime);
		$this->assertSame('image/png', ImageUtil::mime((string)$m->coverData));
	}

	public function testEpub3WithNavAndCoverImageProperty(): void {
		$m = (new EpubExtractor())->extract(Fixtures::path('epub3.epub'));
		$this->assertSame('The Third Book', $m->title);
		$this->assertSame(['Jane Doe', 'John Roe'], $m->authors);
		$this->assertSame('9783161484100', $m->isbn, 'urn:isbn: identifier');
		$this->assertSame('Space Saga', $m->series);
		$this->assertSame(3.0, $m->seriesIndex);
		$this->assertSame(['Science Fiction', 'Space Opera', 'Crime'], $m->subjects);
		$this->assertSame('<p>Plain text description.</p><p>Second paragraph &amp; more.</p>', $m->description);
		$this->assertNotNull($m->coverData);
	}

	public function testEpub3BelongsToCollection(): void {
		$m = (new EpubExtractor())->extract(Fixtures::path('epub3-collection.epub'));
		$this->assertSame('Great Series', $m->series);
		$this->assertSame(4.5, $m->seriesIndex);
		$this->assertNull($m->coverData);
		$this->assertNull($m->description);
	}

	public function testNotAnEpubThrows(): void {
		$this->expectException(UnsafeArchiveException::class);
		(new EpubExtractor())->extract(Fixtures::path('comic.cbz'));
	}

	public function testTypicalAgeRangeBecomesAgeRating(): void {
		$path = Fixtures::temp('.epub');
		$opf = '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
			. '<dc:title>Teen book</dc:title><meta property="schema:typicalAgeRange">13-17</meta></metadata></package>';
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		$zip->addFromString('META-INF/container.xml', '<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="a.opf"/></rootfiles></container>');
		$zip->addFromString('a.opf', $opf);
		$zip->close();
		$m = (new EpubExtractor())->extract($path);
		$this->assertSame(16, $m->ageRating);
		// the fixtures have no age range
		$this->assertNull((new EpubExtractor())->extract(Fixtures::path('epub3.epub'))->ageRating);
	}

	public function testEntityDeclarationIsRejected(): void {
		$path = Fixtures::temp('.epub');
		$opf = '<?xml version="1.0"?><!DOCTYPE p [<!ENTITY x SYSTEM "file:///etc/passwd">]><package xmlns="http://www.idpf.org/2007/opf"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>&x;</dc:title></metadata></package>';
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		$zip->addFromString('META-INF/container.xml', '<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="a.opf"/></rootfiles></container>');
		$zip->addFromString('a.opf', $opf);
		$zip->close();
		$this->expectException(UnsafeArchiveException::class);
		(new EpubExtractor())->extract($path);
	}
}
