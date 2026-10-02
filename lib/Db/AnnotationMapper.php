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
 * @template-extends QBMapper<Annotation>
 */
class AnnotationMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ebookreader_annotations', Annotation::class);
	}

	/** @throws DoesNotExistException */
	public function findByUuid(string $userId, string $uuid): Annotation {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
		return $this->findEntity($qb);
	}

	/** @return list<Annotation> live (not deleted) annotations of a book, oldest first */
	public function findByUserAndFile(string $userId, int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function countLiveByUserAndFile(string $userId, int $fileId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$n = $res->fetchOne();
		$res->closeCursor();
		return is_numeric($n) ? (int)$n : 0;
	}

	/** @return list<Annotation> including tombstones, ordered by (updated_at, id) ascending, after the cursor */
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

	public function deleteByUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->executeStatement();
	}

	/** @param list<int> $fileIds */
	public function deleteByUserAndFiles(string $userId, array $fileIds): void {
		foreach (array_chunk($fileIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}

	/** @return int number of purged tombstones */
	public function deleteTombstonesOlderThan(int $updatedAtMs): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('deleted', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($updatedAtMs, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
