<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Db\TagMapper;
use OCA\EbookReader\Service\GenreClassifier;
use OCA\EbookReader\Service\SettingsService;
use PHPUnit\Framework\TestCase;

class GenreClassifierTest extends TestCase {
	/**
	 * @param list<string>|null $genreList
	 * @param list<string> $dbGenres
	 */
	private function classifier(?array $genreList, array $dbGenres = []): GenreClassifier {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('get')->willReturn(['libraryFolders' => ['/Books'], 'reader' => [], 'filenamePattern' => '', 'genreList' => $genreList]);
		$settings->method('defaultGenres')->willReturn(['Fantasy', 'Krimi']);
		$tags = $this->createMock(TagMapper::class);
		$tags->method('countByNameForUser')->willReturn(array_map(static fn (string $n): array => ['name' => $n, 'count' => 1], $dbGenres));
		return new GenreClassifier($settings, $tags);
	}

	public function testConfiguredListCaseInsensitive(): void {
		$r = $this->classifier(['Fantasy', 'Krimi'])->classify(['fantasy', 'KRIMI', 'Lieblingsbuch'], 'u');
		$this->assertSame(['Fantasy', 'Krimi'], $r['genres']);
		$this->assertSame(['Lieblingsbuch'], $r['tags']);
	}

	public function testKnownDbGenreWinsOverList(): void {
		$r = $this->classifier(['Fantasy'], ['Space Opera'])->classify(['space opera', 'Other'], 'u');
		$this->assertSame(['Space Opera'], $r['genres']);
		$this->assertSame(['Other'], $r['tags']);
	}

	public function testEnglishAliasMapsToGermanDefault(): void {
		$r = $this->classifier(['Science-Fiction', 'Krimi'])->classify(['Science Fiction', 'Crime'], 'u');
		$this->assertSame(['Science-Fiction', 'Krimi'], $r['genres']);
		$this->assertSame([], $r['tags']);
	}

	public function testAliasIgnoredWhenTargetNotInConfiguredList(): void {
		$r = $this->classifier(['Fantasy'])->classify(['Crime'], 'u');
		$this->assertSame([], $r['genres']);
		$this->assertSame(['Crime'], $r['tags']);
	}

	public function testWithoutUserUsesDefaultsAndDedupes(): void {
		$r = $this->classifier(null)->classify(['  Fantasy ', 'fantasy', '', 'x  y', 'X Y'], null);
		$this->assertSame(['Fantasy'], $r['genres']);
		$this->assertSame(['x y'], $r['tags']);
	}

	public function testNormaliseTruncates(): void {
		$this->assertSame(128, mb_strlen((string)GenreClassifier::normalise(str_repeat('ä', 300))));
		$this->assertNull(GenreClassifier::normalise('   '));
	}
}
