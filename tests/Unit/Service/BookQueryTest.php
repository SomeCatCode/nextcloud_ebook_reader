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
}
