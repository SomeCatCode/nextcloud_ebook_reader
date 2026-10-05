<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Metadata\AgeRating;
use OCA\EbookReader\Metadata\ComicInfoParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AgeRatingTest extends TestCase {
	/** @return array<string, array{?string, ?int}> */
	public static function comicInfoValues(): array {
		return [
			'Everyone' => ['Everyone', 0],
			'Early Childhood' => ['Early Childhood', 0],
			'G' => ['G', 0],
			'Kids to Adults' => ['Kids to Adults', 6],
			'Everyone 10+' => ['Everyone 10+', 12],
			'E10+' => ['E10+', 12],
			'PG' => ['PG', 12],
			'Teen' => ['Teen', 12],
			'M' => ['M', 16],
			'MA15+' => ['MA15+', 16],
			'Mature 17+' => ['Mature 17+', 18],
			'Adults Only 18+' => ['Adults Only 18+', 18],
			'R18+' => ['R18+', 18],
			'X18+' => ['X18+', 18],
			'case and spaces' => ['  adults   ONLY 18+ ', 18],
			'Rating Pending' => ['Rating Pending', null],
			'Unknown' => ['Unknown', null],
			'empty' => ['', null],
			'null' => [null, null],
			'garbage' => ['Banana', null],
			'bare number' => ['16', 16],
			'number with plus' => ['13+', 16],
			'FSK' => ['FSK 12', 12],
			'USK 0' => ['USK 0', 0],
			'PEGI 7' => ['PEGI 7', 12],
			'Ab 6' => ['Ab 6 Jahren', 6],
			'above 18' => ['21+', 18],
			'range' => ['Ages 10-14', 12],
		];
	}

	#[DataProvider('comicInfoValues')]
	public function testFromText(?string $raw, ?int $expected): void {
		$this->assertSame($expected, AgeRating::fromText($raw));
	}

	public function testFromAgeRoundsUpToTheNextLevel(): void {
		$this->assertSame(0, AgeRating::fromAge(-3));
		$this->assertSame(0, AgeRating::fromAge(0));
		$this->assertSame(6, AgeRating::fromAge(1));
		$this->assertSame(12, AgeRating::fromAge(7));
		$this->assertSame(12, AgeRating::fromAge(12));
		$this->assertSame(16, AgeRating::fromAge(13));
		$this->assertSame(18, AgeRating::fromAge(17));
		$this->assertSame(18, AgeRating::fromAge(99));
	}

	/** @return array<string, array{?string, ?int}> */
	public static function ageRanges(): array {
		return [
			'open upper bound' => ['12-', 12],
			'range' => ['7-12', 12],
			'plus' => ['16+', 16],
			'single' => ['14', 16],
			'up to' => ['-6', 0],
			'spaces' => [' 8 - 10 ', 12],
			'text' => ['young adult', null],
			'null' => [null, null],
		];
	}

	#[DataProvider('ageRanges')]
	public function testFromAgeRange(?string $raw, ?int $expected): void {
		$this->assertSame($expected, AgeRating::fromAgeRange($raw));
	}

	public function testComicInfoAgeRatingBecomesMetadata(): void {
		$xml = '<?xml version="1.0"?><ComicInfo><Title>T</Title><AgeRating>Teen</AgeRating></ComicInfo>';
		$meta = ComicInfoParser::toMetadata(ComicInfoParser::parse($xml), null, null);
		$this->assertSame(12, $meta->ageRating);

		$none = ComicInfoParser::toMetadata(ComicInfoParser::parse('<ComicInfo><Title>T</Title></ComicInfo>'), null, null);
		$this->assertNull($none->ageRating);
	}

	public function testIsLevel(): void {
		$this->assertTrue(AgeRating::isLevel(16));
		$this->assertFalse(AgeRating::isLevel(15));
		$this->assertFalse(AgeRating::isLevel('16'));
		$this->assertFalse(AgeRating::isLevel(null));
	}
}
