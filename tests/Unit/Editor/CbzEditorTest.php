<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Editor;

use OCA\EbookReader\Editor\CbzEditor;
use OCA\EbookReader\Editor\EditRequest;
use OCA\EbookReader\Editor\InvalidEditRequestException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class CbzEditorTest extends TestCase {
	/** @var list<string> */
	private array $tmp = [];

	protected function tearDown(): void {
		foreach ($this->tmp as $f) {
			@unlink($f);
		}
	}

	private function src(bool $info = false): string {
		return $this->tmp[] = Fixtures::cbz(4, $info);
	}

	private function dst(): string {
		return $this->tmp[] = Fixtures::tmp('.cbz');
	}

	public function testStructureNaturalOrderAndComicInfo(): void {
		$s = (new CbzEditor())->readStructure($this->src(true), 'cbz');
		$this->assertSame(['page1.png', 'page2.png', 'page3.png', 'page4.png', 'page10.png'], array_column($s['items'], 'id'));
		$this->assertSame('Old', $s['metadata']['title']);
		$this->assertSame(['A', 'B'], $s['metadata']['authors']);
		$this->assertSame(2.0, $s['metadata']['seriesIndex']);
		$this->assertSame('2001-05', $s['metadata']['publishedAt']);
		$this->assertSame('Act 2', $s['toc'][0]['label']);
		$this->assertSame('page3.png', $s['toc'][0]['itemId']);
	}

	public function testReorderRemoveRenameAndComicInfo(): void {
		$e = new CbzEditor();
		$dst = $this->dst();
		$res = $e->write($this->src(true), $dst, new EditRequest(
			metadata: ['title' => 'New', 'authors' => ['X'], 'genres' => ['Action'], 'tags' => ['t1'], 'description' => '<p>Sum</p>'],
			order: ['page10.png', 'page3.png', 'page1.png', 'page4.png'],
			removed: ['page2.png'],
			toc: [['label' => 'Start', 'itemId' => 'page10.png', 'children' => []], ['label' => 'Mitte', 'itemId' => 'page4.png', 'children' => []]],
		));
		$z = new ZipArchive();
		$z->open($dst);
		$names = [];
		for ($i = 0; $i < $z->numFiles; $i++) {
			$names[] = (string)$z->getNameIndex($i);
		}
		$this->assertContains('0001.png', $names);
		$this->assertContains('0004.png', $names);
		$this->assertContains('ComicInfo.xml', $names);
		$this->assertNotContains('page1.png', $names);
		$this->assertStringEndsWith('ten', (string)$z->getFromName('0001.png'));
		$this->assertStringEndsWith('3', (string)$z->getFromName('0002.png'));
		$z->close();
		$this->assertSame('0001.png', $res['itemMap']['page10.png']);
		$this->assertSame('0003.png', $res['itemMap']['page1.png']);
		$this->assertNull($res['itemMap']['page2.png']);

		$s = $e->readStructure($dst, 'cbz');
		$this->assertCount(4, $s['items']);
		$this->assertSame('New', $s['metadata']['title']);
		$this->assertSame(['X'], $s['metadata']['authors']);
		$this->assertSame(['Action'], $s['metadata']['genres']);
		$this->assertSame(['t1'], $s['metadata']['tags']);
		$this->assertSame('Sum', $s['metadata']['description']);
		$this->assertSame(['Start', 'Mitte'], array_column($s['toc'], 'label'));
		$this->assertSame(['0001.png', '0004.png'], array_column($s['toc'], 'itemId'));
		$z->open($dst);
		$xml = (string)$z->getFromName('ComicInfo.xml');
		$z->close();
		$this->assertStringContainsString('<PageCount>4</PageCount>', $xml);
		$this->assertStringContainsString('Type="FrontCover"', $xml);
	}

	public function testCoverUploadBecomesFirstPage(): void {
		$e = new CbzEditor();
		$dst = $this->dst();
		$e->write($this->src(), $dst, new EditRequest(cover: ['source' => 'upload', 'data' => Fixtures::PNG_B64]));
		$s = $e->readStructure($dst, 'cbz');
		$this->assertCount(6, $s['items']);
		$z = new ZipArchive();
		$z->open($dst);
		$this->assertSame(Fixtures::png(), $z->getFromName('0001.png'));
		$xml = (string)$z->getFromName('ComicInfo.xml');
		$z->close();
		$this->assertMatchesRegularExpression('/<Page Image="0" Type="FrontCover"\/>/', $xml);
		$this->assertStringContainsString('<PageCount>6</PageCount>', $xml);
	}

	public function testInvalidOrder(): void {
		$this->expectException(InvalidEditRequestException::class);
		(new CbzEditor())->write($this->src(), $this->dst(), new EditRequest(order: ['page1.png', 'bogus.png', 'page2.png', 'page3.png', 'page4.png']));
	}

	public function testCannotRemoveEverything(): void {
		$this->expectException(InvalidEditRequestException::class);
		(new CbzEditor())->write($this->src(), $this->dst(), new EditRequest(removed: ['page1.png', 'page2.png', 'page3.png', 'page4.png', 'page10.png']));
	}
}
