<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Metadata\ComicInfoParser;
use PHPUnit\Framework\TestCase;

class ComicCoverChoiceTest extends TestCase {
	/** PNG header only (enough for getimagesizefromstring and the mime sniffing). */
	private static function png(int $w, int $h): string {
		$ihdr = pack('NN', $w, $h) . "\x08\x02\x00\x00\x00";
		return "\x89PNG\r\n\x1A\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
	}

	/** @param list<string> $pages */
	private static function reader(array $pages): \Closure {
		return static fn (int $i): ?string => $pages[$i] ?? null;
	}

	public function testWideTitleBannerIsSkipped(): void {
		$pages = [self::png(2000, 400), self::png(1000, 1400), self::png(1000, 1400)];
		[$data, $mime] = ComicInfoParser::chooseCover(self::reader($pages), 0, false);
		$this->assertSame($pages[1], $data);
		$this->assertSame('image/png', $mime);
	}

	public function testExplicitFrontCoverWinsEvenIfWide(): void {
		$pages = [self::png(1000, 1400), self::png(2000, 1400)];
		[$data] = ComicInfoParser::chooseCover(self::reader($pages), 1, true);
		$this->assertSame($pages[1], $data);
	}

	public function testFallsBackToFirstImageWhenNoPortraitPageExists(): void {
		$pages = [self::png(2000, 1000), self::png(2000, 1000)];
		[$data] = ComicInfoParser::chooseCover(self::reader($pages), 0, false);
		$this->assertSame($pages[0], $data);
	}

	public function testExplicitFlagComesFromComicInfo(): void {
		$xml = '<ComicInfo><Pages><Page Image="2" Type="FrontCover"/></Pages></ComicInfo>';
		$parsed = ComicInfoParser::parse($xml);
		$this->assertSame(2, $parsed['coverIndex']);
		$this->assertTrue($parsed['coverExplicit']);
		$this->assertFalse(ComicInfoParser::parse('<ComicInfo/>')['coverExplicit']);
	}
}
