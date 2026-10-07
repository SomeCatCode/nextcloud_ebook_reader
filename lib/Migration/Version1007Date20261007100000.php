<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * ebookreader_books.meta_updated_at: time (ms) of the last change of the descriptive data of a book (metadata, cover, file).
 * Unlike updated_at it does not move for rating, status or progress, so a book shared through the app is only re-indexed
 * for the recipient when the owner changed something the recipient sees. Existing rows start with their updated_at.
 */
class Version1007Date20261007100000 extends SimpleMigrationStep {
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
		if ($table->hasColumn('meta_updated_at')) {
			return null;
		}
		$table->addColumn('meta_updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		return $schema;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('ebookreader_books')
			->set('meta_updated_at', 'updated_at')
			->where($qb->expr()->eq('meta_updated_at', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
