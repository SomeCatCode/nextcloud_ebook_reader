<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Editor;

use OCA\EbookReader\Editor\CbzEditor;
use OCA\EbookReader\Editor\EditRequest;
use OCA\EbookReader\Editor\EpubEditor;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** Metadata-only writes copy every other entry raw: compressed size and CRC stay exactly as they were. */
class RawCopyTest extends TestCase {
	/** @var list<string> */
	private array $tmp = [];

	protected function tearDown(): void {
		foreach ($this->tmp as $f) {
			@unlink($f);
		}
	}

	private function tmp(string $ext): string {
		return $this->tmp[] = Fixtures::tmp($ext);
	}

	public function testCbzMetadataWriteKeepsPageBytesAndCompression(): void {
		$src = $this->tmp('.cbz');
		$z = new ZipArchive();
		$z->open($src, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		$pages = [];
		mt_srand(42);
		foreach (['page1.png' => 300000, 'page2.png' => 150000, 'page3.png' => 1000] as $name => $size) {
			// large, effectively incompressible "image" data
			$data = '';
			for ($i = 0; $i < $size; $i += 4) {
				$data .= pack('N', mt_rand());
			}
			$pages[$name] = $data;
			$z->addFromString($name, $data);
			$z->setCompressionName($name, $name === 'page3.png' ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE);
		}
		$z->addFromString('ComicInfo.xml', '<?xml version="1.0"?><ComicInfo><Title>Old</Title></ComicInfo>');
		$z->close();
		$before = self::statsOf($src);

		$dst = $this->tmp('.cbz');
		$res = (new CbzEditor())->write($src, $dst, new EditRequest(metadata: ['title' => 'New title', 'authors' => ['Jane']]));
		$this->assertSame([], $res['itemMap']);
		$after = self::statsOf($dst);

		foreach (array_keys($pages) as $name) {
			$this->assertSame($before[$name], $after[$name], 'raw entry ' . $name . ' must be unchanged');
		}
		$this->assertSame(array_keys($before), array_keys($after), 'order and names stay');
		$this->assertNotSame($before['ComicInfo.xml']['crc'], $after['ComicInfo.xml']['crc']);

		$check = new ZipArchive();
		$check->open($dst);
		$this->assertStringContainsString('New title', (string)$check->getFromName('ComicInfo.xml'));
		$this->assertSame($pages['page1.png'], $check->getFromName('page1.png'));
		$check->close();
	}

	public function testCbzWithoutComicInfoGetsOne(): void {
		$src = $this->tmp('.cbz');
		$z = new ZipArchive();
		$z->open($src, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		$z->addFromString('1.png', Fixtures::png());
		$z->close();
		$dst = $this->tmp('.cbz');
		(new CbzEditor())->write($src, $dst, new EditRequest(metadata: ['title' => 'T']));
		$check = new ZipArchive();
		$check->open($dst);
		$this->assertStringContainsString('<Title>T</Title>', (string)$check->getFromName('ComicInfo.xml'));
		$check->close();
	}

	public function testEpubMetadataWriteKeepsEveryOtherEntryRaw(): void {
		$src = $this->tmp('.epub');
		copy(Fixtures::epub3(), $src);
		$z = new ZipArchive();
		$z->open($src);
		mt_srand(7);
		$big = '';
		for ($i = 0; $i < 200000; $i += 4) {
			$big .= pack('N', mt_rand());
		}
		$z->addFromString('OEBPS/big.jpg', $big);
		$z->setCompressionName('OEBPS/big.jpg', ZipArchive::CM_DEFLATE);
		$z->close();
		$before = self::statsOf($src);

		$dst = $this->tmp('.epub');
		(new EpubEditor())->write($src, $dst, new EditRequest(metadata: ['title' => 'Raw title']));
		$after = self::statsOf($dst);

		$this->assertSame(array_keys($before), array_keys($after));
		foreach ($before as $name => $st) {
			if ($name === 'OEBPS/content.opf') {
				$this->assertSame(ZipArchive::CM_DEFLATE, $after[$name]['comp_method']);
				continue;
			}
			$this->assertSame($st, $after[$name], 'raw entry ' . $name . ' must be unchanged');
		}
		$check = new ZipArchive();
		$check->open($dst);
		$this->assertSame('mimetype', $check->getNameIndex(0));
		$this->assertSame(ZipArchive::CM_STORE, $check->statIndex(0)['comp_method'] ?? -1);
		$this->assertStringContainsString('Raw title', (string)$check->getFromName('OEBPS/content.opf'));
		$check->close();
	}

	public function testProgressCallbackIsCalled(): void {
		$calls = [];
		$dst = $this->tmp('.cbz');
		$src = $this->tmp('.cbz');
		copy(Fixtures::cbz(3), $src);
		$ids = array_column((new CbzEditor())->readStructure($src, 'cbz')['items'], 'id');
		(new CbzEditor())->write($src, $dst, new EditRequest(order: array_reverse($ids), metadata: ['title' => 'x']), function (float $f, string $s) use (&$calls): void {
			$calls[] = [$f, $s];
		});
		$this->assertNotSame([], $calls);
		foreach ($calls as [$f, $s]) {
			$this->assertGreaterThanOrEqual(0.0, $f);
			$this->assertLessThanOrEqual(1.0, $f);
			$this->assertNotSame('', $s);
		}
	}

	/** @return array<string, array{comp_size: int, crc: int, comp_method: int, size: int}> */
	private static function statsOf(string $path): array {
		$z = new ZipArchive();
		$z->open($path);
		$out = [];
		for ($i = 0; $i < $z->numFiles; $i++) {
			$st = $z->statIndex($i);
			if ($st === false) {
				continue;
			}
			$out[(string)$st['name']] = ['comp_size' => (int)$st['comp_size'], 'crc' => (int)$st['crc'], 'comp_method' => (int)$st['comp_method'], 'size' => (int)$st['size']];
		}
		$z->close();
		return $out;
	}
}
