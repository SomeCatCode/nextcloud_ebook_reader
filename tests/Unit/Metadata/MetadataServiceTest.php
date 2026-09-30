<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Metadata\MetadataService;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures.php';

class MetadataServiceTest extends TestCase {
	#[DataProvider('formats')]
	public function testDetectFormat(string $name, string $mime, ?string $expected): void {
		$this->assertSame($expected, (new MetadataService())->detectFormat($name, $mime));
	}

	/** @return array<string, array{string, string, ?string}> */
	public static function formats(): array {
		return [
			'epub' => ['a.epub', 'application/epub+zip', 'epub'],
			'upper' => ['A.EPUB', 'application/octet-stream', 'epub'],
			'mobi' => ['a.mobi', '', 'mobi'],
			'azw3' => ['a.azw3', '', 'azw3'],
			'fb2' => ['a.fb2', 'text/plain', 'fb2'],
			'fb2.zip' => ['a.fb2.zip', 'application/zip', 'fbz'],
			'plain zip' => ['a.zip', 'application/zip', null],
			'cbz' => ['a.cbz', '', 'cbz'],
			'cbr' => ['a.cbr', '', 'cbr'],
			'pdf' => ['a.pdf', 'application/pdf', null],
			'by mime core comic' => ['noext', 'application/comicbook+zip', 'cbz'],
			'by mime contract comic' => ['noext', 'application/vnd.comicbook-rar', 'cbr'],
			'by mime epub' => ['noext', 'application/epub+zip', 'epub'],
			'unknown' => ['a.txt', 'text/plain', null],
		];
	}

	public function testExtractLocalDelegates(): void {
		$m = (new MetadataService())->extractLocal(Fixtures::path('epub2.epub'), 'epub', 'x.epub');
		$this->assertSame('Der Test-Roman', $m->title);
	}

	public function testBrokenFileFallsBackToFilename(): void {
		$f = Fixtures::temp('.epub');
		file_put_contents($f, 'not a zip');
		$m = (new MetadataService())->extractLocal($f, 'epub', 'Jane Doe - Broken Book.epub');
		$this->assertSame('Broken Book', $m->title);
		$this->assertSame(['Jane Doe'], $m->authors);
	}

	public function testDrmFallsBackToFilename(): void {
		$m = (new MetadataService())->extractLocal(Fixtures::path('drm.mobi'), 'mobi', 'Karl - Geschuetzt.mobi');
		$this->assertSame('Geschuetzt', $m->title);
	}

	public function testMissingTitleIsFilledFromFilename(): void {
		$m = (new MetadataService())->extractLocal(Fixtures::path('plain.cbz'), 'cbz', 'Autor - Heft 1.cbz');
		$this->assertSame('Heft 1', $m->title);
		$this->assertSame(['Autor'], $m->authors);
		$this->assertNotNull($m->coverData, 'cover from extractor is kept');
	}

	public function testCbrUsesFilenameOnly(): void {
		$m = (new MetadataService())->extractLocal(Fixtures::path('plain.cbz'), 'cbr', 'Sammelband.cbr');
		$this->assertSame('Sammelband', $m->title);
		$this->assertNull($m->coverData);
	}

	public function testExtractFromNextcloudFile(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('epub3.epub');
		$file->method('fopen')->willReturnCallback(static fn () => fopen(Fixtures::path('epub3.epub'), 'rb'));
		$m = (new MetadataService())->extract($file, 'epub');
		$this->assertSame('The Third Book', $m->title);
	}
}
