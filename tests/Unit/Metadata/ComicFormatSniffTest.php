<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Metadata\ComicArchive;
use OCA\EbookReader\Service\ArchiveTools;
use PHPUnit\Framework\TestCase;

class ComicFormatSniffTest extends TestCase {
	public function testFormatFromHeader(): void {
		$this->assertSame('cbz', ComicArchive::formatFromHeader("PK\x03\x04rest"));
		$this->assertSame('cbr', ComicArchive::formatFromHeader("Rar!\x1A\x07\x01\x00"));
		$this->assertSame('cb7', ComicArchive::formatFromHeader("7z\xBC\xAF\x27\x1C\x00\x04"));
		$this->assertSame('cbt', ComicArchive::formatFromHeader(str_repeat("\0", 257) . "ustar\0" . str_repeat("\0", 250)));
		$this->assertNull(ComicArchive::formatFromHeader('just text'));
	}

	public function testActualFormatPrefersContentOverExtension(): void {
		$path = tempnam(sys_get_temp_dir(), 'ebrt');
		$this->assertIsString($path);
		try {
			file_put_contents($path, "PK\x03\x04" . str_repeat('x', 100));
			$this->assertSame('cbz', ComicArchive::actualFormat($path, 'cbr'));
			// non-comic formats are never touched
			$this->assertSame('epub', ComicArchive::actualFormat($path, 'epub'));
			file_put_contents($path, 'unknown content');
			$this->assertSame('cbr', ComicArchive::actualFormat($path, 'cbr'));
		} finally {
			@unlink($path);
		}
		$this->assertSame('cb7', ComicArchive::actualFormat('/does/not/exist', 'cb7'));
	}

	public function testOpenReadsZipNamedCbrWithoutAnyTool(): void {
		if (!class_exists(\ZipArchive::class)) {
			$this->markTestSkipped('zip extension missing');
		}
		$path = tempnam(sys_get_temp_dir(), 'ebrt') . '.cbr';
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		$zip->addFromString('001.jpg', 'a');
		$zip->addFromString('002.jpg', 'b');
		$zip->close();
		try {
			// no tools at all: an empty search path
			$archive = ComicArchive::open($path, 'cbr', new ArchiveTools([]));
			$this->assertSame(['001.jpg', '002.jpg'], $archive->pages());
			$archive->close();
		} finally {
			@unlink($path);
		}
	}
}
