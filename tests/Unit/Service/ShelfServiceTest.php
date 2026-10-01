<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Shelf;
use OCA\EbookReader\Db\ShelfBook;
use OCA\EbookReader\Db\ShelfBookMapper;
use OCA\EbookReader\Db\ShelfMapper;
use OCA\EbookReader\Service\BookQuery;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\ShelfException;
use OCA\EbookReader\Service\ShelfService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ShelfServiceTest extends TestCase {
	private ShelfMapper&MockObject $shelfMapper;
	private ShelfBookMapper&MockObject $shelfBooks;
	private BookMapper&MockObject $bookMapper;
	private LibraryService&MockObject $library;
	private ShelfService $service;
	/** @var array<int, Shelf> */
	private array $store = [];
	/** @var array<int, list<int>> shelf id => ordered file ids */
	private array $assigned = [];
	/** @var list<int> file ids of the user's own library */
	private array $ownFiles = [1, 2, 3, 4, 5];
	private int $nextId = 1;
	private ITimeFactory&MockObject $time;

	protected function setUp(): void {
		$this->store = [];
		$this->assigned = [];
		$this->nextId = 1;
		$this->shelfMapper = $this->createMock(ShelfMapper::class);
		$this->shelfBooks = $this->createMock(ShelfBookMapper::class);
		$this->bookMapper = $this->createMock(BookMapper::class);
		$this->library = $this->createMock(LibraryService::class);
		$this->library->method('countBooks')->willReturn(0);
		$this->library->method('findBooks')->willReturn(['books' => [], 'total' => 0]);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(\DateTimeImmutable::createFromFormat('U.u', '1800000000.500000'));
		$this->time = $time;

		$this->shelfMapper->method('findByUser')->willReturnCallback(fn (string $u): array => array_values(array_filter($this->store, static fn (Shelf $s): bool => $s->getUserId() === $u)));
		$this->shelfMapper->method('findByUserAndId')->willReturnCallback(function (string $u, int $id): Shelf {
			if (isset($this->store[$id]) && $this->store[$id]->getUserId() === $u) {
				return $this->store[$id];
			}
			throw new DoesNotExistException('');
		});
		$this->shelfMapper->method('insert')->willReturnCallback(function (Shelf $s): Shelf {
			$s->setId($this->nextId++);
			$this->store[$s->getId()] = $s;
			return $s;
		});
		$this->shelfMapper->method('update')->willReturnArgument(0);
		$this->shelfMapper->method('delete')->willReturnCallback(function (Shelf $s): Shelf {
			unset($this->store[$s->getId()]);
			return $s;
		});
		$this->shelfMapper->method('findIdsByUser')->willReturnCallback(fn (string $u): array => array_keys(array_filter($this->store, static fn (Shelf $s): bool => $s->getUserId() === $u)));

		$this->bookMapper->method('findByUserAndFiles')->willReturnCallback(function (string $u, array $ids): array {
			$out = [];
			foreach ($ids as $id) {
				if ($u === 'u' && in_array($id, $this->ownFiles, true)) {
					$b = new Book();
					$b->setFileId($id);
					$out[] = $b;
				}
			}
			return $out;
		});

		$this->shelfBooks->method('findExisting')->willReturnCallback(fn (int $s, array $ids): array => array_values(array_intersect($ids, $this->assigned[$s] ?? [])));
		$this->shelfBooks->method('maxPosition')->willReturnCallback(fn (int $s): int => count($this->assigned[$s] ?? []) - 1);
		$this->shelfBooks->method('insert')->willReturnCallback(function (ShelfBook $r): ShelfBook {
			$this->assigned[$r->getShelfId()][] = $r->getFileId();
			return $r;
		});
		$this->shelfBooks->method('deleteFiles')->willReturnCallback(function (int $s, array $ids): int {
			$before = count($this->assigned[$s] ?? []);
			$this->assigned[$s] = array_values(array_diff($this->assigned[$s] ?? [], $ids));
			return $before - count($this->assigned[$s]);
		});
		$this->shelfBooks->method('findFileIds')->willReturnCallback(fn (int $s): array => $this->assigned[$s] ?? []);
		$this->shelfBooks->method('countsByShelf')->willReturnCallback(fn (): array => array_map('count', $this->assigned));
		$this->shelfBooks->method('coverFileIds')->willReturnCallback(fn (int $s): array => array_slice($this->assigned[$s] ?? [], 0, 4));
		$this->shelfBooks->method('deleteByShelf')->willReturnCallback(function (int $s): void {
			unset($this->assigned[$s]);
		});
		$this->shelfBooks->method('deleteByShelves')->willReturnCallback(function (array $ids): void {
			foreach ($ids as $s) {
				unset($this->assigned[$s]);
			}
		});

		$this->service = new ShelfService($this->shelfMapper, $this->shelfBooks, $this->bookMapper, $this->library, $time);
	}

	/** Asserts that the callable throws a ShelfException with the given reason. */
	private function assertShelfError(string $reason, callable $fn): void {
		try {
			$fn();
		} catch (ShelfException $e) {
			$this->assertSame($reason, $e->reason, $e->getMessage());
			return;
		}
		$this->fail('ShelfException (' . $reason . ') expected');
	}

	public function testCreateManualShelf(): void {
		$shelf = $this->service->create('u', '  Favoriten  ', 'manual');
		$this->assertSame('Favoriten', $shelf['name']);
		$this->assertSame('manual', $shelf['type']);
		$this->assertNull($shelf['query']);
		$this->assertSame(0, $shelf['count']);
		$this->assertSame([], $shelf['coverFileIds']);
		$this->assertSame(1800000000500, $shelf['createdAt']);
		$this->assertSame('u', $this->store[$shelf['id']]->getUserId());
	}

	public function testNameValidation(): void {
		$this->assertShelfError(ShelfException::INVALID, fn () => $this->service->create('u', '   ', 'manual'));
		$this->assertShelfError(ShelfException::INVALID, fn () => $this->service->create('u', str_repeat('x', 256), 'manual'));
		$this->service->create('u', str_repeat('ä', 255), 'manual');
		$this->assertCount(1, $this->store);
	}

	public function testInvalidType(): void {
		$this->assertShelfError(ShelfException::INVALID, fn () => $this->service->create('u', 'A', 'weird'));
	}

	public function testNamesAreUniquePerUserCaseInsensitive(): void {
		$this->service->create('u', 'Krimi', 'manual');
		$this->assertShelfError(ShelfException::EXISTS, fn () => $this->service->create('u', 'KRIMI', 'manual'));
		// another user may use the same name
		$this->service->create('other', 'Krimi', 'manual');
		$this->assertCount(2, $this->store);
	}

	public function testAtMost200ShelvesPerUser(): void {
		for ($i = 0; $i < ShelfService::MAX_SHELVES; $i++) {
			$this->service->create('u', 'Regal ' . $i, 'manual');
		}
		$this->assertShelfError(ShelfException::LIMIT, fn () => $this->service->create('u', 'one too many', 'manual'));
		// the limit is per user
		$this->service->create('other', 'x', 'manual');
		$this->assertCount(201, $this->store);
	}

	public function testSortOrderOfNewShelvesIsAppended(): void {
		$a = $this->service->create('u', 'A', 'manual');
		$b = $this->service->create('u', 'B', 'manual');
		$this->assertSame(0, $a['sortOrder']);
		$this->assertSame(1, $b['sortOrder']);
	}

	public function testSmartShelfNeedsValidQuery(): void {
		$this->assertShelfError(ShelfException::INVALID, fn () => $this->service->create('u', 'S', 'smart'));
		$this->assertShelfError(ShelfException::INVALID, fn () => $this->service->create('u', 'S', 'smart', ['bogus' => 1]));
		$this->assertCount(0, $this->store);
	}

	public function testSmartShelfStoresNormalisedQueryAndReportsCount(): void {
		$book = new Book();
		$book->setFileId(42);
		$this->library = $this->createMock(LibraryService::class);
		$this->library->method('countBooks')->willReturn(7);
		$this->library->method('findBooks')->willReturn(['books' => [$book], 'total' => 7]);
		$this->service = new ShelfService($this->shelfMapper, $this->shelfBooks, $this->bookMapper, $this->library, $this->time);
		$shelf = $this->service->create('u', 'Fantasy', 'smart', ['include' => ['tag:Fantasy/*', 'tag:fantasy/*', 'genre: Krimi '], 'match' => 'any']);
		$this->assertSame('smart', $shelf['type']);
		$this->assertSame(7, $shelf['count']);
		$this->assertSame([42], $shelf['coverFileIds']);
		$this->assertNotNull($shelf['query']);
		$this->assertSame(['tag:Fantasy/*', 'genre:Krimi'], $shelf['query']['include']);
		$this->assertSame('any', $shelf['query']['match']);
		$this->assertSame('title', $shelf['query']['sort']);
		$this->assertSame('asc', $shelf['query']['order']);
	}

	public function testListIsScopedToUserAndCountsManualShelves(): void {
		$a = $this->service->create('u', 'A', 'manual');
		$this->service->create('other', 'B', 'manual');
		$this->service->addBooks('u', $a['id'], [1, 2]);
		$list = $this->service->list('u');
		$this->assertCount(1, $list);
		$this->assertSame(2, $list[0]['count']);
		$this->assertSame([1, 2], $list[0]['coverFileIds']);
	}

	public function testUpdateRenameAndConflicts(): void {
		$a = $this->service->create('u', 'A', 'manual');
		$this->service->create('u', 'B', 'manual');
		$renamed = $this->service->update('u', $a['id'], 'a', false, null, null);
		$this->assertSame('a', $renamed['name']);
		$this->assertShelfError(ShelfException::EXISTS, fn () => $this->service->update('u', $a['id'], 'b', false, null, null));
		$this->assertShelfError(ShelfException::INVALID, fn () => $this->service->update('u', $a['id'], '', false, null, null));
		$this->assertShelfError(ShelfException::INVALID, fn () => $this->service->update('u', $a['id'], null, false, null, -1));
		$this->assertSame(5, $this->service->update('u', $a['id'], null, false, null, 5)['sortOrder']);
	}

	public function testUpdateQueryOnlyForSmartShelves(): void {
		$manual = $this->service->create('u', 'M', 'manual');
		$this->assertShelfError(ShelfException::INVALID, fn () => $this->service->update('u', $manual['id'], null, true, ['include' => ['tag:x']], null));
		$smart = $this->service->create('u', 'S', 'smart', ['include' => ['tag:x']]);
		$this->assertShelfError(ShelfException::INVALID, fn () => $this->service->update('u', $smart['id'], null, true, null, null));
		$updated = $this->service->update('u', $smart['id'], null, true, ['include' => ['author:Doe']], null);
		$this->assertNotNull($updated['query']);
		$this->assertSame(['author:Doe'], $updated['query']['include']);
	}

	public function testOtherUsersShelfIsNotFound(): void {
		$a = $this->service->create('u', 'A', 'manual');
		$this->assertShelfError(ShelfException::NOT_FOUND, fn () => $this->service->update('evil', $a['id'], 'x', false, null, null));
		$this->assertShelfError(ShelfException::NOT_FOUND, fn () => $this->service->delete('evil', $a['id']));
		$this->assertShelfError(ShelfException::NOT_FOUND, fn () => $this->service->addBooks('evil', $a['id'], [1]));
		$this->assertShelfError(ShelfException::NOT_FOUND, fn () => $this->service->removeBooks('evil', $a['id'], [1]));
		$this->assertShelfError(ShelfException::NOT_FOUND, fn () => $this->service->reorder('evil', $a['id'], [1]));
		$this->assertCount(1, $this->store);
	}

	public function testDeleteRemovesAssignmentsOnly(): void {
		$a = $this->service->create('u', 'A', 'manual');
		$this->service->addBooks('u', $a['id'], [1, 2]);
		$this->service->delete('u', $a['id']);
		$this->assertSame([], $this->store);
		$this->assertArrayNotHasKey($a['id'], $this->assigned);
		$this->bookMapper->expects($this->never())->method('delete');
	}

	public function testAddBooksOnlyOwnLibraryBooks(): void {
		$a = $this->service->create('u', 'A', 'manual');
		$result = $this->service->addBooks('u', $a['id'], [1, 2, 99, 2, 3]);
		// 99 is not in the library, 2 is a duplicate within the request
		$this->assertSame(['added' => 3, 'skipped' => 1], $result);
		$this->assertSame([1, 2, 3], $this->assigned[$a['id']]);
		// adding again skips the existing ones
		$this->assertSame(['added' => 1, 'skipped' => 1], $this->service->addBooks('u', $a['id'], [3, 4]));
		$this->assertSame([1, 2, 3, 4], $this->assigned[$a['id']]);
	}

	public function testAddBooksValidation(): void {
		$a = $this->service->create('u', 'A', 'manual');
		$this->assertShelfError(ShelfException::INVALID, fn () => $this->service->addBooks('u', $a['id'], range(1, 501)));
		$this->assertSame(['added' => 0, 'skipped' => 0], $this->service->addBooks('u', $a['id'], ['x', -1, 0, 1.5]));
		$this->assertSame(['added' => 5, 'skipped' => 0], $this->service->addBooks('u', $a['id'], range(1, 5)));
		$this->assertSame(500, count(ShelfService::cleanFileIds(range(1, 500))));
	}

	public function testBooksCannotBeAddedToSmartShelves(): void {
		$s = $this->service->create('u', 'S', 'smart', ['include' => ['tag:x']]);
		$this->assertShelfError(ShelfException::INVALID, fn () => $this->service->addBooks('u', $s['id'], [1]));
		$this->assertShelfError(ShelfException::INVALID, fn () => $this->service->removeBooks('u', $s['id'], [1]));
		$this->assertShelfError(ShelfException::INVALID, fn () => $this->service->reorder('u', $s['id'], [1]));
	}

	public function testRemoveBooks(): void {
		$a = $this->service->create('u', 'A', 'manual');
		$this->service->addBooks('u', $a['id'], [1, 2, 3]);
		$this->assertSame(['removed' => 2], $this->service->removeBooks('u', $a['id'], [1, 3, 9]));
		$this->assertSame([2], $this->assigned[$a['id']]);
	}

	public function testReorderPutsListedFirstAndKeepsTheRest(): void {
		$a = $this->service->create('u', 'A', 'manual');
		$this->service->addBooks('u', $a['id'], [1, 2, 3, 4]);
		$result = $this->service->reorder('u', $a['id'], [3, 99, 1]);
		// 99 is not on the shelf; 2 and 4 follow in their old order
		$this->assertSame(['fileIds' => [3, 1, 2, 4]], $result);
	}

	public function testReorderWritesPositions(): void {
		$a = $this->service->create('u', 'A', 'manual');
		$this->service->addBooks('u', $a['id'], [1, 2]);
		$calls = [];
		$mapper = $this->createMock(ShelfBookMapper::class);
		$mapper->method('findFileIds')->willReturn([1, 2]);
		$mapper->method('updatePosition')->willReturnCallback(function (int $s, int $f, int $p) use (&$calls): void {
			$calls[] = [$f, $p];
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new \DateTimeImmutable());
		$service = new ShelfService($this->shelfMapper, $mapper, $this->bookMapper, $this->library, $time);
		$service->reorder('u', $a['id'], [2, 1]);
		$this->assertSame([[2, 0], [1, 1]], $calls);
	}

	public function testDeleteAllForUser(): void {
		$a = $this->service->create('u', 'A', 'manual');
		$b = $this->service->create('other', 'B', 'manual');
		$this->service->addBooks('u', $a['id'], [1]);
		$this->shelfMapper->expects($this->once())->method('deleteByUser')->with('u');
		$this->service->deleteAllForUser('u');
		$this->assertArrayNotHasKey($a['id'], $this->assigned);
		$this->assertArrayHasKey($b['id'], $this->store);
	}

	// --- smart query validation, loop prevention ---

	public function testValidateQueryLimits(): void {
		$this->assertShelfError(ShelfException::INVALID, fn () => ShelfService::validateQuery(['search' => str_repeat('a', 5000)]));
		$terms = [];
		for ($i = 0; $i < 51; $i++) {
			$terms[] = 'tag:t' . $i;
		}
		$this->assertShelfError(ShelfException::INVALID, fn () => ShelfService::validateQuery(['include' => $terms]));
		$q = ShelfService::validateQuery(['include' => array_slice($terms, 0, 50)]);
		$this->assertCount(50, $q['include']);
		$this->assertShelfError(ShelfException::INVALID, fn () => ShelfService::validateQuery(['include' => ['tag:a'], 'exclude' => array_slice($terms, 0, 50)]));
	}

	public function testValidateQueryRejectsUnknownKeysAndBadValues(): void {
		$this->assertShelfError(ShelfException::INVALID, fn () => ShelfService::validateQuery(['evil' => 1]));
		$this->assertShelfError(ShelfException::INVALID, fn () => ShelfService::validateQuery(['include' => 'tag:a']));
		$this->assertShelfError(ShelfException::INVALID, fn () => ShelfService::validateQuery(['include' => ['nonsense']]));
		$this->assertShelfError(ShelfException::INVALID, fn () => ShelfService::validateQuery(['include' => [5]]));
		$this->assertShelfError(ShelfException::INVALID, fn () => ShelfService::validateQuery(['match' => 'some']));
		$this->assertShelfError(ShelfException::INVALID, fn () => ShelfService::validateQuery(['status' => 'done']));
		$this->assertShelfError(ShelfException::INVALID, fn () => ShelfService::validateQuery(['sort' => 'weird']));
		$this->assertShelfError(ShelfException::INVALID, fn () => ShelfService::validateQuery(['sort' => 'shelf']));
		$this->assertShelfError(ShelfException::INVALID, fn () => ShelfService::validateQuery(['order' => 'up']));
	}

	public function testValidateQueryDefaults(): void {
		$this->assertSame([
			'include' => [], 'exclude' => [], 'match' => 'all', 'search' => '', 'status' => null, 'sort' => 'title', 'order' => 'asc',
		], ShelfService::validateQuery([]));
	}

	public function testSmartQueryWithShelfTermIsRejected(): void {
		$this->assertShelfError(ShelfException::INVALID, fn () => ShelfService::validateQuery(['include' => ['shelf:3']]));
		$this->assertShelfError(ShelfException::INVALID, fn () => ShelfService::validateQuery(['exclude' => ['SHELF: 3']]));
		$this->assertShelfError(ShelfException::INVALID, fn () => $this->service->create('u', 'Loop', 'smart', ['include' => ['shelf:1']]));
	}

	public function testToBookQueryDropsShelfTermsOfStoredQueries(): void {
		// stored data could have been written by an older version or manipulated: shelf terms never survive resolution
		$bq = ShelfService::toBookQuery([
			'include' => ['shelf:5', 'tag:Fantasy/*', ' Shelf :6'],
			'exclude' => ['shelf:7', 'author:Doe'],
			'match' => 'any',
			'search' => 'dragon',
			'status' => 'unread',
			'sort' => 'rating',
			'order' => 'desc',
		]);
		$this->assertSame([['type' => 'tag', 'name' => 'Fantasy/*']], $bq->include);
		$this->assertSame([['type' => 'author', 'name' => 'Doe']], $bq->exclude);
		$this->assertSame('any', $bq->match);
		$this->assertSame('dragon', $bq->search);
		$this->assertSame('unread', $bq->status);
		// sort/order only with $withSort
		$this->assertSame('title', $bq->sort);
		$withSort = ShelfService::toBookQuery(['sort' => 'rating', 'order' => 'desc'], 4, true);
		$this->assertSame('rating', $withSort->sort);
		$this->assertSame('desc', $withSort->order);
		$this->assertSame(4, $withSort->limit);
		// a stored sort=shelf makes no sense for a smart shelf
		$this->assertSame('title', ShelfService::toBookQuery(['sort' => 'shelf'], 4, true)->sort);
	}

	public function testToBookQueryToleratesGarbage(): void {
		$bq = ShelfService::toBookQuery(['include' => 'x', 'exclude' => [1, null], 'match' => []]);
		$this->assertSame([], $bq->include);
		$this->assertSame([], $bq->exclude);
		$this->assertSame(BookQuery::MATCH_ALL, $bq->match);
	}
}
