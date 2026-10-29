<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Metadata\ComicInfoParser;
use OCA\EbookReader\Metadata\Longstrip;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LongstripTest extends TestCase {
	/** @return array<string, array{array<string, ?string>, bool}> */
	public static function cases(): array {
		return [
			'Format Webtoon' => [['Format' => 'Webtoon'], true],
			'Format Web Toon, other case' => [['Format' => 'web toon'], true],
			'Format Long Strip' => [['Format' => 'Long Strip'], true],
			'Format Vertical' => [['Format' => 'Vertical'], true],
			'Tag webtoon among others' => [['Tags' => 'Action, Webtoon'], true],
			'Genre Long-Strip' => [['Genre' => 'Long-Strip'], true],
			'Format TPB' => [['Format' => 'TPB'], false],
			'tag only containing the word' => [['Tags' => 'Webtoon adaptation'], false],
			'nothing' => [[], false],
		];
	}

	/** @param array<string, ?string> $fields */
	#[DataProvider('cases')]
	public function testFromComicInfo(array $fields, bool $expected): void {
		$this->assertSame($expected, Longstrip::fromComicInfo($fields));
	}

	public function testComicInfoFormatReachesTheMetadata(): void {
		$xml = '<?xml version="1.0"?><ComicInfo><Title>T</Title><Format>Webtoon</Format></ComicInfo>';
		$this->assertTrue(ComicInfoParser::toMetadata(ComicInfoParser::parse($xml), null, null)->longstrip);
		$plain = '<?xml version="1.0"?><ComicInfo><Title>T</Title></ComicInfo>';
		$this->assertFalse(ComicInfoParser::toMetadata(ComicInfoParser::parse($plain), null, null)->longstrip);
	}

	public function testManualValueSurvivesTheFileValue(): void {
		$book = new Book();
		$this->assertTrue($book->applyFileLongstrip(true));
		$this->assertTrue($book->getLongstrip());
		$book->setManualLongstrip(false);
		$this->assertFalse($book->applyFileLongstrip(true));
		$this->assertFalse($book->getLongstrip());
		$this->assertTrue($book->getLongstripFile());
		$book->resetLongstrip();
		$this->assertTrue($book->getLongstrip());
		$this->assertFalse($book->getLongstripManual());
	}
}
