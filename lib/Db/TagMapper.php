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
 * @template-extends QBMapper<Tag>
 */
class TagMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ebookreader_tags', Tag::class);
	}

	/** @return list<Tag> */
	public function findByBook(int $bookId, ?string $type = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('book_id', $qb->createNamedParameter($bookId, IQueryBuilder::PARAM_INT)));
		if ($type !== null) {
			$qb->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)));
		}
		$qb->orderBy('name', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @param list<int> $bookIds
	 * @return array<int, list<Tag>> keyed by book id
	 */
	public function findByBooks(array $bookIds): array {
		$out = [];
		foreach (array_chunk($bookIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($this->getTableName())
				->where($qb->expr()->in('book_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('name', 'ASC');
			foreach ($this->findEntities($qb) as $tag) {
				$out[$tag->getBookId()][] = $tag;
			}
		}
		return $out;
	}

	/** Deletes tags of a book, optionally restricted to type and/or source. */
	public function deleteByBook(int $bookId, ?string $type = null, ?string $source = null): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('book_id', $qb->createNamedParameter($bookId, IQueryBuilder::PARAM_INT)));
		if ($type !== null) {
			$qb->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)));
		}
		if ($source !== null) {
			$qb->andWhere($qb->expr()->eq('source', $qb->createNamedParameter($source)));
		}
		$qb->executeStatement();
	}

	/** @param list<int> $bookIds */
	public function deleteByBooks(array $bookIds): void {
		foreach (array_chunk($bookIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('book_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}

	/**
	 * Distinct names with counts for the books of a user (non-deleted).
	 * @return list<array{name: string, count: int}>
	 */
	public function countByNameForUser(string $userId, string $type): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.name')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName(), 't')
			->innerJoin('t', 'ebookreader_books', 'b', $qb->expr()->eq('b.id', 't.book_id'))
			->where($qb->expr()->eq('b.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('b.deleted_at'))
			->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter($type)))
			->groupBy('t.name')
			->orderBy('t.name', 'ASC');
		$res = $qb->executeQuery();
		$rows = [];
		while ($row = $res->fetch()) {
			$rows[] = ['name' => (string)$row['name'], 'count' => (int)$row['cnt']];
		}
		$res->closeCursor();
		return $rows;
	}
}
