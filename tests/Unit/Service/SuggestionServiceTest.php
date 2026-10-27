<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Tag;
use OCA\EbookReader\Db\TagMapper;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\SuggestionService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeQueryBuilder.php';

class SuggestionServiceTest extends TestCase {
	private static function book(int $fileId, string $title, ?string $series = null, ?float $index = null, string $status = Book::STATUS_UNREAD, string $format = 'epub'): Book {
		$b = new Book();
		$b->setId($fileId);
		$b->setFileId($fileId);
		$b->setTitle($title);
		$b->setSeries($series);
		$b->setSeriesIndex($index);
		$b->setReadStatus($status);
		$b->setFormat($format);
		$b->setPath('/Books/' . $title . '.' . $format);
		return $b;
	}

	private static function tag(string $type, string $name): Tag {
		$t = new Tag();
		$t->setType($type);
		$t->setName($name);
		return $t;
	}

	/** @param list<Book> $books */
	private static function ids(array $books): array {
		return array_map(static fn (Book $b): int => $b->getFileId(), $books);
	}

	/** @return array{0: IQueryBuilder, 1: IDBConnection} */
	private function fakeDb(): array {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => FakeQueryBuilder::create($this));
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $s): string => addcslashes($s, '%_\\'));
		return [FakeQueryBuilder::create($this), $db];
	}

	public function testScoreSqlUsesTheDocumentedWeights(): void {
		[$qb, $db] = $this->fakeDb();
		$source = self::book(1, 'Source');
		$source->setAuthorsArray(['Ann Author', 'Bo Writer']);
		$source->setLanguage('en-US');
		$sql = SuggestionService::scoreSql($qb, $db, $source, [
			self::tag(Tag::TYPE_GENRE, 'Fantasy'),
			self::tag(Tag::TYPE_GENRE, 'fantasy'),
			self::tag(Tag::TYPE_TAG, 'Magic'),
		]);

		// authors: +3 each, matched on the JSON encoded name
		$this->assertSame(2, substr_count($sql, 'THEN 3 ELSE 0'));
		$this->assertStringContainsString('"Ann Author"', $sql);
		$this->assertStringContainsString('"Bo Writer"', $sql);
		// genres +2, tags +1 (one sub-select per type)
		$this->assertStringContainsString(') * 2', $sql);
		$this->assertStringContainsString(') * 1', $sql);
		$this->assertSame(1, substr_count($sql, ') * 2'));
		// language (primary subtag) and format family +1 each
		$this->assertStringContainsString("iLike(b.language,'en%')", $sql);
		$this->assertSame(2, substr_count($sql, 'THEN 1 ELSE 0'));
	}

	public function testScoreSqlFormatFamily(): void {
		[$qb, $db] = $this->fakeDb();
		$sql = SuggestionService::scoreSql($qb, $db, self::book(1, 'X', format: 'cbz'), []);
		$this->assertStringContainsString("LOWER(b.format) IN ('cbz','cbr','cb7','cbt')", $sql);
		$sql = SuggestionService::scoreSql($qb, $db, self::book(1, 'X', format: 'epub'), []);
		$this->assertStringContainsString("LOWER(b.format) NOT IN ('cbz','cbr','cb7','cbt')", $sql);
	}

	public function testScoreSqlWithoutAuthorsTagsOrLanguageOnlyScoresTheFormat(): void {
		[$qb, $db] = $this->fakeDb();
		$sql = SuggestionService::scoreSql($qb, $db, self::book(1, 'X'), []);
		$this->assertStringNotContainsString('THEN 3', $sql);
		$this->assertStringNotContainsString('b.language', $sql);
		$this->assertStringNotContainsString('SELECT', $sql);
	}

	public function testContentScoreLeavesOutLanguageAndFormat(): void {
		// a suggestion needs a shared author, genre or tag: language and format family alone never qualify
		[$qb, $db] = $this->fakeDb();
		$source = self::book(1, 'Source', format: 'cbz');
		$source->setAuthorsArray(['Ann Author']);
		$source->setLanguage('en');
		$sql = SuggestionService::scoreSql($qb, $db, $source, [self::tag(Tag::TYPE_GENRE, 'Fantasy')], true);
		$this->assertStringContainsString('"Ann Author"', $sql);
		$this->assertStringContainsString(') * 2', $sql);
		$this->assertStringNotContainsString('b.language', $sql);
		$this->assertStringNotContainsString('b.format', $sql);
		$this->assertSame('', SuggestionService::scoreSql($qb, $db, self::book(2, 'Bare'), [], true));
	}

	public function testPrimaryLanguageAndComicFormats(): void {
		$this->assertSame('en', SuggestionService::primaryLanguage('en-US'));
		$this->assertSame('de', SuggestionService::primaryLanguage(' DE_de '));
		$this->assertNull(SuggestionService::primaryLanguage(''));
		$this->assertNull(SuggestionService::primaryLanguage(null));
		$this->assertTrue(SuggestionService::isComic('CBR'));
		$this->assertFalse(SuggestionService::isComic('epub'));
	}

	public function testPickExcludesSelfSameSeriesNonUnreadAndScoreZero(): void {
		$source = self::book(1, 'Source', 'Saga', 1.0);
		$rows = [
			['book' => $source, 'score' => 9],
			['book' => self::book(2, 'Saga 2', 'saga ', 2.0), 'score' => 8],
			['book' => self::book(3, 'Read', null, null, Book::STATUS_FINISHED), 'score' => 7],
			['book' => self::book(4, 'Reading', null, null, Book::STATUS_READING), 'score' => 7],
			['book' => self::book(5, 'Zero', null), 'score' => 0],
			['book' => self::book(6, 'Good'), 'score' => 3],
		];
		$this->assertSame([6], self::ids(SuggestionService::pick($rows, $source, 4)));
	}

	public function testPickKeepsOneBookPerSeriesAndRespectsTheLimitAndOrder(): void {
		$source = self::book(1, 'Source');
		$rows = [
			['book' => self::book(10, 'Other 1', 'Other', 1.0), 'score' => 6],
			['book' => self::book(11, 'Other 2', 'OTHER', 2.0), 'score' => 6],
			['book' => self::book(12, 'Solo A'), 'score' => 5],
			['book' => self::book(13, 'Solo B'), 'score' => 4],
			['book' => self::book(14, 'Third 1', 'Third', 1.0), 'score' => 3],
			['book' => self::book(15, 'Solo C'), 'score' => 2],
		];
		$this->assertSame([10, 12, 13, 14], self::ids(SuggestionService::pick($rows, $source, 4)));
		$this->assertSame([10, 12], self::ids(SuggestionService::pick($rows, $source, 2)));
	}

	public function testSimilarRepresentsASeriesByItsFirstUnreadVolume(): void {
		$source = self::book(1, 'Source');
		$best = self::book(21, 'Other 3', 'Other', 3.0);
		$first = self::book(20, 'Other 2', 'Other', 2.0);
		$read = self::book(19, 'Other 1', 'Other', 1.0, Book::STATUS_FINISHED);
		$db = $this->createMock(IDBConnection::class);
		$bookMapper = $this->createMock(BookMapper::class);
		$bookMapper->method('findBySeries')->with('u', ['Other'])->willReturn([$best, $read, $first]);
		$tagMapper = $this->createMock(TagMapper::class);
		$tagMapper->method('findByBooks')->willReturn([]);
		$service = new class($db, $bookMapper, $tagMapper, $this->createMock(LibraryService::class), [['book' => $best, 'score' => 5], ['book' => $first, 'score' => 4]]) extends SuggestionService {
			/** @param list<array{book: Book, score: int}> $rows */
			public function __construct(
				IDBConnection $db,
				BookMapper $bm,
				TagMapper $tm,
				LibraryService $ls,
				private array $rows,
			) {
				parent::__construct($db, $bm, $tm, $ls);
			}

			protected function candidates(string $userId, Book $book, array $tags): array {
				return $this->rows;
			}
		};

		$this->assertSame([20], self::ids($service->similar('u', $source, 4)));
	}

	public function testSuggestNextIsNullWhenTheNextVolumeIsFinished(): void {
		$source = self::book(1, 'One', 'Saga', 1.0);
		$library = $this->createMock(LibraryService::class);
		$library->method('nextVolume')->willReturnOnConsecutiveCalls(
			self::book(2, 'Two', 'Saga', 2.0, Book::STATUS_FINISHED),
			self::book(3, 'Three', 'Saga', 3.0, Book::STATUS_READING),
			null,
		);
		$service = new class($this->createMock(IDBConnection::class), $this->createMock(BookMapper::class), $this->createMock(TagMapper::class), $library) extends SuggestionService {
			public function similar(string $userId, Book $book, int $limit): array {
				return [];
			}
		};
		$this->assertNull($service->suggest('u', $source, 4)['next']);
		$this->assertSame(3, $service->suggest('u', $source, 4)['next']?->getFileId());
		$this->assertNull($service->suggest('u', $source, 4)['next']);
	}
}
