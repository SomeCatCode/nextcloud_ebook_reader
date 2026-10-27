<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Migration;

use Closure;
use OCA\EbookReader\Metadata\VolumeNumber;
use OCA\EbookReader\Service\SeriesOrder;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Natural volume order on ebookreader_books:
 * - title_sort: sort key of the title with zero-padded digit runs (SQL cannot sort "Vol 2" before "Vol 10" otherwise)
 * - series_index_derived: series_index was parsed from the title or file name, the metadata had none
 * Existing rows get title_sort and, for books of a series without series_index, a parsed series_index right here.
 */
class Version1010Date20261027100000 extends SimpleMigrationStep {
	private const BATCH = 500;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('ebookreader_books')) {
			return null;
		}
		$table = $schema->getTable('ebookreader_books');
		$changed = false;
		if (!$table->hasColumn('title_sort')) {
			$table->addColumn('title_sort', Types::STRING, ['notnull' => false, 'length' => 768]);
			$changed = true;
		}
		if (!$table->hasColumn('series_index_derived')) {
			$table->addColumn('series_index_derived', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$lastId = 0;
		while (true) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'title', 'path', 'series', 'series_index')
				->from('ebookreader_books')
				->where($qb->expr()->gt('id', $qb->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
				->orderBy('id', 'ASC')
				->setMaxResults(self::BATCH);
			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();
			if ($rows === []) {
				return;
			}
			foreach ($rows as $row) {
				$id = (int)$row['id'];
				$lastId = $id;
				$title = is_string($row['title'] ?? null) ? $row['title'] : '';
				$path = is_string($row['path'] ?? null) ? $row['path'] : '';
				$source = $title !== '' ? $title : pathinfo($path, PATHINFO_FILENAME);
				$update = $this->db->getQueryBuilder();
				$update->update('ebookreader_books')
					->set('title_sort', $update->createNamedParameter(SeriesOrder::sortKey($source)))
					->where($update->expr()->eq('id', $update->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
				$series = is_string($row['series'] ?? null) ? trim($row['series']) : '';
				if ($series !== '' && $row['series_index'] === null) {
					$derived = VolumeNumber::fromTitleOrFilename($title, $path);
					if ($derived !== null) {
						$update->set('series_index', $update->createNamedParameter($derived, IQueryBuilder::PARAM_STR))
							->set('series_index_derived', $update->createNamedParameter(true, IQueryBuilder::PARAM_BOOL));
					}
				}
				$update->executeStatement();
			}
		}
	}
}
