<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Editor;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Service\RenameService;
use PHPUnit\Framework\TestCase;

class RenameServiceTest extends TestCase {
	private function book(?string $series = 'Saga', ?float $index = 2.0): Book {
		$b = new Book();
		$b->setTitle('Der Titel: Teil/1');
		$b->setAuthorsArray(['Erika Muster', 'Max Mustermann']);
		$b->setSeries($series);
		$b->setSeriesIndex($index);
		$b->setPath('/Books/old.epub');
		return $b;
	}

	public function testPatternWithSeries(): void {
		$name = (new RenameService())->buildFilename($this->book(), '{author} - {series} {series_index} - {title}');
		$this->assertSame('Erika Muster - Saga 2 - Der Titel_ Teil_1', $name);
	}

	public function testEmptyPlaceholdersLeaveNoDanglingSeparators(): void {
		$name = (new RenameService())->buildFilename($this->book(null, null), '{author} - {series} {series_index} - {title}');
		$this->assertSame('Erika Muster - Der Titel_ Teil_1', $name);
	}

	public function testFallbackToTitleOrPath(): void {
		$b = new Book();
		$b->setPath('/Books/old.epub');
		$this->assertSame('old', (new RenameService())->buildFilename($b, '{author} - {title}'));
	}

	public function testSanitizeTrimsAndShortens(): void {
		$s = new RenameService();
		$this->assertSame('a_b', $s->sanitize(' ..a|b.. '));
		$this->assertLessThanOrEqual(150, mb_strlen($s->sanitize(str_repeat('x', 500))));
	}
}
