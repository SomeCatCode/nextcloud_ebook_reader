<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Service\OpdsFeedBuilder;
use PHPUnit\Framework\TestCase;

class OpdsFeedBuilderTest extends TestCase {
	private const ATOM = 'http://www.w3.org/2005/Atom';

	private function load(string $xml): \DOMDocument {
		$doc = new \DOMDocument();
		$this->assertTrue($doc->loadXML($xml, LIBXML_NONET), 'feed must be well-formed XML');
		return $doc;
	}

	private function xpath(\DOMDocument $doc): \DOMXPath {
		$x = new \DOMXPath($doc);
		$x->registerNamespace('a', self::ATOM);
		$x->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
		$x->registerNamespace('dcterms', 'http://purl.org/dc/terms/');
		$x->registerNamespace('os', 'http://a9.com/-/spec/opensearch/1.1/');
		return $x;
	}

	/** @return array<string, mixed> */
	private function feed(array $entries = [], array $extra = []): array {
		return $extra + [
			'id' => 'urn:test:feed',
			'title' => 'Test',
			'updated' => 1800000000,
			'kind' => OpdsFeedBuilder::KIND_ACQUISITION,
			'selfUrl' => 'https://nc/opds/all',
			'startUrl' => 'https://nc/opds',
			'entries' => $entries,
		];
	}

	public function testMimeMap(): void {
		$this->assertSame('application/epub+zip', OpdsFeedBuilder::mimeFor('epub'));
		$this->assertSame('application/x-mobipocket-ebook', OpdsFeedBuilder::mimeFor('mobi'));
		$this->assertSame('application/vnd.amazon.ebook', OpdsFeedBuilder::mimeFor('azw3'));
		$this->assertSame('application/x-fictionbook+xml', OpdsFeedBuilder::mimeFor('fb2'));
		$this->assertSame('application/x-zip-compressed-fb2', OpdsFeedBuilder::mimeFor('fbz'));
		$this->assertSame('application/vnd.comicbook+zip', OpdsFeedBuilder::mimeFor('cbz'));
		$this->assertSame('application/vnd.comicbook-rar', OpdsFeedBuilder::mimeFor('CBR'));
		$this->assertSame('application/x-cb7', OpdsFeedBuilder::mimeFor('cb7'));
		$this->assertSame('application/x-cbt', OpdsFeedBuilder::mimeFor('cbt'));
		$this->assertSame('application/octet-stream', OpdsFeedBuilder::mimeFor('exe'));
	}

	public function testContentTypes(): void {
		$this->assertSame('application/atom+xml;profile=opds-catalog;kind=acquisition', OpdsFeedBuilder::contentType('acquisition'));
		$this->assertSame('application/atom+xml;profile=opds-catalog;kind=navigation', OpdsFeedBuilder::contentType('navigation'));
		$this->assertSame('application/atom+xml;profile=opds-catalog;kind=navigation', OpdsFeedBuilder::contentType('anything'));
	}

