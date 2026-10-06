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
 * Files shared by the app (directly or through a shared shelf), see FileShare.
 *
 * @template-extends QBMapper<FileShare>
 */
class FileShareMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ebookreader_file_shares', FileShare::class);
	}

	/** @return list<FileShare> all reasons for sharing one file from an owner with a recipient */
	public function findByTriple(string $ownerId, string $recipientId, int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->eq('recipient_id', $qb->createNamedParameter($recipientId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/** @return list<FileShare> */
	public function findByShelfShare(int $shelfShareId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('shelf_share_id', $qb->createNamedParameter($shelfShareId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/** @return list<FileShare> rows pointing at a Nextcloud share */
	public function findByShareId(string $shareId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId)));
		return $this->findEntities($qb);
	}

	/** @return list<FileShare> direct book shares of an owner */
	public function findDirectByOwner(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->eq('shelf_share_id', $qb->createNamedParameter(FileShare::DIRECT, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<FileShare> direct book shares received by a user */
	public function findDirectByRecipient(string $recipientId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('recipient_id', $qb->createNamedParameter($recipientId)))
			->andWhere($qb->expr()->eq('shelf_share_id', $qb->createNamedParameter(FileShare::DIRECT, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<FileShare> every row where the user is owner or recipient */
	public function findByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->eq('owner_id', $qb->createNamedParameter($userId)),
				$qb->expr()->eq('recipient_id', $qb->createNamedParameter($userId)),
			));
		return $this->findEntities($qb);
	}

	/** @return list<int> distinct file ids the app shared with a user (directly or through shelves) */
	public function findIncomingFileIds(string $recipientId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('file_id')->from($this->getTableName())
			->where($qb->expr()->eq('recipient_id', $qb->createNamedParameter($recipientId)));
		$res = $qb->executeQuery();
		$ids = array_map('intval', $res->fetchAll(\PDO::FETCH_COLUMN));
		$res->closeCursor();
		return $ids;
	}

	public function isIncoming(string $recipientId, int $fileId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('recipient_id', $qb->createNamedParameter($recipientId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$res = $qb->executeQuery();
		$found = $res->fetchOne() !== false;
		$res->closeCursor();
		return $found;
	}

	/** @return list<string> owners that shared the file with the recipient through the app */
	public function findOwnersOf(string $recipientId, int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('owner_id')->from($this->getTableName())
			->where($qb->expr()->eq('recipient_id', $qb->createNamedParameter($recipientId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$ids = array_map('strval', $res->fetchAll(\PDO::FETCH_COLUMN));
		$res->closeCursor();
		return $ids;
	}

	/**
	 * Number of shared files per shelf share.
	 * @param list<int> $shelfShareIds
	 * @return array<int, int>
	 */
	public function countsByShelfShares(array $shelfShareIds): array {
		$out = [];
		foreach (array_chunk($shelfShareIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('shelf_share_id')
				->selectAlias($qb->func()->count('*'), 'cnt')
				->from($this->getTableName())
				->where($qb->expr()->in('shelf_share_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->groupBy('shelf_share_id');
			$res = $qb->executeQuery();
			while ($row = $res->fetch()) {
				$out[(int)$row['shelf_share_id']] = (int)$row['cnt'];
			}
			$res->closeCursor();
		}
		return $out;
	}

	/** Detaches the rows from a Nextcloud share that no longer exists: they stay as "handled" and the app never re-creates or deletes it. */
	public function detachShare(string $shareId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('share_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId)))
			->executeStatement();
	}

	public function deleteByShelfShare(int $shelfShareId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('shelf_share_id', $qb->createNamedParameter($shelfShareId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
