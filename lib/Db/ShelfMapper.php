<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Shelf>
 */
class ShelfMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ebookreader_shelves', Shelf::class);
	}

	/** @throws DoesNotExistException also for shelves of other users */
	public function findByUserAndId(string $userId, int $id): Shelf {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/** @return list<Shelf> sorted by sort_order, then name */
	public function findByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('name', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function countByUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$res = $qb->executeQuery();
		$count = (int)$res->fetchOne();
		$res->closeCursor();
		return $count;
	}

	/** @return list<int> */
	public function findIdsByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$res = $qb->executeQuery();
		$ids = array_map('intval', $res->fetchAll(\PDO::FETCH_COLUMN));
		$res->closeCursor();
		return $ids;
	}

	/** Physically removes all shelves of a user. */
	public function deleteByUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->executeStatement();
	}
}