	public function testBuildsWellFormedFeedWithLinks(): void {
		$xml = (new OpdsFeedBuilder())->build($this->feed([[
			'id' => 'urn:ebookreader:12',
			'title' => 'Dune',
			'updated' => 1700000000,
			'authors' => ['Frank Herbert', ' '],
			'language' => 'en',
			'publisher' => 'Chilton',
			'issued' => '1965-08',
			'identifier' => 'urn:isbn:9780441172719',
			'categories' => ['Science Fiction'],
			'summary' => 'A desert planet.',
			'links' => [
				['rel' => OpdsFeedBuilder::REL_ACQUISITION, 'href' => 'https://nc/opds/download/12', 'type' => 'application/epub+zip', 'length' => 1234],
				['rel' => OpdsFeedBuilder::REL_THUMBNAIL, 'href' => 'https://nc/opds/cover/12?size=small', 'type' => 'image/jpeg'],
			],
		]], [
			'upUrl' => 'https://nc/opds',
			'searchUrl' => 'https://nc/opds/opensearch.xml',
			'links' => [['rel' => 'next', 'href' => 'https://nc/opds/all?page=2', 'type' => OpdsFeedBuilder::contentType('acquisition')]],
			'total' => 120,
			'perPage' => 50,
			'startIndex' => 1,
		]));
		$this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
		$doc = $this->load($xml);
		$x = $this->xpath($doc);
		$this->assertSame('Test', $x->evaluate('string(/a:feed/a:title)'));
		$this->assertSame('2027-01-15T08:00:00Z', $x->evaluate('string(/a:feed/a:updated)'));
		$this->assertSame('https://nc/opds/all?page=2', $x->evaluate('string(/a:feed/a:link[@rel="next"]/@href)'));
		$this->assertSame('https://nc/opds', $x->evaluate('string(/a:feed/a:link[@rel="start"]/@href)'));
		$this->assertSame('https://nc/opds', $x->evaluate('string(/a:feed/a:link[@rel="up"]/@href)'));
		$this->assertSame('application/opensearchdescription+xml', $x->evaluate('string(/a:feed/a:link[@rel="search"]/@type)'));
		$this->assertSame('120', $x->evaluate('string(/a:feed/os:totalResults)'));
		$this->assertSame('50', $x->evaluate('string(/a:feed/os:itemsPerPage)'));
		$this->assertSame(1.0, $x->evaluate('count(/a:feed/a:entry)'));
		$this->assertSame('urn:ebookreader:12', $x->evaluate('string(/a:feed/a:entry/a:id)'));
		$this->assertSame(1.0, $x->evaluate('count(/a:feed/a:entry/a:author)'), 'blank authors are skipped');
		$this->assertSame('en', $x->evaluate('string(/a:feed/a:entry/dc:language)'));
		$this->assertSame('Chilton', $x->evaluate('string(/a:feed/a:entry/dc:publisher)'));
		$this->assertSame('1965-08', $x->evaluate('string(/a:feed/a:entry/dcterms:issued)'));
		$this->assertSame('urn:isbn:9780441172719', $x->evaluate('string(/a:feed/a:entry/dc:identifier)'));
		$this->assertSame('Science Fiction', $x->evaluate('string(/a:feed/a:entry/a:category/@term)'));
		$this->assertSame('1234', $x->evaluate('string(/a:feed/a:entry/a:link[@rel="http://opds-spec.org/acquisition"]/@length)'));
		$this->assertSame('application/epub+zip', $x->evaluate('string(/a:feed/a:entry/a:link[@rel="http://opds-spec.org/acquisition"]/@type)'));
		$this->assertSame(1.0, $x->evaluate('count(/a:feed/a:entry/a:link[@rel="http://opds-spec.org/image/thumbnail"])'));
	}

	public function testUserDataIsEscapedAndInvalidCharactersAreDropped(): void {
		$evil = '</title><entry>"><script>alert(1)</script>&amp; ]]>';
		$xml = (new OpdsFeedBuilder())->build($this->feed([[
			'id' => 'urn:ebookreader:1',
			'title' => $evil . "\x00\x08\x0B" . "caf\xE9",
			'updated' => 1,
			'authors' => [$evil],
			'summary' => $evil,
			'categories' => [$evil],
			'links' => [['rel' => 'x', 'href' => 'https://nc/?a=1&b="2"<3>', 'type' => 'text/html', 'title' => $evil]],
		]]));
		$doc = $this->load($xml);
		$x = $this->xpath($doc);
		$this->assertSame(1.0, $x->evaluate('count(/a:feed/a:entry)'), 'no injected entry');
		$this->assertSame(0.0, $x->evaluate('count(//script)'));
		$title = (string)$x->evaluate('string(/a:feed/a:entry/a:title)');
		$this->assertStringStartsWith($evil, $title);
		$this->assertStringNotContainsString("\x00", $title);
		$this->assertStringNotContainsString("\x08", $title);
		$this->assertSame($evil, (string)$x->evaluate('string(/a:feed/a:entry/a:author/a:name)'));
		$this->assertSame('https://nc/?a=1&b="2"<3>', $x->evaluate('string(/a:feed/a:entry/a:link/@href)'));
		$this->assertSame($evil, $x->evaluate('string(/a:feed/a:entry/a:link/@title)'));
		$this->assertSame($evil, $x->evaluate('string(/a:feed/a:entry/a:category/@label)'));
	}

	public function testOpenSearchDescription(): void {
		$xml = (new OpdsFeedBuilder())->buildOpenSearch('Books & more', 'Search "books"', 'https://nc/opds/search?q={searchTerms}');
		$doc = $this->load($xml);
		$x = new \DOMXPath($doc);
		$x->registerNamespace('os', 'http://a9.com/-/spec/opensearch/1.1/');
		$this->assertSame('Books & more', $x->evaluate('string(/os:OpenSearchDescription/os:ShortName)'));
		$this->assertSame('https://nc/opds/search?q={searchTerms}', $x->evaluate('string(/os:OpenSearchDescription/os:Url/@template)'));
		$this->assertSame('application/atom+xml;profile=opds-catalog;kind=acquisition', $x->evaluate('string(/os:OpenSearchDescription/os:Url/@type)'));
	}

	public function testNavigationFeedKind(): void {
		$xml = (new OpdsFeedBuilder())->build($this->feed([], ['kind' => OpdsFeedBuilder::KIND_NAVIGATION]));
		$x = $this->xpath($this->load($xml));
		$this->assertSame('application/atom+xml;profile=opds-catalog;kind=navigation', $x->evaluate('string(/a:feed/a:link[@rel="self"]/@type)'));
	}
}
