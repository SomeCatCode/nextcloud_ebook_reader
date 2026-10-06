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
 * @template-extends QBMapper<ShelfShare>
 */
class ShelfShareMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ebookreader_shelf_shares', ShelfShare::class);
	}

	/** @throws DoesNotExistException */
	public function findById(int $id): ShelfShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @throws DoesNotExistException */
	public function findByShelfAndRecipient(int $shelfId, string $recipientId): ShelfShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('shelf_id', $qb->createNamedParameter($shelfId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('recipient_id', $qb->createNamedParameter($recipientId)));
		return $this->findEntity($qb);
	}

	/** @return list<ShelfShare> */
	public function findByShelf(int $shelfId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('shelf_id', $qb->createNamedParameter($shelfId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<ShelfShare> */
	public function findByOwner(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<ShelfShare> */
	public function findByRecipient(string $recipientId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('recipient_id', $qb->createNamedParameter($recipientId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<ShelfShare> the next page of all shelf shares by id (background sync) */
	public function findPage(int $afterId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Number of recipients per shelf of an owner.
	 * @return array<int, int> shelf id => count
	 */
	public function countsByOwner(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('shelf_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->groupBy('shelf_id');
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[(int)$row['shelf_id']] = (int)$row['cnt'];
		}
		$res->closeCursor();
		return $out;
	}
}
