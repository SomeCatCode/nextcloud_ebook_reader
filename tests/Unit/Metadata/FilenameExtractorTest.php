<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Metadata\FilenameExtractor;
use PHPUnit\Framework\TestCase;

class FilenameExtractorTest extends TestCase {
	public function testAuthorDashTitle(): void {
		$m = (new FilenameExtractor())->fromFilename('Terry Pratchett - Small Gods.epub');
		$this->assertSame('Small Gods', $m->title);
		$this->assertSame(['Terry Pratchett'], $m->authors);
	}

	public function testTitleContainingDash(): void {
		$m = (new FilenameExtractor())->fromFilename('Jane Doe & John Roe - Foo - Bar.epub');
		$this->assertSame('Foo - Bar', $m->title);
		$this->assertSame(['Jane Doe', 'John Roe'], $m->authors);
	}

	public function testOnlyTitleAndUnderscores(): void {
		$m = (new FilenameExtractor())->fromFilename('Das_Buch.cbz');
		$this->assertSame('Das Buch', $m->title);
		$this->assertSame([], $m->authors);
	}

	public function testFb2Zip(): void {
		$m = (new FilenameExtractor())->fromFilename('A - B.fb2.zip');
		$this->assertSame('B', $m->title);
	}

	public function testExtractUsesBasename(): void {
		$this->assertSame('x', (new FilenameExtractor())->extract('/tmp/dir/x.mobi')->title);
	}
}
