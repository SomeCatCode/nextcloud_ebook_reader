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
 * @template-extends QBMapper<Task>
 */
class TaskMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ebookreader_tasks', Task::class);
	}

	/** @throws DoesNotExistException */
	public function findById(int $id): Task {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @throws DoesNotExistException also for tasks of other users */
	public function findByUserAndId(string $userId, int $id): Task {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/** @return list<Task> queued and running tasks of the user, oldest first */
	public function findActiveByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter([Task::STATUS_QUEUED, Task::STATUS_RUNNING], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'ASC')
			->setMaxResults(50);
		return $this->findEntities($qb);
	}

	/**
	 * Atomic queued -> running (UPDATE ... WHERE status = 'queued'). Exactly one caller gets true,
	 * however many processes try at once.
	 */
	public function claim(int $id, int $nowMs): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(Task::STATUS_RUNNING))
			->set('updated_at', $qb->createNamedParameter($nowMs, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Task::STATUS_QUEUED)));
		return $qb->executeStatement() === 1;
	}

	public function updateProgress(int $id, float $progress, string $step, int $nowMs): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('progress', $qb->createNamedParameter(sprintf('%.4F', max(0.0, min(1.0, $progress))), IQueryBuilder::PARAM_STR))
			->set('step', $qb->createNamedParameter(mb_substr($step, 0, 255)))
			->set('updated_at', $qb->createNamedParameter($nowMs, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Task::STATUS_RUNNING)))
			->executeStatement();
	}

	/** Only changes the step text while the task is still queued (e.g. "Waiting for background job"). */
	public function updateQueuedStep(int $id, string $step, int $nowMs): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('step', $qb->createNamedParameter(mb_substr($step, 0, 255)))
			->set('updated_at', $qb->createNamedParameter($nowMs, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Task::STATUS_QUEUED)))
			->executeStatement();
	}

	/** Final state; the (possibly large) request is dropped. */
	public function finish(int $id, string $status, ?string $resultJson, ?string $error, int $nowMs): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter($status))
			->set('result', $qb->createNamedParameter($resultJson))
			->set('error', $qb->createNamedParameter($error !== null ? mb_substr($error, 0, 1000) : null))
			->set('request', $qb->createNamedParameter('{}'))
			->set('updated_at', $qb->createNamedParameter($nowMs, IQueryBuilder::PARAM_INT));
		if ($status === Task::STATUS_DONE) {
			$qb->set('progress', $qb->createNamedParameter('1', IQueryBuilder::PARAM_STR))
				->set('step', $qb->createNamedParameter(''));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** Finished tasks older than the cutoff are deleted. */
	public function deleteFinishedOlderThan(int $cutoffMs): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter([Task::STATUS_DONE, Task::STATUS_FAILED], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($cutoffMs, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	/** Running tasks that have not reported anything since the cutoff (the process died) are marked failed. */
	public function failStale(int $cutoffMs, int $nowMs): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(Task::STATUS_FAILED))
			->set('error', $qb->createNamedParameter('The task was interrupted.'))
			->set('result', $qb->createNamedParameter('{"code":500}'))
			->set('request', $qb->createNamedParameter('{}'))
			->set('updated_at', $qb->createNamedParameter($nowMs, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Task::STATUS_RUNNING)))
			->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($cutoffMs, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	public function deleteByUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->executeStatement();
	}
}
