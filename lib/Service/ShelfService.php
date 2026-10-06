<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Shelf;
use OCA\EbookReader\Db\ShelfBook;
use OCA\EbookReader\Db\ShelfBookMapper;
use OCA\EbookReader\Db\ShelfMapper;
use OCA\EbookReader\Db\ShelfShare;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;

/**
 * Manual and smart shelves of a user. Everything is scoped by user id.
 *
 * @psalm-import-type EbookReaderShelf from \OCA\EbookReader\ResponseDefinitions
 * @psalm-import-type EbookReaderSmartQuery from \OCA\EbookReader\ResponseDefinitions
 */
class ShelfService {
	public const MAX_SHELVES = 200;
	public const MAX_NAME_LENGTH = 255;
	public const MAX_QUERY_BYTES = 4096;
	public const MAX_FILE_IDS = 500;
	public const MAX_SEARCH_LENGTH = 255;
	public const COVERS = 4;
	private const QUERY_KEYS = ['include', 'exclude', 'match', 'search', 'status', 'sort', 'order'];

	public function __construct(
		private ShelfMapper $shelves,
		private ShelfBookMapper $shelfBooks,
		private BookMapper $books,
		private LibraryService $library,
		private ITimeFactory $time,
		private ?ShareService $sharing = null,
	) {
	}

	private function nowMs(): int {
		return (int)$this->time->now()->format('Uv');
	}

