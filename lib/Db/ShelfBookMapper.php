<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Assignments of books (by file id) to manual shelves.
 *
 * @template-extends QBMapper<ShelfBook>
 */
class ShelfBookMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ebookreader_shelf_books', ShelfBook::class);
	}

	/** @return list<int> file ids of the shelf in shelf order */
	public function findFileIds(int $shelfId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id')->from($this->getTableName())
			->where($qb->expr()->eq('shelf_id', $qb->createNamedParameter($shelfId, IQueryBuilder::PARAM_INT)))
			->orderBy('position', 'ASC')
			->addOrderBy('id', 'ASC');
		$res = $qb->executeQuery();
		$ids = array_map('intval', $res->fetchAll(\PDO::FETCH_COLUMN));
		$res->closeCursor();
		return $ids;
	}

	/**
	 * @param list<int> $fileIds
	 * @return list<int> those file ids that are already on the shelf
	 */
	public function findExisting(int $shelfId, array $fileIds): array {
		$out = [];
		foreach (array_chunk($fileIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('file_id')->from($this->getTableName())
				->where($qb->expr()->eq('shelf_id', $qb->createNamedParameter($shelfId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$res = $qb->executeQuery();
			foreach ($res->fetchAll(\PDO::FETCH_COLUMN) as $id) {
				$out[] = (int)$id;
			}
			$res->closeCursor();
		}
		return $out;
	}

	public function maxPosition(int $shelfId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('position'))->from($this->getTableName())
			->where($qb->expr()->eq('shelf_id', $qb->createNamedParameter($shelfId, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$max = $res->fetchOne();
		$res->closeCursor();
		return is_numeric($max) ? (int)$max : -1;
	}

	/**
	 * @param list<int> $fileIds
	 * @return int number of removed assignments
	 */
	public function deleteFiles(int $shelfId, array $fileIds): int {
		$removed = 0;
		foreach (array_chunk($fileIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->eq('shelf_id', $qb->createNamedParameter($shelfId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$removed += $qb->executeStatement();
		}
		return $removed;
	}

	public function updatePosition(int $shelfId, int $fileId, int $position): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('position', $qb->createNamedParameter($position, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('shelf_id', $qb->createNamedParameter($shelfId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function deleteByShelf(int $shelfId): void {
		$this->deleteByShelves([$shelfId]);
	}

	/** @param list<int> $shelfIds */
	public function deleteByShelves(array $shelfIds): void {
		foreach (array_chunk($shelfIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('shelf_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}

	/**
	 * Number of (non-deleted) books of the user on each manual shelf.
	 * @return array<int, int> shelf id => count
	 */
	public function countsByShelf(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('sb.shelf_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName(), 'sb')
			->innerJoin('sb', 'ebookreader_shelves', 's', $qb->expr()->eq('s.id', 'sb.shelf_id'))
			->innerJoin('sb', 'ebookreader_books', 'b', $qb->expr()->andX(
				$qb->expr()->eq('b.file_id', 'sb.file_id'),
				$qb->expr()->eq('b.user_id', 's.user_id'),
			))
			->where($qb->expr()->eq('s.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('b.deleted_at'))
			->groupBy('sb.shelf_id');
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[(int)$row['shelf_id']] = (int)$row['cnt'];
		}
		$res->closeCursor();
		return $out;
	}

	/** @return list<int> first file ids of a manual shelf (non-deleted books of the user, shelf order) */
	public function coverFileIds(int $shelfId, string $userId, int $limit = 4): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('sb.file_id')
			->from($this->getTableName(), 'sb')
			->innerJoin('sb', 'ebookreader_books', 'b', $qb->expr()->eq('b.file_id', 'sb.file_id'))
			->where($qb->expr()->eq('sb.shelf_id', $qb->createNamedParameter($shelfId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('b.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('b.deleted_at'))
			->orderBy('sb.position', 'ASC')
			->addOrderBy('sb.id', 'ASC')
			->setMaxResults($limit);
		$res = $qb->executeQuery();
		$ids = array_map('intval', $res->fetchAll(\PDO::FETCH_COLUMN));
		$res->closeCursor();
		return $ids;
	}

	/**
	 * Removes the assignments of the given files from the given shelves (purged tombstones).
	 * @param list<int> $shelfIds
	 * @param list<int> $fileIds
	 */
	public function deleteFilesFromShelves(array $shelfIds, array $fileIds): void {
		if ($shelfIds === [] || $fileIds === []) {
			return;
		}
		foreach (array_chunk($shelfIds, 200) as $shelfChunk) {
			foreach (array_chunk($fileIds, 500) as $fileChunk) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($this->getTableName())
					->where($qb->expr()->in('shelf_id', $qb->createNamedParameter($shelfChunk, IQueryBuilder::PARAM_INT_ARRAY)))
					->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($fileChunk, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
		}
	}
}
