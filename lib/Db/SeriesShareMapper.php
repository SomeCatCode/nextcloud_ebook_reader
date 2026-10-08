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
 * @template-extends QBMapper<SeriesShare>
 */
class SeriesShareMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ebookreader_series_shares', SeriesShare::class);
	}

	/** @throws DoesNotExistException */
	public function findById(int $id): SeriesShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @throws DoesNotExistException */
	public function findByOwnerSeriesAndRecipient(string $ownerId, string $seriesKey, string $recipientId): SeriesShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->eq('series_key', $qb->createNamedParameter($seriesKey)))
			->andWhere($qb->expr()->eq('recipient_id', $qb->createNamedParameter($recipientId)));
		return $this->findEntity($qb);
	}

	/** @return list<SeriesShare> all recipients of one series of an owner */
	public function findByOwnerAndSeries(string $ownerId, string $seriesKey): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->eq('series_key', $qb->createNamedParameter($seriesKey)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<SeriesShare> */
	public function findByOwner(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<SeriesShare> */
	public function findByRecipient(string $recipientId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('recipient_id', $qb->createNamedParameter($recipientId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<SeriesShare> the next page of all series shares by id (background sync) */
	public function findPage(int $afterId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Number of recipients per series of an owner.
	 * @return array<string, int> series key (see SeriesShare::keyOf) => count
	 */
	public function countsByOwner(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('series_key')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->groupBy('series_key');
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[(string)$row['series_key']] = (int)$row['cnt'];
		}
		$res->closeCursor();
		return $out;
	}
}