	/** @return list<EbookReaderShelf> own shelves sorted by sortOrder, then name; then the shelves shared with the user */
	public function list(string $userId): array {
		$counts = $this->shelfBooks->countsByShelf($userId);
		$shareCounts = $this->sharing?->shelfShareCounts($userId) ?? [];
		$out = [];
		foreach ($this->shelves->findByUser($userId) as $shelf) {
			$out[] = $this->toApi($userId, $shelf, $counts, $shareCounts);
		}
		if ($this->sharing !== null) {
			foreach ($this->sharing->incomingShelves($userId) as $entry) {
				$out[] = $this->incomingToApi($userId, $entry['share'], $entry['shelf']);
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed>|null $query smart query (required for smart shelves)
	 * @return EbookReaderShelf
	 * @throws ShelfException
	 */
	public function create(string $userId, string $name, string $type, ?array $query = null): array {
		$name = self::validateName($name);
		if ($type !== Shelf::TYPE_MANUAL && $type !== Shelf::TYPE_SMART) {
			throw new ShelfException('type must be manual or smart', ShelfException::INVALID);
		}
		$normalized = null;
		if ($type === Shelf::TYPE_SMART) {
			if ($query === null) {
				throw new ShelfException('A smart shelf needs a query', ShelfException::INVALID);
			}
			$normalized = self::validateQuery($query);
		}
		$existing = $this->shelves->findByUser($userId);
		if (count($existing) >= self::MAX_SHELVES) {
			throw new ShelfException('At most ' . self::MAX_SHELVES . ' shelves are allowed', ShelfException::LIMIT);
		}
		$this->assertNameFree($existing, $name, null);
		$sortOrder = 0;
		foreach ($existing as $s) {
			$sortOrder = max($sortOrder, $s->getSortOrder() + 1);
		}
		$now = $this->nowMs();
		$shelf = new Shelf();
		$shelf->setUserId($userId);
		$shelf->setName($name);
		$shelf->setType($type);
		$shelf->setQuery($normalized !== null ? json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null);
		$shelf->setSortOrder($sortOrder);
		$shelf->setCreatedAt($now);
		$shelf->setUpdatedAt($now);
		$shelf = $this->shelves->insert($shelf);
		return $this->toApi($userId, $shelf, $this->shelfBooks->countsByShelf($userId));
	}

	/**
	 * @param array<string, mixed>|null $query new smart query (only if $queryGiven)
	 * @return EbookReaderShelf
	 * @throws ShelfException
	 */
	public function update(string $userId, int $id, ?string $name, bool $queryGiven, ?array $query, ?int $sortOrder): array {
		$shelf = $this->get($userId, $id);
		if ($name !== null) {
			$name = self::validateName($name);
			$this->assertNameFree($this->shelves->findByUser($userId), $name, $shelf->getId());
			$shelf->setName($name);
		}
		if ($queryGiven) {
			if (!$shelf->isSmart()) {
				throw new ShelfException('Only smart shelves have a query', ShelfException::INVALID);
			}
			if ($query === null) {
				throw new ShelfException('A smart shelf needs a query', ShelfException::INVALID);
			}
			$shelf->setQuery(json_encode(self::validateQuery($query), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		}
		if ($sortOrder !== null) {
			if ($sortOrder < 0 || $sortOrder > 1000000) {
				throw new ShelfException('sortOrder out of range', ShelfException::INVALID);
			}
			$shelf->setSortOrder($sortOrder);
		}
		$shelf->setUpdatedAt($this->nowMs());
		$shelf = $this->shelves->update($shelf);
		if ($queryGiven) {
			$this->sharing?->syncShelf($shelf->getId());
		}
		return $this->toApi($userId, $shelf, $this->shelfBooks->countsByShelf($userId));
	}

	/**
	 * Deletes the shelf and its assignments; the books stay. Its shares are removed first.
	 * @throws ShelfException
	 */
	public function delete(string $userId, int $id): void {
		$shelf = $this->get($userId, $id);
		$this->sharing?->removeShelf($shelf->getId());
		$this->shelfBooks->deleteByShelf($shelf->getId());
		$this->shelves->delete($shelf);
	}

	/**
	 * Adds books of the user's own library (non-deleted rows) to a manual shelf.
	 * @param list<int> $fileIds
	 * @return array{added: int, skipped: int} skipped = unknown, foreign or already assigned books
	 * @throws ShelfException
	 */
	public function addBooks(string $userId, int $id, array $fileIds): array {
		$shelf = $this->manualShelf($userId, $id);
		$ids = self::cleanFileIds($fileIds);
		$own = [];
		foreach ($this->books->findByUserAndFiles($userId, $ids) as $book) {
			$own[$book->getFileId()] = true;
		}
		$existing = array_flip($this->shelfBooks->findExisting($shelf->getId(), $ids));
		$position = $this->shelfBooks->maxPosition($shelf->getId());
		$now = $this->nowMs();
		$added = 0;
		foreach ($ids as $fileId) {
			if (!isset($own[$fileId]) || isset($existing[$fileId])) {
				continue;
			}
			$row = new ShelfBook();
			$row->setShelfId($shelf->getId());
			$row->setFileId($fileId);
			$row->setPosition(++$position);
			$row->setAddedAt($now);
			try {
				$this->shelfBooks->insert($row);
				$added++;
			} catch (DbException $e) {
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				// concurrently added: counts as skipped
			}
		}
		if ($added > 0) {
			$shelf->setUpdatedAt($now);
			$this->shelves->update($shelf);
			$this->sharing?->syncShelf($shelf->getId());
		}
		return ['added' => $added, 'skipped' => count($ids) - $added];
	}

	/**
	 * @param list<int> $fileIds
	 * @return array{removed: int}
	 * @throws ShelfException
	 */
	public function removeBooks(string $userId, int $id, array $fileIds): array {
		$shelf = $this->manualShelf($userId, $id);
		$removed = $this->shelfBooks->deleteFiles($shelf->getId(), self::cleanFileIds($fileIds));
		if ($removed > 0) {
			$shelf->setUpdatedAt($this->nowMs());
			$this->shelves->update($shelf);
			$this->sharing?->syncShelf($shelf->getId());
		}
		return ['removed' => $removed];
	}

	/**
	 * New order of a manual shelf: the listed books first in the given order (unknown ones are ignored),
	 * books not listed follow in their current order.
	 * @param list<int> $fileIds
	 * @return array{fileIds: list<int>}
	 * @throws ShelfException
	 */
	public function reorder(string $userId, int $id, array $fileIds): array {
		$shelf = $this->manualShelf($userId, $id);
		$current = $this->shelfBooks->findFileIds($shelf->getId());
		$onShelf = array_flip($current);
		$final = [];
		foreach (self::cleanFileIds($fileIds) as $fileId) {
			if (isset($onShelf[$fileId])) {
				$final[] = $fileId;
				unset($onShelf[$fileId]);
			}
		}
		foreach ($current as $fileId) {
			if (isset($onShelf[$fileId])) {
				$final[] = $fileId;
			}
		}
		foreach ($final as $position => $fileId) {
			$this->shelfBooks->updatePosition($shelf->getId(), $fileId, $position);
		}
		$shelf->setUpdatedAt($this->nowMs());
		$this->shelves->update($shelf);
		return ['fileIds' => $final];
	}

	/** Removes all shelves and assignments of a user (user deletion). */
	public function deleteAllForUser(string $userId): void {
		$ids = $this->shelves->findIdsByUser($userId);
		if ($ids !== []) {
			$this->shelfBooks->deleteByShelves($ids);
		}
		$this->shelves->deleteByUser($userId);
	}

	/**
	 * An own shelf of the user (all changes go through here); a shelf shared with the user is read-only (FORBIDDEN).
	 * @throws ShelfException
	 */
	public function get(string $userId, int $id): Shelf {
		try {
			return $this->shelves->findByUserAndId($userId, $id);
		} catch (DoesNotExistException) {
			if ($this->sharing?->isIncomingShelf($userId, $id) === true) {
				throw new ShelfException('Shared shelves are read-only', ShelfException::FORBIDDEN);
			}
			throw new ShelfException('Shelf not found', ShelfException::NOT_FOUND);
		}
	}

	/** @throws ShelfException */
	private function manualShelf(string $userId, int $id): Shelf {
		$shelf = $this->get($userId, $id);
		if ($shelf->isSmart()) {
			throw new ShelfException('Books can only be assigned to manual shelves', ShelfException::INVALID);
		}
		return $shelf;
	}

	/**
	 * @param array<int, int> $counts manual shelf counts by shelf id
	 * @param ?array<int, int> $shareCounts recipients by shelf id (null = loaded here)
	 * @return EbookReaderShelf
	 */
	private function toApi(string $userId, Shelf $shelf, array $counts, ?array $shareCounts = null): array {
		$shareCounts ??= $this->sharing?->shelfShareCounts($userId) ?? [];
		$query = $shelf->isSmart() ? ($shelf->getQueryArray() ?? []) : null;
		if ($query !== null) {
			$bookQuery = self::toBookQuery($query, self::COVERS, true);
			$count = $this->library->countBooks($userId, $bookQuery);
			$covers = array_map(static fn ($b): int => $b->getFileId(), $this->library->findBooks($userId, $bookQuery)['books']);
			/** @var EbookReaderSmartQuery $query */
			$query = self::normalizeQuery($query);
		} else {
			$count = $counts[$shelf->getId()] ?? 0;
			$covers = $this->shelfBooks->coverFileIds($shelf->getId(), $userId, self::COVERS);
		}
		return [
			'id' => $shelf->getId(),
			'name' => $shelf->getName(),
			'type' => $shelf->isSmart() ? 'smart' : 'manual',
			'query' => $query,
			'count' => $count,
			'coverFileIds' => array_slice($covers, 0, self::COVERS),
			'sortOrder' => $shelf->getSortOrder(),
			'createdAt' => $shelf->getCreatedAt(),
			'updatedAt' => $shelf->getUpdatedAt(),
			'owner' => $userId,
			'ownerDisplayName' => $this->sharing?->displayName($userId) ?? $userId,
			'readOnly' => false,
			'shareCount' => $shareCounts[$shelf->getId()] ?? 0,
		];
	}

	/**
	 * A shelf shared with the user: its books are the shared files that are in the recipient's library (`shelf:<id>`
	 * resolves to them). The owner's smart query is not exposed (it may reveal the owner's tags or reading status).
	 * @return EbookReaderShelf
	 */
	private function incomingToApi(string $userId, ShelfShare $share, Shelf $shelf): array {
		$base = ['include' => ['shelf:' . $shelf->getId()], 'sort' => $shelf->isSmart() ? 'title' : 'shelf', 'order' => 'asc'];
		$count = $this->library->countBooks($userId, BookQuery::fromRequestParams($base));
		$covers = array_map(static fn ($b): int => $b->getFileId(), $this->library->findBooks($userId, BookQuery::fromRequestParams($base + ['limit' => self::COVERS]))['books']);
		return [
			'id' => $shelf->getId(),
			'name' => $shelf->getName(),
			'type' => $shelf->isSmart() ? 'smart' : 'manual',
			'query' => null,
			'count' => $count,
			'coverFileIds' => array_slice($covers, 0, self::COVERS),
			'sortOrder' => $shelf->getSortOrder(),
			'createdAt' => $share->getCreatedAt(),
			'updatedAt' => $shelf->getUpdatedAt(),
			'owner' => $share->getOwnerId(),
			'ownerDisplayName' => $this->sharing?->displayName($share->getOwnerId()) ?? $share->getOwnerId(),
			'readOnly' => true,
			'shareCount' => 0,
		];
	}

	/** @param list<Shelf> $existing */
	private function assertNameFree(array $existing, string $name, ?int $exceptId): void {
		$key = mb_strtolower($name);
		foreach ($existing as $s) {
			if ($s->getId() !== $exceptId && mb_strtolower($s->getName()) === $key) {
				throw new ShelfException('A shelf with this name already exists', ShelfException::EXISTS);
			}
		}
	}

	/** @throws ShelfException */
	public static function validateName(string $name): string {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
			throw new ShelfException('The name must have 1 to ' . self::MAX_NAME_LENGTH . ' characters', ShelfException::INVALID);
		}
		return $name;
	}

	/**
	 * @param array<mixed> $fileIds
	 * @return list<int> unique positive ids
	 * @throws ShelfException more than MAX_FILE_IDS
	 */
	public static function cleanFileIds(array $fileIds): array {
		if (count($fileIds) > self::MAX_FILE_IDS) {
			throw new ShelfException('At most ' . self::MAX_FILE_IDS . ' books per request', ShelfException::INVALID);
		}
		$out = [];
		foreach ($fileIds as $id) {
			if (is_int($id) || (is_string($id) && ctype_digit($id))) {
				$id = (int)$id;
				if ($id > 0) {
					$out[$id] = $id;
				}
			}
		}
		return array_values($out);
	}

	/**
	 * Validates a smart query (library filter state) and returns its normalised form.
	 * Max. 4 KB, known keys only, at most 50 terms, `shelf:` terms are rejected (smart shelves must not reference shelves).
	 *
	 * @param array<mixed> $query
	 * @return array{include: list<string>, exclude: list<string>, match: string, search: string, status: ?string, sort: string, order: string}
	 * @throws ShelfException
	 */
	public static function validateQuery(array $query): array {
		$encoded = json_encode($query, JSON_UNESCAPED_UNICODE);
		if ($encoded === false || strlen($encoded) > self::MAX_QUERY_BYTES) {
			throw new ShelfException('The query is too large (max. 4 KB)', ShelfException::INVALID);
		}
		foreach (array_keys($query) as $key) {
			if (!in_array($key, self::QUERY_KEYS, true)) {
				throw new ShelfException('Unknown query key: ' . (string)$key, ShelfException::INVALID);
			}
		}
		$terms = 0;
		$lists = [];
		foreach (['include', 'exclude'] as $key) {
			$raw = $query[$key] ?? [];
			if (!is_array($raw) || !array_is_list($raw)) {
				throw new ShelfException($key . ' must be a list of "type:name" terms', ShelfException::INVALID);
			}
			$terms += count($raw);
			if ($terms > BookQuery::MAX_FILTER_ENTRIES) {
				throw new ShelfException('At most ' . BookQuery::MAX_FILTER_ENTRIES . ' terms are allowed', ShelfException::INVALID);
			}
			$list = [];
			foreach ($raw as $term) {
				$parsed = is_string($term) ? BookQuery::parseFilterEntries([$term]) : [];
				if (count($parsed) !== 1) {
					throw new ShelfException('Invalid term in ' . $key, ShelfException::INVALID);
				}
				if ($parsed[0]['type'] === 'shelf') {
					throw new ShelfException('Smart shelves must not contain shelf terms', ShelfException::INVALID);
				}
				$list[mb_strtolower($parsed[0]['type'] . ':' . $parsed[0]['name'])] ??= $parsed[0]['type'] . ':' . $parsed[0]['name'];
			}
			$lists[$key] = array_values($list);
		}
		$match = $query['match'] ?? BookQuery::MATCH_ALL;
		if ($match !== BookQuery::MATCH_ALL && $match !== BookQuery::MATCH_ANY) {
			throw new ShelfException('match must be all or any', ShelfException::INVALID);
		}
		$search = $query['search'] ?? '';
		if (!is_string($search) || mb_strlen($search) > self::MAX_SEARCH_LENGTH) {
			throw new ShelfException('Invalid search', ShelfException::INVALID);
		}
		$status = $query['status'] ?? null;
		if ($status !== null && (!is_string($status) || !in_array($status, BookQuery::STATUSES, true))) {
			throw new ShelfException('Invalid status', ShelfException::INVALID);
		}
		$sort = $query['sort'] ?? 'title';
		if (!is_string($sort) || $sort === 'shelf' || !in_array($sort, BookQuery::SORTS, true)) {
			throw new ShelfException('Invalid sort', ShelfException::INVALID);
		}
		$order = $query['order'] ?? 'asc';
		if ($order !== 'asc' && $order !== 'desc') {
			throw new ShelfException('order must be asc or desc', ShelfException::INVALID);
		}
		return [
			'include' => $lists['include'],
			'exclude' => $lists['exclude'],
			'match' => $match,
			'search' => trim($search),
			'status' => $status,
			'sort' => $sort,
			'order' => $order,
		];
	}

	/**
	 * Lenient variant of validateQuery for stored queries: invalid parts fall back to defaults.
	 * @param array<mixed> $query
	 * @return array{include: list<string>, exclude: list<string>, match: string, search: string, status: ?string, sort: string, order: string}
	 */
	public static function normalizeQuery(array $query): array {
		try {
			return self::validateQuery($query);
		} catch (ShelfException) {
			$bq = self::toBookQuery($query, BookQuery::DEFAULT_LIMIT, true);
			$fmt = static fn (array $entries): array => array_map(static fn (array $e): string => $e['type'] . ':' . $e['name'], $entries);
			return [
				'include' => $fmt($bq->include),
				'exclude' => $fmt($bq->exclude),
				'match' => $bq->match,
				'search' => $bq->search ?? '',
				'status' => $bq->status,
				'sort' => $bq->sort,
				'order' => $bq->order,
			];
		}
	}

	/**
	 * The filter of a (stored) smart query as a BookQuery. `shelf:` terms are dropped, so a smart shelf never
	 * references another shelf (no loops). Sort and order are only taken over with $withSort (cover selection).
	 *
	 * @param array<mixed> $query
	 */
	public static function toBookQuery(array $query, int $limit = BookQuery::DEFAULT_LIMIT, bool $withSort = false, int $offset = 0): BookQuery {
		$strip = static function (mixed $list): array {
			if (!is_array($list)) {
				return [];
			}
			return array_values(array_filter($list, static fn ($t): bool => is_string($t) && !preg_match('/^\s*shelf\s*:/i', $t)));
		};
		return BookQuery::fromRequestParams([
			'include' => $strip($query['include'] ?? null),
			'exclude' => $strip($query['exclude'] ?? null),
			'match' => $query['match'] ?? null,
			'search' => $query['search'] ?? null,
			'status' => $query['status'] ?? null,
			'sort' => $withSort && ($query['sort'] ?? null) !== 'shelf' ? ($query['sort'] ?? null) : null,
			'order' => $withSort ? ($query['order'] ?? null) : null,
			'limit' => $limit,
			'offset' => $offset,
		]);
	}
}
