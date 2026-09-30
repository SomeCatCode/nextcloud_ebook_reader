<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Metadata\Fb2Extractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures.php';

class Fb2ExtractorTest extends TestCase {
	public function testSupports(): void {
		$e = new Fb2Extractor();
		$this->assertTrue($e->supports('fb2'));
		$this->assertTrue($e->supports('fbz'));
		$this->assertFalse($e->supports('cbz'));
	}

	#[DataProvider('files')]
	public function testExtract(string $file): void {
		$m = (new Fb2Extractor())->extract(Fixtures::path($file));
		$this->assertSame('Das FB2-Buch', $m->title);
		$this->assertSame(['Ivan I. Petrov'], $m->authors);
		$this->assertSame('FB2-Serie', $m->series);
		$this->assertSame(7.0, $m->seriesIndex);
		$this->assertSame('de', $m->language);
		$this->assertSame('FB2 Verlag', $m->publisher);
		$this->assertSame('9780306406157', $m->isbn);
		$this->assertSame('2016', $m->publishedAt);
		$this->assertSame(['Fantasy', 'Krimi'], $m->genres, 'genre codes mapped to German labels');
		$this->assertSame(['Drachen', 'Magie', 'Lieblingsbuch', 'no_such_code'], $m->tags);
		$this->assertSame('<p>Eine <strong>kurze</strong> Beschreibung.</p><br><p>Noch ein <em>Absatz</em>.</p>', $m->description);
		$this->assertNotNull($m->coverData);
		$this->assertSame('image/png', $m->coverMime);
	}

	/** @return array<string, list<string>> */
	public static function files(): array {
		return ['plain fb2' => ['book.fb2'], 'zipped fb2' => ['book.fb2.zip']];
	}

	public function testMalformedXmlThrows(): void {
		$f = Fixtures::temp('.fb2');
		file_put_contents($f, '<FictionBook><description>');
		$this->expectException(\RuntimeException::class);
		(new Fb2Extractor())->extract($f);
	}

	public function testEntityDeclarationsAreRejected(): void {
		$f = Fixtures::temp('.fb2');
		file_put_contents($f, '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "aaaa">]><FictionBook><description><title-info><book-title>&a;</book-title></title-info></description></FictionBook>');
		$this->expectException(\RuntimeException::class);
		(new Fb2Extractor())->extract($f);
	}
}
