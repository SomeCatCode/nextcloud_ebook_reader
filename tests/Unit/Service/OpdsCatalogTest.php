<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\Shelf;
use OCA\EbookReader\Db\ShelfMapper;
use OCA\EbookReader\Db\Tag;
use OCA\EbookReader\Db\TagMapper;
use OCA\EbookReader\Service\BookQuery;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\OpdsCatalog;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OpdsCatalogTest extends TestCase {
	private LibraryService&MockObject $library;
	private TagMapper&MockObject $tags;
	private ShelfMapper&MockObject $shelves;

	private function catalog(): OpdsCatalog {
		$this->library = $this->createMock(LibraryService::class);
		$this->tags = $this->createMock(TagMapper::class);
		$this->shelves = $this->createMock(ShelfMapper::class);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static function (string $route, array $params = []): string {
			$name = str_replace('ebookreader.opds.', '', $route);
			return 'https://nc/opds/' . $name . ($params === [] ? '' : '?' . http_build_query($params));
		});
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $p = []): string => vsprintf($s, $p));
		$l10n->method('n')->willReturnCallback(static fn (string $s, string $p, int $n): string => str_replace('%n', (string)$n, $n === 1 ? $s : $p));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new \DateTimeImmutable('@1800000000'));
		return new OpdsCatalog($this->library, $this->tags, $this->shelves, $urls, $l10n, $time);
	}

	private function book(int $id, string $format = 'epub'): Book {
		$b = new Book();
		$b->setId($id);
		$b->setFileId($id + 1000);
		$b->setFormat($format);
		$b->setPath('/Books/b' . $id . '.' . $format);
		$b->setSize(4096);
		$b->setTitle('Book ' . $id);
		$b->setAuthors(json_encode(['Ann Author']));
		$b->setHasCover(true);
		$b->setUpdatedAt(1700000000000);
		return $b;
	}

	private function xpath(string $xml): \DOMXPath {
		$doc = new \DOMDocument();
		$this->assertTrue($doc->loadXML($xml));
		$x = new \DOMXPath($doc);
		$x->registerNamespace('a', 'http://www.w3.org/2005/Atom');
		return $x;
	}

	public function testRootListsAllSections(): void {
		$x = $this->xpath($this->catalog()->root());
		$this->assertSame(8.0, $x->evaluate('count(/a:feed/a:entry)'));
		$this->assertSame('https://nc/opds/opensearch', $x->evaluate('string(/a:feed/a:link[@rel="search"]/@href)'));
		$titles = [];
		foreach ($x->query('/a:feed/a:entry/a:title') ?: [] as $node) {
			$titles[] = $node->textContent;
		}
		$this->assertSame(['Recently added', 'Continue reading', 'All books', 'Authors', 'Series', 'Genres', 'Tags', 'Shelves'], $titles);
		$this->assertSame('application/atom+xml;profile=opds-catalog;kind=navigation', $x->evaluate('string(/a:feed/a:link[@rel="self"]/@type)'));
	}

	public function testBookFeedEntriesAndPaging(): void {
		$c = $this->catalog();
		$this->library->expects($this->once())->method('findBooks')
			->with('alice', $this->callback(static fn (BookQuery $q): bool => $q->limit === 50 && $q->offset === 50 && $q->sort === 'added' && $q->order === 'desc'))
			->willReturn(['books' => [$this->book(1), $this->book(2, 'cbz')], 'total' => 120]);
		$genre = new Tag();
		$genre->setBookId(1);
		$genre->setName('Fantasy');
		$this->tags->method('findByBooks')->with([1, 2])->willReturn([1 => [$genre]]);

		$x = $this->xpath($c->recent('alice', 2));
		$this->assertSame(2.0, $x->evaluate('count(/a:feed/a:entry)'));
		$this->assertSame('urn:ebookreader:1001', $x->evaluate('string(/a:feed/a:entry[1]/a:id)'));
		$this->assertSame('Fantasy', $x->evaluate('string(/a:feed/a:entry[1]/a:category/@term)'));
		$this->assertSame('https://nc/opds/download?fileId=1001', $x->evaluate('string(/a:feed/a:entry[1]/a:link[@rel="http://opds-spec.org/acquisition"]/@href)'));
		$this->assertSame('4096', $x->evaluate('string(/a:feed/a:entry[1]/a:link[@rel="http://opds-spec.org/acquisition"]/@length)'));
		$this->assertSame('application/vnd.comicbook+zip', $x->evaluate('string(/a:feed/a:entry[2]/a:link[@rel="http://opds-spec.org/acquisition"]/@type)'));
		$this->assertSame('https://nc/opds/cover?fileId=1001&size=large', $x->evaluate('string(/a:feed/a:entry[1]/a:link[@rel="http://opds-spec.org/image"]/@href)'));
		$this->assertSame('https://nc/opds/recent', $x->evaluate('string(/a:feed/a:link[@rel="first"]/@href)'));
		$this->assertSame('https://nc/opds/recent', $x->evaluate('string(/a:feed/a:link[@rel="previous"]/@href)'));
		$this->assertSame('https://nc/opds/recent?page=3', $x->evaluate('string(/a:feed/a:link[@rel="next"]/@href)'));
		$this->assertSame('https://nc/opds/recent?page=3', $x->evaluate('string(/a:feed/a:link[@rel="last"]/@href)'));
		$this->assertSame('https://nc/opds/recent?page=2', $x->evaluate('string(/a:feed/a:link[@rel="self"]/@href)'));
		$this->assertSame('application/atom+xml;profile=opds-catalog;kind=acquisition', $x->evaluate('string(/a:feed/a:link[@rel="self"]/@type)'));
	}

	public function testPagingLinksAtTheEnds(): void {
		$c = $this->catalog();
		$this->assertSame([], $c->pagingLinks('all', [], 1, 10, 50, 'acquisition'));
		$rels = array_column($c->pagingLinks('all', [], 1, 60, 50, 'acquisition'), 'rel');
		$this->assertSame(['next', 'last'], $rels);
		$rels = array_column($c->pagingLinks('all', [], 2, 100, 50, 'acquisition'), 'rel');
		$this->assertSame(['first', 'previous'], $rels);
		// a page beyond the end links back to the last real page
		$links = $c->pagingLinks('all', [], 9, 100, 50, 'acquisition');
		$this->assertSame('https://nc/opds/all?page=2', $links[1]['href']);
	}

	public function testReadingFeedQueriesBooksInProgress(): void {
		$c = $this->catalog();
		$this->library->expects($this->once())->method('findBooks')
			->with('alice', $this->callback(static fn (BookQuery $q): bool => $q->status === 'reading' && $q->sort === 'read' && $q->order === 'desc'))
			->willReturn(['books' => [], 'total' => 0]);
		$x = $this->xpath($c->reading('alice', 1));
		$this->assertSame(0.0, $x->evaluate('count(/a:feed/a:entry)'));
	}

	public function testFilteredFeedUsesFilterTermAndRejectsOthers(): void {
		$c = $this->catalog();
		$this->library->expects($this->once())->method('findBooks')
			->with('alice', $this->callback(static fn (BookQuery $q): bool => $q->include === [['type' => 'series', 'name' => 'Dune Saga']] && $q->sort === 'series' && $q->exclude === []))
			->willReturn(['books' => [$this->book(1)], 'total' => 1]);
		$x = $this->xpath((string)$c->filtered('alice', 'series:Dune Saga', '', 'asc', 1));
		$this->assertSame('Series: Dune Saga', $x->evaluate('string(/a:feed/a:title)'));
		$this->assertSame('https://nc/opds/books?filter=series%3ADune+Saga&sort=series', $x->evaluate('string(/a:feed/a:link[@rel="self"]/@href)'));

		// missing: and unknown terms are not offered via OPDS
		$this->assertNull($c->filtered('alice', 'missing:cover', 'title', 'asc', 1));
		$this->assertNull($c->filtered('alice', 'bogus:x', 'title', 'asc', 1));
		$this->assertNull($c->filtered('alice', 'nocolon', 'title', 'asc', 1));
		$this->assertNull($c->filtered('alice', '', 'title', 'asc', 1));
	}

	public function testUnknownSortFallsBackAndShelfTermOnlyUsesOwnShelves(): void {
		$c = $this->catalog();
		$this->library->method('findBooks')
			->with('alice', $this->callback(static fn (BookQuery $q): bool => $q->sort === 'title' && $q->include === [['type' => 'shelf', 'name' => '5']]))
			->willReturn(['books' => [], 'total' => 0]);
		$this->shelves->expects($this->once())->method('findByUserAndId')->with('alice', 5)->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('x'));
		$x = $this->xpath((string)$c->filtered('alice', 'shelf:5', 'DROP TABLE', 'asc', 1));
		$this->assertSame('Shelf: 5', $x->evaluate('string(/a:feed/a:title)'), 'a foreign shelf name is not revealed');
	}

	public function testSearchFeed(): void {
		$c = $this->catalog();
		$this->library->expects($this->once())->method('findBooks')
			->with('alice', $this->callback(static fn (BookQuery $q): bool => $q->search === 'dune &' && $q->include === []))
			->willReturn(['books' => [$this->book(1)], 'total' => 1]);
		$x = $this->xpath($c->search('alice', '  dune &  ', 1));
		$this->assertSame('Search: dune &', $x->evaluate('string(/a:feed/a:title)'));
		$this->assertSame(1.0, $x->evaluate('count(/a:feed/a:entry)'));
	}

	public function testEmptySearchReturnsEmptyFeedWithoutQuery(): void {
		$c = $this->catalog();
		$this->library->expects($this->never())->method('findBooks');
		$x = $this->xpath($c->search('alice', '   ', 1));
		$this->assertSame(0.0, $x->evaluate('count(/a:feed/a:entry)'));
	}

	public function testOpenSearchTemplate(): void {
		$doc = new \DOMDocument();
		$doc->loadXML($this->catalog()->openSearch());
		$url = $doc->getElementsByTagName('Url')->item(0);
		$this->assertInstanceOf(\DOMElement::class, $url);
		$this->assertSame('https://nc/opds/search?q={searchTerms}', $url->getAttribute('template'));
	}

	public function testAuthorNavigationIsPaged(): void {
		$c = $this->catalog();
		$authors = [];
		for ($i = 1; $i <= 150; $i++) {
			$authors[] = ['name' => 'Author ' . $i, 'count' => $i];
		}
		$this->library->method('getFacets')->willReturn(['authors' => $authors]);
		$x = $this->xpath($c->navigation('alice', 'authors', 2));
		$this->assertSame(50.0, $x->evaluate('count(/a:feed/a:entry)'));
		$this->assertSame('Author 101', $x->evaluate('string(/a:feed/a:entry[1]/a:title)'));
		$this->assertSame('https://nc/opds/books?filter=author%3AAuthor+101', $x->evaluate('string(/a:feed/a:entry[1]/a:link/@href)'));
		$this->assertSame('101 books', $x->evaluate('string(/a:feed/a:entry[1]/a:content)'));
		$this->assertSame('', $x->evaluate('string(/a:feed/a:link[@rel="next"]/@href)'));
		$this->assertSame('https://nc/opds/authors', $x->evaluate('string(/a:feed/a:link[@rel="previous"]/@href)'));
	}

	public function testShelfNavigationListsOwnShelvesOnly(): void {
		$c = $this->catalog();
		$manual = new Shelf();
		$manual->setId(3);
		$manual->setName('Favourites');
		$manual->setType(Shelf::TYPE_MANUAL);
		$smart = new Shelf();
		$smart->setId(4);
		$smart->setName('Unread');
		$smart->setType(Shelf::TYPE_SMART);
		$this->shelves->expects($this->once())->method('findByUser')->with('alice')->willReturn([$manual, $smart]);
		$x = $this->xpath($c->navigation('alice', 'shelves', 1));
		$this->assertSame(2.0, $x->evaluate('count(/a:feed/a:entry)'));
		$this->assertSame('https://nc/opds/books?filter=shelf%3A3&sort=shelf', $x->evaluate('string(/a:feed/a:entry[1]/a:link/@href)'));
		$this->assertSame('https://nc/opds/books?filter=shelf%3A4', $x->evaluate('string(/a:feed/a:entry[2]/a:link/@href)'));
	}

	public function testBookEntryMetadata(): void {
		$c = $this->catalog();
		$b = $this->book(1);
		$b->setDescription('<p>Hello &amp; <b>welcome</b></p><p>Second</p>');
		$b->setLanguage('de');
		$b->setPublisher('Verlag');
		$b->setPublishedAt('2020-05-17T00:00:00Z');
		$b->setIsbn('978-3-16-148410-0');
		$b->setSeries('Saga');
		$b->setSeriesIndex(2.0);
		$b->setHasCover(false);
		$entry = $c->bookEntry($b);
		$this->assertSame('de', $entry['language']);
		$this->assertSame('Verlag', $entry['publisher']);
		$this->assertSame('2020-05-17', $entry['issued']);
		$this->assertSame('urn:isbn:9783161484100', $entry['identifier']);
		$this->assertSame("Saga #2\n\nHello & welcome\nSecond", $entry['summary']);
		$this->assertCount(1, $entry['links'], 'no cover links without a cover');
		$this->assertSame(1700000000, $entry['updated']);
	}

	public function testPlainText(): void {
		$this->assertSame('', OpdsCatalog::plainText(null));
		$this->assertSame('a b', OpdsCatalog::plainText('<i>a</i>   b'));
		$this->assertSame(10, mb_strlen(OpdsCatalog::plainText(str_repeat('x', 50), 10)));
	}

	public function testTitleFallsBackToFileName(): void {
		$b = $this->book(1);
		$b->setTitle(' ');
		$this->assertSame('b1', OpdsCatalog::titleOf($b));
	}
}
