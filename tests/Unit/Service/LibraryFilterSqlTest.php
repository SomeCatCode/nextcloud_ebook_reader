<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Shelf;
use OCA\EbookReader\Db\ShelfMapper;
use OCA\EbookReader\Db\TagMapper;
use OCA\EbookReader\Metadata\MetadataService;
use OCA\EbookReader\Metadata\SidecarService;
use OCA\EbookReader\Service\BookQuery;
use OCA\EbookReader\Service\CoverService;
use OCA\EbookReader\Service\GenreClassifier;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/OcHooksEmitterStub.php';
require_once __DIR__ . '/FakeQueryBuilder.php';

/**
 * SQL building of the filter terms (shelf:, hierarchical tag/genre terms, inSeries) at helper level:
 * the query builder is a stand-in that renders the conditions as text.
 */
class LibraryFilterSqlTest extends TestCase {
	private ShelfMapper&MockObject $shelves;
	private LibraryService $service;
	/** @var array<int, Shelf> */
	private array $shelfStore = [];

	protected function setUp(): void {
		$this->shelfStore = [];
		$this->shelves = $this->createMock(ShelfMapper::class);
		$this->shelves->method('findByUserAndId')->willReturnCallback(function (string $u, int $id): Shelf {
			if (isset($this->shelfStore[$id]) && $this->shelfStore[$id]->getUserId() === $u) {
				return $this->shelfStore[$id];
			}
			throw new DoesNotExistException('');
		});
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => FakeQueryBuilder::create($this));
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $s): string => addcslashes($s, '%_\\'));
		$settings = $this->createMock(SettingsService::class);
		$tags = $this->createMock(TagMapper::class);
		$this->service = new LibraryService(
			$this->createMock(BookMapper::class),
			$tags,
			$this->createMock(MetadataService::class),
			$this->createMock(CoverService::class),
			new GenreClassifier($settings, $tags),
			$settings,
			$this->createMock(IRootFolder::class),
			$this->createMock(IJobList::class),
			$db,
			$this->createMock(LoggerInterface::class),
			$this->createMock(SidecarService::class),
			$this->shelves,
		);
	}

	private function addShelf(int $id, string $user, string $type, ?string $query = null): void {
		$s = new Shelf();
		$s->setId($id);
		$s->setUserId($user);
		$s->setName('S' . $id);
		$s->setType($type);
		$s->setQuery($query);
		$this->shelfStore[$id] = $s;
	}

	/** @return list<string> */
	private function conditions(BookQuery $q, string $user = 'u'): array {
		$qb = FakeQueryBuilder::create($this);
		$m = new \ReflectionMethod(LibraryService::class, 'filterConditions');
		/** @var list<string> $out */
		$out = $m->invoke($this->service, $qb, $user, $q, true);
		return $out;
	}

	public function testPlainTagTermIsExact(): void {
		$c = $this->conditions(BookQuery::fromRequestParams(['include' => ['tag:Fantasy']]));
		$this->assertCount(1, $c);
		$this->assertStringContainsString("iLike(t.name,'Fantasy')", $c[0]);
		$this->assertStringNotContainsString('Fantasy/%', $c[0]);
		$this->assertStringContainsString('in(b.id,', $c[0]);
	}

	public function testHierarchicalTagTermMatchesParentAndChildren(): void {
		$c = $this->conditions(BookQuery::fromRequestParams(['include' => ['tag:Fantasy/*']]));
		$this->assertStringContainsString("iLike(t.name,'Fantasy')", $c[0]);
		$this->assertStringContainsString("iLike(t.name,'Fantasy/%')", $c[0]);
		$this->assertStringContainsString("eq(t.type,'tag')", $c[0]);
		// never a bare prefix match: "Fantasyx" must not match
		$this->assertStringNotContainsString("'Fantasy%'", $c[0]);
	}

	public function testHierarchicalGenreExcludeUsesNotIn(): void {
		$c = $this->conditions(BookQuery::fromRequestParams(['exclude' => ['genre: Fantasy / High /*']]));
		$this->assertStringStartsWith('notIn(b.id,', $c[0]);
		$this->assertStringContainsString("iLike(t.name,'Fantasy/High')", $c[0]);
		$this->assertStringContainsString("iLike(t.name,'Fantasy/High/%')", $c[0]);
		$this->assertStringContainsString("eq(t.type,'genre')", $c[0]);
	}

	public function testHierarchyBaseIsLikeEscaped(): void {
		$c = $this->conditions(BookQuery::fromRequestParams(['include' => ['tag:100%_Fun/*']]));
		$this->assertStringContainsString("iLike(t.name,'100\\%\\_Fun/%')", $c[0]);
	}

	public function testManualShelfIncludeAndExclude(): void {
		$this->addShelf(5, 'u', 'manual');
		$inc = $this->conditions(BookQuery::fromRequestParams(['include' => ['shelf:5']]));
		$this->assertStringContainsString('in(b.file_id,', $inc[0]);
		$this->assertStringContainsString("eq(sbm.shelf_id,'5')", $inc[0]);
		$exc = $this->conditions(BookQuery::fromRequestParams(['exclude' => ['shelf:5']]));
		$this->assertStringStartsWith('notIn(b.file_id,', $exc[0]);
	}

	public function testForeignOrUnknownShelfMatchesNothing(): void {
		$this->addShelf(5, 'other', 'manual');
		$this->assertSame(['(1 = 0)'], $this->conditions(BookQuery::fromRequestParams(['include' => ['shelf:5']])));
		$this->assertSame(['(1 = 0)'], $this->conditions(BookQuery::fromRequestParams(['include' => ['shelf:404']])));
		$this->assertSame(['(1 = 0)'], $this->conditions(BookQuery::fromRequestParams(['include' => ['shelf:abc']])));
		// excluding an unknown shelf has no effect
		$this->assertSame([], $this->conditions(BookQuery::fromRequestParams(['exclude' => ['shelf:404']])));
	}

	public function testSmartShelfIsResolvedToItsSavedQuery(): void {
		$this->addShelf(6, 'u', 'smart', json_encode(['include' => ['tag:Fantasy/*'], 'exclude' => ['author:Doe'], 'status' => 'unread']));
		$c = $this->conditions(BookQuery::fromRequestParams(['include' => ['shelf:6'], 'search' => 'x']));
		// the smart conditions are ANDed with the remaining filters
		$this->assertCount(2, $c);
		$this->assertStringContainsString("eq(b.read_status,'unread')", $c[0]);
		$this->assertStringContainsString("iLike(t.name,'Fantasy/%')", $c[0]);
		$this->assertStringContainsString('isNull(b.authors)', $c[0]);
		$this->assertStringContainsString("iLike(b.title,'%x%')", $c[1]);
	}

	public function testHideFinishedIsIgnoredWhenStatusIsSet(): void {
		$this->assertSame(["neq(b.read_status,'finished')"], $this->conditions(BookQuery::fromRequestParams(['hideFinished' => 1])));
		$this->assertSame(["eq(b.read_status,'finished')"], $this->conditions(BookQuery::fromRequestParams(['hideFinished' => 1, 'status' => 'finished'])));
		$this->assertSame([], $this->conditions(BookQuery::fromRequestParams(['hideFinished' => 0])));
	}

	public function testSmartShelfExclusionNegatesWholeQuery(): void {
		$this->addShelf(6, 'u', 'smart', json_encode(['include' => ['format:epub']]));
		$c = $this->conditions(BookQuery::fromRequestParams(['exclude' => ['shelf:6']]));
		$this->assertStringStartsWith('NOT (', $c[0]);
		$this->addShelf(7, 'u', 'smart', json_encode([]));
		$this->assertSame(['1 = 0'], $this->conditions(BookQuery::fromRequestParams(['exclude' => ['shelf:7']])));
		$this->assertSame(['(1 = 1)'], $this->conditions(BookQuery::fromRequestParams(['include' => ['shelf:7']])));
	}

	public function testSmartShelfCannotReferenceOtherShelves(): void {
		// manipulated stored query: shelf terms are ignored, so there is no recursion (no loop through shelf 8 itself)
		$this->addShelf(8, 'u', 'smart', json_encode(['include' => ['shelf:8', 'shelf:9'], 'exclude' => ['shelf:8']]));
		$this->addShelf(9, 'u', 'smart', json_encode(['include' => ['shelf:8']]));
		$this->assertSame(['(1 = 1)'], $this->conditions(BookQuery::fromRequestParams(['include' => ['shelf:8']])));
		$this->assertSame(['(1 = 1)'], $this->conditions(BookQuery::fromRequestParams(['include' => ['shelf:9']])));
	}

	public function testShelfTermsInMatchAny(): void {
		$this->addShelf(5, 'u', 'manual');
		$c = $this->conditions(BookQuery::fromRequestParams(['include' => ['shelf:5', 'format:cbz'], 'match' => 'any']));
		$this->assertCount(1, $c);
		$this->assertStringContainsString(' OR ', $c[0]);
	}

	public function testInSeriesConditions(): void {
		$one = $this->conditions(BookQuery::fromRequestParams(['inSeries' => '1']));
		$this->assertStringContainsString('isNotNull(b.series)', $one[0]);
		$this->assertStringContainsString("neq(b.series,'')", $one[0]);
		$zero = $this->conditions(BookQuery::fromRequestParams(['inSeries' => '0']));
		$this->assertStringContainsString('isNull(b.series)', $zero[0]);
		$this->assertStringContainsString(' OR ', $zero[0]);
		$this->assertSame([], $this->conditions(BookQuery::fromRequestParams([])));
	}

	/** @return array<string, array{string, string, string}> */
	public static function missingProvider(): array {
		return [
			'genre' => ['genre', "notIn(b.id,(SELECT ... WHERE eq(t.type,'genre')))", "in(b.id,(SELECT ... WHERE eq(t.type,'genre')))"],
			'tag' => ['tag', "notIn(b.id,(SELECT ... WHERE eq(t.type,'tag')))", "in(b.id,(SELECT ... WHERE eq(t.type,'tag')))"],
			'author' => ['author', "(isNull(b.authors) OR eq(b.authors,'') OR eq(b.authors,'[]'))", "NOT (isNull(b.authors) OR eq(b.authors,'') OR eq(b.authors,'[]'))"],
			'series' => ['series', "(isNull(b.series) OR eq(b.series,''))", "NOT (isNull(b.series) OR eq(b.series,''))"],
			'description' => ['description', "(isNull(b.description) OR eq(b.description,''))", "NOT (isNull(b.description) OR eq(b.description,''))"],
			'language' => ['language', "(isNull(b.language) OR eq(b.language,''))", "NOT (isNull(b.language) OR eq(b.language,''))"],
			'cover' => ['cover', "(isNull(b.has_cover) OR NOT (eq(b.has_cover,'1')))", "eq(b.has_cover,'1')"],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('missingProvider')]
	public function testMissingConditions(string $field, string $include, string $exclude): void {
		$c = $this->conditions(BookQuery::fromRequestParams(['include' => ['missing:' . $field]]));
		// a lone include term is wrapped by the AND/OR composite
		$this->assertSame(['(' . $include . ')'], $c);
		$c = $this->conditions(BookQuery::fromRequestParams(['exclude' => ['missing:' . $field]]));
		$this->assertSame([$exclude], $c);
	}

	public function testMissingCombinesWithOtherTermsAndMatchAny(): void {
		$q = BookQuery::fromRequestParams(['include' => ['missing:series', 'tag:Fantasy'], 'match' => 'any', 'exclude' => ['missing:cover']]);
		$c = $this->conditions($q);
		$this->assertCount(2, $c);
		$this->assertStringContainsString(' OR ', $c[0]);
		$this->assertStringContainsString('isNull(b.series)', $c[0]);
		$this->assertStringContainsString("iLike(t.name,'Fantasy')", $c[0]);
		$this->assertSame("eq(b.has_cover,'1')", $c[1]);
	}

	public function testMissingCountsHelper(): void {
		$counts = LibraryService::buildMissingCounts(
			['total' => '10', 'author' => '1', 'series' => '7', 'description' => '3', 'language' => '4', 'cover' => '2'],
			['genre' => 6, 'tag' => 10],
		);
		$this->assertSame(['genre' => 4, 'tag' => 0, 'author' => 1, 'series' => 7, 'description' => 3, 'cover' => 2, 'language' => 4], $counts);
		$empty = LibraryService::buildMissingCounts([], []);
		$this->assertSame(0, array_sum($empty));
	}

	/** @return array<string, array{string, string, string}> */
	public static function flagProvider(): array {
		return [
			'ongoing' => ['completion:ongoing', "eq(b.completion,'ongoing')", "(isNull(b.completion) OR NOT (eq(b.completion,'ongoing')))"],
			'completed' => ['completion:completed', "eq(b.completion,'completed')", "(isNull(b.completion) OR NOT (eq(b.completion,'completed')))"],
			'unknown' => ['completion:unknown', 'isNull(b.completion)', 'isNotNull(b.completion)'],
			'age exact' => ['age:16', "eq(b.age_rating,'16')", "(isNull(b.age_rating) OR NOT (eq(b.age_rating,'16')))"],
			'age up to' => ['age:<=12', "lte(b.age_rating,'12')", "(isNull(b.age_rating) OR NOT (lte(b.age_rating,'12')))"],
			'age none' => ['age:none', 'isNull(b.age_rating)', 'isNotNull(b.age_rating)'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('flagProvider')]
	public function testCompletionAndAgeConditions(string $term, string $include, string $exclude): void {
		$this->assertSame(['(' . $include . ')'], $this->conditions(BookQuery::fromRequestParams(['include' => [$term]])));
		$this->assertSame([$exclude], $this->conditions(BookQuery::fromRequestParams(['exclude' => [$term]])));
	}

	public function testSmartShelfWithAgeAndCompletionTerms(): void {
		$this->addShelf(6, 'u', 'smart', json_encode(['include' => ['age:<=12', 'completion:completed']]));
		$c = $this->conditions(BookQuery::fromRequestParams(['include' => ['shelf:6']]));
		$this->assertStringContainsString("lte(b.age_rating,'12')", $c[0]);
		$this->assertStringContainsString("eq(b.completion,'completed')", $c[0]);
	}

	public function testFlagFacetHelpers(): void {
		$this->assertSame(
			[['name' => 'ongoing', 'count' => 3], ['name' => 'completed', 'count' => 0], ['name' => 'unknown', 'count' => 5]],
			LibraryService::buildCompletionFacets(['ongoing' => 3, 'unknown' => 4, 'weird' => 1]),
		);
		$this->assertSame(
			[['name' => '0', 'count' => 1], ['name' => '6', 'count' => 0], ['name' => '12', 'count' => 2], ['name' => '16', 'count' => 0], ['name' => '18', 'count' => 4], ['name' => 'none', 'count' => 7]],
			LibraryService::buildAgeFacets(['0' => 1, '12' => 2, '18' => 4, 'none' => 7]),
		);
	}

	public function testShelfSharedWithTheUserResolvesToTheFilesSharedForIt(): void {
		// shelf 7 belongs to another user and is shared with "u" (shelf share 5)
		$this->addShelf(7, 'owner', Shelf::TYPE_SMART, '{"include":["tag:Private"]}');
		$cache = new \ReflectionProperty(LibraryService::class, 'incomingShelfCache');
		$cache->setValue($this->service, ['u|7' => ['shareId' => 5, 'type' => Shelf::TYPE_SMART]]);
		$c = $this->conditions(BookQuery::fromRequestParams(['include' => ['shelf:7']]));
		$this->assertCount(1, $c);
		$this->assertStringContainsString('in(b.file_id,', $c[0]);
		$this->assertStringContainsString("eq(fsm.shelf_share_id,'5')", $c[0]);
		$this->assertStringContainsString("eq(fsm.recipient_id,'u')", $c[0]);
		// the owner's saved query is never evaluated against the recipient's library
		$this->assertStringNotContainsString('Private', $c[0]);

		$c = $this->conditions(BookQuery::fromRequestParams(['exclude' => ['shelf:7']]));
		$this->assertStringStartsWith('notIn(b.file_id,', $c[0]);
	}

	public function testForeignShelfThatIsNotSharedMatchesNothing(): void {
		$this->addShelf(8, 'owner', Shelf::TYPE_MANUAL);
		$cache = new \ReflectionProperty(LibraryService::class, 'incomingShelfCache');
		$cache->setValue($this->service, ['u|8' => null]);
		$c = $this->conditions(BookQuery::fromRequestParams(['include' => ['shelf:8']]));
		$this->assertSame(['(1 = 0)'], $c);
	}
}
