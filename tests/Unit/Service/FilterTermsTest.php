<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Service\FilterTerms;
use OCA\EbookReader\Service\GenreClassifier;
use PHPUnit\Framework\TestCase;

class FilterTermsTest extends TestCase {
	public function testNormalizeHierarchyTrimsSpacesAndDropsEmptyLevels(): void {
		$this->assertSame('Fantasy/High Fantasy', FilterTerms::normalizeHierarchy(' Fantasy  /  High   Fantasy '));
		$this->assertSame('A/B', FilterTerms::normalizeHierarchy('/A//B/'));
		$this->assertNull(FilterTerms::normalizeHierarchy(' / / '));
	}

	public function testNormalizeHierarchyCapsAtFiveLevels(): void {
		$this->assertSame('a/b/c/d/e', FilterTerms::normalizeHierarchy('a/b/c/d/e/f/g'));
	}

	public function testGenreClassifierNormaliseUsesHierarchy(): void {
		$this->assertSame('Fantasy/High Fantasy', GenreClassifier::normalise('Fantasy / High Fantasy'));
		$this->assertSame('Sci-Fi', GenreClassifier::normalise('  Sci-Fi '));
		$this->assertNull(GenreClassifier::normalise(' / '));
	}

	public function testHierarchyBase(): void {
		$this->assertSame('Fantasy', FilterTerms::hierarchyBase('Fantasy/*'));
		$this->assertSame('Fantasy/High', FilterTerms::hierarchyBase(' Fantasy / High /* '));
		$this->assertNull(FilterTerms::hierarchyBase('Fantasy'));
		$this->assertNull(FilterTerms::hierarchyBase('/*'));
		$this->assertNull(FilterTerms::hierarchyBase('*'));
		$this->assertNull(FilterTerms::hierarchyBase('Fantasy*'));
	}

	public function testWildcardMatchesParentAndChildrenCaseInsensitive(): void {
		$this->assertTrue(FilterTerms::tagMatches('Fantasy', 'Fantasy/*'));
		$this->assertTrue(FilterTerms::tagMatches('fantasy', 'FANTASY/*'));
		$this->assertTrue(FilterTerms::tagMatches('Fantasy/High Fantasy', 'fantasy/*'));
		$this->assertTrue(FilterTerms::tagMatches('Fantasy/High/Epic', 'Fantasy/*'));
		$this->assertTrue(FilterTerms::tagMatches('Fantasy/High/Epic', 'Fantasy/High/*'));
	}

	public function testWildcardDoesNotMatchSiblingsWithSamePrefix(): void {
		$this->assertFalse(FilterTerms::tagMatches('Fantasyx', 'Fantasy/*'));
		$this->assertFalse(FilterTerms::tagMatches('Fantasyx/High', 'Fantasy/*'));
		$this->assertFalse(FilterTerms::tagMatches('Sci-Fi/Fantasy', 'Fantasy/*'));
		$this->assertFalse(FilterTerms::tagMatches('Fantasy', 'Fantasy/High/*'));
	}

	public function testPlainTermStaysExact(): void {
		$this->assertTrue(FilterTerms::tagMatches('Fantasy', 'fantasy'));
		$this->assertFalse(FilterTerms::tagMatches('Fantasy/High', 'Fantasy'));
	}

	public function testShelfId(): void {
		$this->assertSame(7, FilterTerms::shelfId('7'));
		$this->assertSame(12, FilterTerms::shelfId(' 12 '));
		$this->assertNull(FilterTerms::shelfId('0'));
		$this->assertNull(FilterTerms::shelfId('-1'));
		$this->assertNull(FilterTerms::shelfId('abc'));
		$this->assertNull(FilterTerms::shelfId('1e3'));
		$this->assertNull(FilterTerms::shelfId(''));
		$this->assertNull(FilterTerms::shelfId('99999999999999999999'));
	}

	public function testNormalizeAge(): void {
		$this->assertSame('12', FilterTerms::normalizeAge('12'));
		$this->assertSame('0', FilterTerms::normalizeAge('00'));
		$this->assertSame('<=16', FilterTerms::normalizeAge(' <= 16 '));
		$this->assertSame('none', FilterTerms::normalizeAge('None'));
		$this->assertNull(FilterTerms::normalizeAge('15'));
		$this->assertNull(FilterTerms::normalizeAge('>=12'));
		$this->assertNull(FilterTerms::normalizeAge('<='));
		$this->assertNull(FilterTerms::normalizeAge('-6'));
		$this->assertNull(FilterTerms::normalizeAge('118'));
	}

	public function testAgeMatches(): void {
		$this->assertTrue(FilterTerms::ageMatches(12, '12'));
		$this->assertFalse(FilterTerms::ageMatches(16, '12'));
		$this->assertTrue(FilterTerms::ageMatches(6, '<=12'));
		$this->assertTrue(FilterTerms::ageMatches(12, '<=12'));
		$this->assertFalse(FilterTerms::ageMatches(16, '<=12'));
		// unrated books are not "12 or lower"
		$this->assertFalse(FilterTerms::ageMatches(null, '<=12'));
		$this->assertTrue(FilterTerms::ageMatches(null, 'none'));
		$this->assertFalse(FilterTerms::ageMatches(0, 'none'));
		$this->assertFalse(FilterTerms::ageMatches(12, 'bogus'));
	}
}
