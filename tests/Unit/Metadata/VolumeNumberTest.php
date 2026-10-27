<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Metadata\VolumeNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VolumeNumberTest extends TestCase {
	/** @return array<string, array{string, ?float}> */
	public static function titles(): array {
		return [
			'Vol. 10' => ['Saga Vol. 10', 10.0],
			'Vol 3' => ['Saga vol 3', 3.0],
			'Volume 12' => ['Saga, Volume 12: The End', 12.0],
			'v02' => ['Saga v02', 2.0],
			'v.7' => ['Saga v.7', 7.0],
			'Band 3' => ['Saga Band 3', 3.0],
			'Tome 4' => ['Saga Tome 4', 4.0],
			'T04' => ['Saga T04', 4.0],
			'#7' => ['Saga #7', 7.0],
			'No. 5' => ['Saga No. 5', 5.0],
			'Book 2' => ['Saga Book 2', 2.0],
			'decimal' => ['Saga Vol. 10.5', 10.5],
			'chapter' => ['Saga Chapter 5', 5.0],
			'Ch.' => ['Saga Ch. 12', 12.0],
			'Kapitel' => ['Saga Kapitel 8', 8.0],
			'volume before chapter' => ['Saga Vol. 3 Ch. 25', 3.0],
			'japanese' => ['ワンピース 第3巻', 3.0],
			'trailing' => ['Saga 10', 10.0],
			'trailing with group' => ['Saga 10 (Digital) [Group]', 10.0],
			'trailing decimal' => ['Saga 10.5', 10.5],
			'leading zeros' => ['Saga 007', 7.0],
			'extension' => ['Saga 12.cbz', 12.0],
			'underscores' => ['Saga_Vol_4.epub', 4.0],
			'year is no volume' => ['Saga 2019', null],
			'no number' => ['Saga', null],
			'number inside word' => ['Catch22 Story', null],
			'empty' => ['', null],
		];
	}

	#[DataProvider('titles')]
	public function testParse(string $text, ?float $expected): void {
		$this->assertSame($expected, VolumeNumber::parse($text));
	}

	public function testLeadingNumberOnlyForFileNames(): void {
		$this->assertNull(VolumeNumber::parse('01 - The Beginning'));
		$this->assertSame(1.0, VolumeNumber::parse('01 - The Beginning', true));
		$this->assertSame(12.0, VolumeNumber::parse('12 Title', true));
		$this->assertNull(VolumeNumber::parse('2001 A Space Odyssey', true));
	}

	public function testTitleBeforeFileName(): void {
		$this->assertSame(10.0, VolumeNumber::fromTitleOrFilename('Saga 10', '/Books/Saga 2.cbz'));
		$this->assertSame(2.0, VolumeNumber::fromTitleOrFilename('The Beginning', '/Books/Saga/Saga 2.cbz'));
		$this->assertSame(3.0, VolumeNumber::fromTitleOrFilename(null, '/Books/03 - Saga.cbz'));
		$this->assertNull(VolumeNumber::fromTitleOrFilename('Saga', '/Books/Saga.cbz'));
		$this->assertNull(VolumeNumber::fromTitleOrFilename(null, null));
	}
}
