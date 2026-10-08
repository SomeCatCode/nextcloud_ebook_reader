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
 * @template-extends QBMapper<FolderShare>
 */
class FolderShareMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ebookreader_folder_shares', FolderShare::class);
	}

	/** @throws DoesNotExistException */
	public function findByTriple(string $ownerId, int $folderId, string $recipientId): FolderShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('recipient_id', $qb->createNamedParameter($recipientId)));
		return $this->findEntity($qb);
	}

	/** @return list<FolderShare> all recipients of one folder */
	public function findByFolder(string $ownerId, int $folderId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<FolderShare> */
	public function findByOwner(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<FolderShare> */
	public function findByRecipient(string $recipientId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('recipient_id', $qb->createNamedParameter($recipientId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<FolderShare> rows pointing at a Nextcloud share */
	public function findByShareId(string $shareId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId)));
		return $this->findEntities($qb);
	}

	/**
	 * Number of recipients per folder of an owner.
	 * @return array<int, int> folder id => count
	 */
	public function countsByOwner(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('folder_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->groupBy('folder_id');
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[(int)$row['folder_id']] = (int)$row['cnt'];
		}
		$res->closeCursor();
		return $out;
	}
}
