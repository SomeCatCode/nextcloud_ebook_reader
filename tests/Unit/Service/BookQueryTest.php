<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Service\BookQuery;
use PHPUnit\Framework\TestCase;

class BookQueryTest extends TestCase {
	public function testParsesIncludeAndExcludeArrays(): void {
		$q = BookQuery::fromRequestParams([
			'include' => ['genre:Fantasy', 'Tag: Space Opera ', 'author:Doe, John', 'series:Saga', 'format:EPUB'],
			'exclude' => ['tag:Horror'],
			'match' => 'any',
		]);
		$this->assertSame([
			['type' => 'genre', 'name' => 'Fantasy'],
			['type' => 'tag', 'name' => 'Space Opera'],
			['type' => 'author', 'name' => 'Doe, John'],
			['type' => 'series', 'name' => 'Saga'],
			['type' => 'format', 'name' => 'EPUB'],
		], $q->include);
		$this->assertSame([['type' => 'tag', 'name' => 'Horror']], $q->exclude);
		$this->assertSame('any', $q->match);
	}

	public function testIgnoresMalformedEntries(): void {
		$q = BookQuery::fromRequestParams([
			'include' => ['nocolon', 'unknown:x', 'genre:', ':x', 42, ['genre:x'], null, 'tag:ok', 'TAG:OK'],
			'match' => 'weird',
		]);
		$this->assertSame([['type' => 'tag', 'name' => 'ok']], $q->include);
		$this->assertSame('all', $q->match);
	}

	public function testCommaSeparatedStringKeepsCommasInNames(): void {
		$q = BookQuery::fromRequestParams(['include' => 'genre:Fantasy,tag:Epic, author:Doe, John,series:Saga']);
		$this->assertSame([
			['type' => 'genre', 'name' => 'Fantasy'],
			['type' => 'tag', 'name' => 'Epic'],
			['type' => 'author', 'name' => 'Doe, John'],
			['type' => 'series', 'name' => 'Saga'],
		], $q->include);
	}

	public function testMissingTermsAreValidated(): void {
		$q = BookQuery::fromRequestParams([
			'include' => ['missing:genre', 'missing:Cover', 'missing:bogus', 'missing:', 'missing:GENRE'],
			'exclude' => 'missing:language,missing:nope',
		]);
		$this->assertSame([['type' => 'missing', 'name' => 'genre'], ['type' => 'missing', 'name' => 'cover']], $q->include);
		$this->assertSame([['type' => 'missing', 'name' => 'language']], $q->exclude);
	}

	public function testCompletionAndAgeTermsAreValidatedAndNormalised(): void {
		$q = BookQuery::fromRequestParams([
			'include' => ['completion:Ongoing', 'completion:done', 'age:<= 12', 'age:16', 'age:15', 'age:<=7', 'age:NONE', 'age:'],
			'exclude' => 'completion:unknown,age:18',
		]);
		$this->assertSame([
			['type' => 'completion', 'name' => 'ongoing'],
			['type' => 'age', 'name' => '<=12'],
			['type' => 'age', 'name' => '16'],
			['type' => 'age', 'name' => 'none'],
		], $q->include);
		$this->assertSame([['type' => 'completion', 'name' => 'unknown'], ['type' => 'age', 'name' => '18']], $q->exclude);
	}

	public function testShelfTermAndSort(): void {
		$q = BookQuery::fromRequestParams(['include' => ['shelf:12', 'tag:Fantasy/*'], 'exclude' => ['shelf:3'], 'sort' => 'shelf']);
		$this->assertSame([['type' => 'shelf', 'name' => '12'], ['type' => 'tag', 'name' => 'Fantasy/*']], $q->include);
		$this->assertSame([['type' => 'shelf', 'name' => '3']], $q->exclude);
		$this->assertSame('shelf', $q->sort);
	}

	public function testInSeriesParam(): void {
		$this->assertNull(BookQuery::fromRequestParams([])->inSeries);
		$this->assertTrue(BookQuery::fromRequestParams(['inSeries' => '1'])->inSeries);
		$this->assertTrue(BookQuery::fromRequestParams(['inSeries' => 1])->inSeries);
		$this->assertFalse(BookQuery::fromRequestParams(['inSeries' => '0'])->inSeries);
		$this->assertFalse(BookQuery::fromRequestParams(['inSeries' => 0])->inSeries);
		$this->assertNull(BookQuery::fromRequestParams(['inSeries' => 'x'])->inSeries);
		$this->assertNull(BookQuery::fromRequestParams(['inSeries' => null])->inSeries);
	}

	public function testNonArrayNonStringIsIgnored(): void {
		$q = BookQuery::fromRequestParams(['include' => 5, 'exclude' => null]);
		$this->assertSame([], $q->include);
		$this->assertSame([], $q->exclude);
	}

	public function testLegacyParamsBecomeIncludeEntries(): void {
		$q = BookQuery::fromRequestParams(['genre' => 'Krimi', 'tag' => 'x', 'author' => 'A', 'series' => 'S', 'format' => 'cbz', 'include' => ['tag:y']]);
		$this->assertSame([
			['type' => 'tag', 'name' => 'y'],
			['type' => 'format', 'name' => 'cbz'],
			['type' => 'genre', 'name' => 'Krimi'],
			['type' => 'tag', 'name' => 'x'],
			['type' => 'author', 'name' => 'A'],
			['type' => 'series', 'name' => 'S'],
		], $q->effectiveIncludes());
	}

	public function testDefaults(): void {
		$q = BookQuery::fromRequestParams([]);
		$this->assertSame([], $q->effectiveIncludes());
		$this->assertSame([], $q->exclude);
		$this->assertSame('all', $q->match);
	}

	public function testEntryCountIsCapped(): void {
		$entries = [];
		for ($i = 0; $i < 200; $i++) {
			$entries[] = 'tag:t' . $i;
		}
		$this->assertCount(BookQuery::MAX_FILTER_ENTRIES, BookQuery::fromRequestParams(['include' => $entries])->include);
	}

	public function testOcsResponseFormatIsNotABookFormatFilter(): void {
		$this->assertNull(BookQuery::fromRequestParams(['format' => 'json'])->format);
		$this->assertNull(BookQuery::fromRequestParams(['format' => 'XML'])->format);
		$this->assertSame([], BookQuery::fromRequestParams(['format' => 'json'])->effectiveIncludes());
		$this->assertSame('epub', BookQuery::fromRequestParams(['format' => 'epub'])->format);
	}

	public function testSharedParam(): void {
		$this->assertNull(BookQuery::fromRequestParams([])->shared);
		$this->assertSame('incoming', BookQuery::fromRequestParams(['shared' => 'incoming'])->shared);
		$this->assertSame('outgoing', BookQuery::fromRequestParams(['shared' => ' Outgoing '])->shared);
		$this->assertSame('any', BookQuery::fromRequestParams(['shared' => 'ANY'])->shared);
		$this->assertNull(BookQuery::fromRequestParams(['shared' => 'bogus'])->shared);
		$this->assertNull(BookQuery::fromRequestParams(['shared' => ''])->shared);
		$this->assertNull(BookQuery::fromRequestParams(['shared' => 1])->shared);
		$this->assertNull(BookQuery::fromRequestParams(['shared' => ['any']])->shared);
	}
}
