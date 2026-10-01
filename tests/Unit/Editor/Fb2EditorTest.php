<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Editor;

use OCA\EbookReader\Editor\EditRequest;
use OCA\EbookReader\Editor\Fb2Editor;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class Fb2EditorTest extends TestCase {
	/** @var list<string> */
	private array $tmp = [];

	protected function tearDown(): void {
		foreach ($this->tmp as $f) {
			@unlink($f);
		}
	}

	private function src(bool $zipped = false): string {
		return $this->tmp[] = Fixtures::fb2($zipped);
	}

	private function dst(string $ext): string {
		return $this->tmp[] = Fixtures::tmp($ext);
	}

	public function testStructure(): void {
		$s = (new Fb2Editor())->readStructure($this->src(), 'fb2');
		$this->assertSame(['b0/s0', 'b0/s1', 'b0/s2'], array_column($s['items'], 'id'));
		$this->assertSame('One', $s['items'][0]['label']);
		$this->assertSame('Abschnitt 3', $s['items'][2]['label']);
		$this->assertSame('Old Title', $s['metadata']['title']);
		$this->assertSame(['Ann Author'], $s['metadata']['authors']);
		$this->assertNotSame([], $s['metadata']['genres']);
		$this->assertSame('b0/s1/s0', $s['toc'][1]['children'][0]['itemId']);
	}

	public function testEditMetadataReorderRemoveAndTitles(): void {
		$e = new Fb2Editor();
		$dst = $this->dst('.fb2');
		$res = $e->write($this->src(), $dst, new EditRequest(
			metadata: [
				'title' => 'Neuer Titel',
				'authors' => ['Erika Muster', 'Cher'],
				'series' => 'Reihe',
				'seriesIndex' => 3,
				'description' => '<p>Erster</p><p>Zweiter</p>',
				'language' => 'en',
				'publisher' => 'Verlag',
				'isbn' => '123',
				'publishedAt' => '2020-05-01',
				'genres' => ['Krimi', 'Unbekanntes Genre'],
				'tags' => ['Tag1'],
			],
			order: ['b0/s2', 'b0/s0'],
			removed: ['b0/s1'],
			toc: [['label' => 'Umbenannt', 'itemId' => 'b0/s0', 'children' => []]],
		));
		$xml = (string)file_get_contents($dst);
		$this->assertTrue((bool)simplexml_load_string($xml));
		$s = $e->readStructure($dst, 'fb2');
		$this->assertSame(['Abschnitt 1', 'Umbenannt'], array_column($s['items'], 'label'));
		$this->assertSame('b0/s1', $res['itemMap']['b0/s0']);
		$this->assertSame('b0/s0', $res['itemMap']['b0/s2']);
		$this->assertNull($res['itemMap']['b0/s1']);
		$m = $s['metadata'];
		$this->assertSame('Neuer Titel', $m['title']);
		$this->assertSame(['Erika Muster', 'Cher'], $m['authors']);
		$this->assertSame('Reihe', $m['series']);
		$this->assertSame(3.0, $m['seriesIndex']);
		$this->assertSame("Erster\n\nZweiter", $m['description']);
		$this->assertSame('en', $m['language']);
		$this->assertSame('Verlag', $m['publisher']);
		$this->assertSame('123', $m['isbn']);
		$this->assertSame('2020-05-01', $m['publishedAt']);
		$this->assertCount(1, $m['genres']);
		$this->assertSame(['Tag1', 'Unbekanntes Genre'], $m['tags']);
		$this->assertStringContainsString('<genre>detective</genre>', $xml);
		$this->assertStringContainsString('name="notes"', $xml, 'the notes body stays');
		// schema order inside title-info: genre, author, book-title, annotation, keywords, date, lang, sequence
		$this->assertLessThan(strpos($xml, '<book-title>'), strpos($xml, '<author>'));
		$this->assertLessThan(strpos($xml, '<lang>'), strpos($xml, '<annotation>'));
		$this->assertLessThan(strpos($xml, '<sequence'), strpos($xml, '<lang>'));
	}

	public function testCoverUpload(): void {
		$e = new Fb2Editor();
		$dst = $this->dst('.fb2');
		$e->write($this->src(), $dst, new EditRequest(cover: ['source' => 'upload', 'data' => Fixtures::PNG_B64]));
		$xml = (string)file_get_contents($dst);
		$this->assertStringContainsString('<binary id="cover-ebr.png" content-type="image/png">', $xml);
		$this->assertMatchesRegularExpression('/<coverpage><image [^>]*href="#cover-ebr.png"/', $xml);
	}

	public function testFbzRoundtrip(): void {
		$e = new Fb2Editor();
		$dst = $this->dst('.fbz');
		$e->write($this->src(true), $dst, new EditRequest(metadata: ['title' => 'Zipped'], removed: ['b0/s0']));
		$z = new ZipArchive();
		$this->assertTrue($z->open($dst));
		$this->assertNotFalse($z->locateName('book.fb2'));
		$z->close();
		$s = $e->readStructure($dst, 'fbz');
		$this->assertSame('Zipped', $s['metadata']['title']);
		$this->assertCount(2, $s['items']);
	}

	public function testMetadataOnlyFastPath(): void {
		foreach ([false, true] as $zipped) {
			$src = $this->src($zipped);
			$dst = $this->tmp[] = Fixtures::tmp($zipped ? '.fbz' : '.fb2');
			$res = (new Fb2Editor())->write($src, $dst, new EditRequest(metadata: ['title' => 'Quick']));
			$this->assertSame([], $res['itemMap']);
			$before = (new Fb2Editor())->readStructure($src, $zipped ? 'fbz' : 'fb2');
			$after = (new Fb2Editor())->readStructure($dst, $zipped ? 'fbz' : 'fb2');
			$this->assertSame($before['items'], $after['items']);
			$this->assertSame($before['toc'], $after['toc']);
			$this->assertSame('Quick', $after['metadata']['title']);
		}
	}
}
