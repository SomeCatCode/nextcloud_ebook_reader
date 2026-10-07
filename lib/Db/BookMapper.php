<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Book>
 */
class BookMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ebookreader_books', Book::class);
	}

	/**
	 * Moves metaUpdatedAt (descriptive data changed) unless the caller set it itself.
	 * @param Book $entity
	 * @return Book
	 */
	#[\Override]
	public function insert(Entity $entity): Entity {
		if ($entity instanceof Book && $entity->getMetaUpdatedAt() === 0) {
			$entity->setMetaUpdatedAt($entity->getUpdatedAt() > 0 ? $entity->getUpdatedAt() : (int)(microtime(true) * 1000.0));
		}
		return parent::insert($entity);
	}

	/**
	 * Moves metaUpdatedAt when a descriptive field (see Book::META_FIELDS) changed, unless the caller set it itself.
	 * Every writer of such fields (indexing, editor, cover upload) is covered without having to remember it.
	 * @param Book $entity
	 * @return Book
	 */
	#[\Override]
	public function update(Entity $entity): Entity {
		if ($entity instanceof Book && $entity->hasPendingMetaChange() && !array_key_exists('metaUpdatedAt', $entity->getUpdatedFields())) {
			$entity->setMetaUpdatedAt((int)(microtime(true) * 1000.0));
		}
		return parent::update($entity);
	}

	/**
	 * Finds a non-deleted book of a user by file id.
	 * @throws DoesNotExistException
	 */
	public function findByUserAndFile(string $userId, int $fileId, bool $includeDeleted = false): Book {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		if (!$includeDeleted) {
			$qb->andWhere($qb->expr()->isNull('deleted_at'));
		}
		return $this->findEntity($qb);
	}

	/**
	 * All rows (all users) for a file id.
	 * @return list<Book>
	 */
	public function findByFileId(int $fileId, bool $includeDeleted = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		if (!$includeDeleted) {
			$qb->andWhere($qb->expr()->isNull('deleted_at'));
		}
		return $this->findEntities($qb);
	}

	/**
	 * @param list<int> $fileIds
	 * @return list<Book>
	 */
	public function findByUserAndFiles(string $userId, array $fileIds): array {
		if ($fileIds === []) {
			return [];
		}
		$result = [];
		foreach (array_chunk($fileIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($this->getTableName())
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->isNull('deleted_at'));
			array_push($result, ...$this->findEntities($qb));
		}
		return $result;
	}

	/**
	 * Non-deleted books of a user in the given series (case-insensitive, exact names).
	 * @param list<string> $series
	 * @return list<Book>
	 */
	public function findBySeries(string $userId, array $series): array {
		$series = array_values(array_unique(array_filter(array_map('trim', $series), static fn (string $s): bool => $s !== '')));
		$result = [];
		foreach (array_chunk($series, 50) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$names = array_map(fn (string $s): string => $qb->expr()->iLike('series', $qb->createNamedParameter($this->db->escapeLikeParameter($s))), $chunk);
			$qb->select('*')->from($this->getTableName())
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->isNull('deleted_at'))
				->andWhere($qb->expr()->orX(...$names));
			array_push($result, ...$this->findEntities($qb));
		}
		return $result;
	}

	/**
	 * All non-deleted books of a user.
	 * @return list<Book>
	 */
	public function findAllByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		return $this->findEntities($qb);
	}

	/**
	 * Rows (including tombstones) changed after the cursor (updatedAt, id), ordered by (updated_at, id).
	 * @return list<Book>
	 */
	public function findChangedSince(string $userId, int $updatedAt, int $id, int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->gt('updated_at', $qb->createNamedParameter($updatedAt, IQueryBuilder::PARAM_INT)),
				$qb->expr()->andX(
					$qb->expr()->eq('updated_at', $qb->createNamedParameter($updatedAt, IQueryBuilder::PARAM_INT)),
					$qb->expr()->gt('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
				),
			))
			->orderBy('updated_at', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function countByUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		$res = $qb->executeQuery();
		$count = (int)$res->fetchOne();
		$res->closeCursor();
		return $count;
	}

	/** Physically removes all rows of a user. */
	public function deleteByUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->executeStatement();
	}

	/** Physically removes tombstones older than the given timestamp (ms). Returns number of rows. */
	public function deleteTombstonesOlderThan(int $deletedBeforeMs): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete($this->getTableName())
			->where($qb->expr()->isNotNull('deleted_at'))
			->andWhere($qb->expr()->lt('deleted_at', $qb->createNamedParameter($deletedBeforeMs, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Tombstones older than the timestamp (ms), id-paged, for cleaning up dependent rows before they are purged.
	 * @return list<array{id: int, user_id: string, file_id: int}>
	 */
	public function findTombstonesOlderThan(int $deletedBeforeMs, int $afterId = 0, int $limit = 1000): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'user_id', 'file_id')->from($this->getTableName())
			->where($qb->expr()->isNotNull('deleted_at'))
			->andWhere($qb->expr()->lt('deleted_at', $qb->createNamedParameter($deletedBeforeMs, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[] = ['id' => (int)$row['id'], 'user_id' => (string)$row['user_id'], 'file_id' => (int)$row['file_id']];
		}
		$res->closeCursor();
		return $out;
	}

	/** @return list<int> file ids that have no non-deleted rows any more but still had rows before (for cover cleanup) */
	public function findDistinctFileIdsByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('file_id')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$res = $qb->executeQuery();
		$ids = array_map('intval', $res->fetchAll(\PDO::FETCH_COLUMN));
		$res->closeCursor();
		return array_values($ids);
	}

	public function countActiveByFileId(int $fileId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		$res = $qb->executeQuery();
		$count = (int)$res->fetchOne();
		$res->closeCursor();
		return $count;
	}
}
