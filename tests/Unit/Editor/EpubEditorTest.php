<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Editor;

use OCA\EbookReader\Editor\EditRequest;
use OCA\EbookReader\Editor\EpubEditor;
use OCA\EbookReader\Editor\InvalidEditRequestException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class EpubEditorTest extends TestCase {
	private EpubEditor $editor;
	/** @var list<string> */
	private array $tmp = [];

	protected function setUp(): void {
		$this->editor = new EpubEditor();
	}

	protected function tearDown(): void {
		foreach ($this->tmp as $f) {
			@unlink($f);
		}
	}

	private function src(): string {
		return $this->tmp[] = Fixtures::epub3();
	}

	private function dst(): string {
		return $this->tmp[] = Fixtures::tmp('.epub');
	}

	/** @return list<string> */
	private function entries(string $path): array {
		$z = new ZipArchive();
		$z->open($path);
		$names = [];
		for ($i = 0; $i < $z->numFiles; $i++) {
			$names[] = (string)$z->getNameIndex($i);
		}
		$z->close();
		return $names;
	}

	private function read(string $path, string $name): string {
		$z = new ZipArchive();
		$z->open($path);
		$c = (string)$z->getFromName($name);
		$z->close();
		return $c;
	}

	public function testReadStructure(): void {
		$s = $this->editor->readStructure($this->src(), 'epub');
		$this->assertSame(['ch1', 'ch2', 'ch3'], array_column($s['items'], 'id'));
		$this->assertSame('Chapter One', $s['items'][0]['label']);
		$this->assertSame('OEBPS/text/ch1.xhtml', $s['items'][0]['href']);
		$this->assertFalse($s['items'][2]['linear']);
		$this->assertSame('Test Book', $s['metadata']['title']);
		$this->assertSame(['Jane Doe'], $s['metadata']['authors']);
		$this->assertCount(3, $s['toc']);
		$this->assertSame('ch2', $s['toc'][1]['itemId']);
		$this->assertSame('s1', $s['toc'][1]['children'][0]['fragment']);
		$this->assertTrue($s['capabilities']['writesFile']);
	}

	public function testUnchangedRoundtripKeepsEverything(): void {
		$src = $this->src();
		$dst = $this->dst();
		$res = $this->editor->write($src, $dst, new EditRequest());
		$this->assertSame([], $res['warnings']);
		$this->assertSame($this->entries($src), $this->entries($dst));
		$z = new ZipArchive();
		$z->open($dst);
		$this->assertSame('mimetype', $z->getNameIndex(0));
		$this->assertSame(ZipArchive::CM_STORE, $z->statIndex(0)['comp_method']);
		$z->close();
		$this->assertSame($this->read($src, 'META-INF/encryption.xml'), $this->read($dst, 'META-INF/encryption.xml'));
		$this->assertSame($this->read($src, 'OEBPS/nav.xhtml'), $this->read($dst, 'OEBPS/nav.xhtml'));
		$this->assertSame($this->editor->readStructure($src, 'epub'), $this->editor->readStructure($dst, 'epub'));
	}

	public function testOversizedChapterKeepsResourcesInsteadOfFailing(): void {
		$src = $this->src();
		// Inflate a remaining chapter beyond the 2 MB scan limit of EditorUtil::references()
		$zip = new \ZipArchive();
		$zip->open($src);
		$ch1 = (string)$zip->getFromName('OEBPS/text/ch1.xhtml');
		$zip->addFromString('OEBPS/text/ch1.xhtml', str_replace('</body>', '<!--' . str_repeat('x', 2_200_000) . '--></body>', $ch1));
		$zip->close();

		$dst = $this->dst();
		$res = $this->editor->write($src, $dst, new EditRequest(order: ['ch1', 'ch3'], removed: ['ch2']));

		$entries = $this->entries($dst);
		$this->assertNotContains('OEBPS/text/ch2.xhtml', $entries);
		$this->assertContains('OEBPS/images/pic.png', $entries, 'resources must be kept when a chapter can not be scanned');
		$this->assertNotEmpty(array_filter($res['warnings'], static fn (string $w): bool => str_contains($w, 'too large')));
	}

	public function testRemoveAndReorder(): void {
		$src = $this->src();
		$dst = $this->dst();
		$res = $this->editor->write($src, $dst, new EditRequest(order: ['ch3', 'ch1'], removed: ['ch2']));
		$s = $this->editor->readStructure($dst, 'epub');
		$this->assertSame(['ch3', 'ch1'], array_column($s['items'], 'id'));
		$entries = $this->entries($dst);
		$this->assertNotContains('OEBPS/text/ch2.xhtml', $entries);
		$this->assertNotContains('OEBPS/images/pic.png', $entries, 'orphaned image must be removed');
		$this->assertContains('OEBPS/style/main.css', $entries);
		$this->assertContains('OEBPS/images/cover.png', $entries);
		$this->assertContains('META-INF/encryption.xml', $entries);
		$this->assertNull($res['itemMap']['OEBPS/text/ch2.xhtml']);
		$this->assertSame('OEBPS/text/ch1.xhtml', $res['itemMap']['OEBPS/text/ch1.xhtml']);
		$this->assertNotEmpty($res['warnings'], 'link into a removed chapter must be reported');
		$opf = $this->read($dst, 'OEBPS/content.opf');
		$this->assertStringNotContainsString('ch2', $opf);
		$this->assertStringNotContainsString('pic.png', $opf);
		$nav = $this->read($dst, 'OEBPS/nav.xhtml');
		$this->assertStringNotContainsString('ch2', $nav);
		$this->assertStringContainsString('Chapter One', $nav);
		$ncx = $this->read($dst, 'OEBPS/toc.ncx');
		$this->assertStringNotContainsString('ch2', $ncx);
		$this->assertCount(2, $s['toc']);
	}

	public function testInvalidRequestsAreRejected(): void {
		$src = $this->src();
		$dst = $this->dst();
		$this->expectException(InvalidEditRequestException::class);
		$this->editor->write($src, $dst, new EditRequest(removed: ['nope']));
	}

	public function testOrderMustBePermutation(): void {
		$this->expectException(InvalidEditRequestException::class);
		$this->editor->write($this->src(), $this->dst(), new EditRequest(order: ['ch1', 'ch2']));
	}

	public function testMetadataRoundtrip(): void {
		$src = $this->src();
		$dst = $this->dst();
		$meta = [
			'title' => 'New & Title',
			'authors' => ['Max Mustermann', 'Erika Muster'],
			'series' => 'Saga',
			'seriesIndex' => 2.5,
			'description' => '<p>Hello <b>world</b> &amp; more</p><p>Second</p>',
			'language' => 'de',
			'publisher' => 'Verlag',
			'isbn' => '9783161484100',
			'publishedAt' => '2021-03-04',
			'genres' => ['Fantasy'],
			'tags' => ['Lieblingsbuch', 'Drache'],
		];
		$this->editor->write($src, $dst, new EditRequest(metadata: $meta));
		$m = $this->editor->readStructure($dst, 'epub')['metadata'];
		$this->assertSame('New & Title', $m['title']);
		$this->assertSame(['Max Mustermann', 'Erika Muster'], $m['authors']);
		$this->assertSame('Saga', $m['series']);
		$this->assertSame(2.5, $m['seriesIndex']);
		$this->assertSame('de', $m['language']);
		$this->assertSame('Verlag', $m['publisher']);
		$this->assertSame('9783161484100', $m['isbn']);
		$this->assertSame('2021-03-04', $m['publishedAt']);
		$this->assertSame(['Fantasy', 'Lieblingsbuch', 'Drache'], $m['tags']);
		$this->assertStringContainsString('Hello world & more', (string)$m['description']);
		$this->assertStringNotContainsString('<b>', (string)$m['description']);
		$opf = $this->read($dst, 'OEBPS/content.opf');
		$this->assertStringContainsString('belongs-to-collection', $opf);
		$this->assertStringContainsString('calibre:series', $opf);
		$this->assertStringNotContainsString('refines="#a1"', $opf, 'refinements of removed creators must go');
		$this->assertStringNotContainsString('2020-01-01T00:00:00Z', $opf, 'dcterms:modified must be refreshed');
	}

	public function testMetadataDelete(): void {
		$dst = $this->dst();
		$this->editor->write($this->src(), $dst, new EditRequest(metadata: ['description' => null, 'series' => null, 'tags' => [], 'genres' => []]));
		$m = $this->editor->readStructure($dst, 'epub')['metadata'];
		$this->assertNull($m['description']);
		$this->assertSame([], $m['tags']);
	}

	public function testTocRewrite(): void {
		$dst = $this->dst();
		$toc = [
			['label' => 'Kapitel 3 zuerst', 'itemId' => 'ch3', 'fragment' => null, 'children' => [
				['label' => 'Unterpunkt', 'itemId' => 'ch1', 'fragment' => 'x', 'children' => []],
			]],
			['label' => 'Zwei', 'itemId' => 'ch2', 'fragment' => null, 'children' => []],
		];
		$this->editor->write($this->src(), $dst, new EditRequest(toc: $toc));
		$s = $this->editor->readStructure($dst, 'epub');
		$this->assertSame('Kapitel 3 zuerst', $s['toc'][0]['label']);
		$this->assertSame('ch1', $s['toc'][0]['children'][0]['itemId']);
		$this->assertSame('x', $s['toc'][0]['children'][0]['fragment']);
		$ncx = $this->read($dst, 'OEBPS/toc.ncx');
		$this->assertStringContainsString('playOrder="3"', $ncx);
		$this->assertStringContainsString('content="2"', $ncx);
		$this->assertStringContainsString('Kapitel 3 zuerst', $ncx);
		$this->assertTrue((bool)simplexml_load_string($ncx));
	}

	public function testCoverUpload(): void {
		$dst = $this->dst();
		$cover = ['source' => 'upload', 'data' => Fixtures::PNG_B64];
		$this->editor->write($this->src(), $dst, new EditRequest(cover: $cover));
		$entries = $this->entries($dst);
		$this->assertContains('OEBPS/images/ebr-cover.png', $entries);
		$this->assertNotContains('OEBPS/images/cover.png', $entries, 'the replaced cover is orphaned');
		$opf = $this->read($dst, 'OEBPS/content.opf');
		$this->assertStringContainsString('properties="cover-image"', $opf);
		$this->assertSame(1, substr_count($opf, 'cover-image'));
		$this->assertStringContainsString('content="ebr-cover"', $opf);
	}

	public function testCoverUploadRejectsNonImage(): void {
		$this->expectException(InvalidEditRequestException::class);
		$this->editor->write($this->src(), $this->dst(), new EditRequest(cover: ['source' => 'upload', 'data' => base64_encode('not an image')]));
	}
}
