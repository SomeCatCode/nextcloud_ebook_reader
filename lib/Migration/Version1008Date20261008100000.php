<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Series and folder sharing:
 *  - ebookreader_series_shares: a series shared live with a user (the owner's books of that series name),
 *  - ebookreader_folder_shares: a folder shared with a user as one Nextcloud share,
 *  - ebookreader_books.shared_owner: owner of the file when it reached the user through a share of another user
 *    (null = own file), so "shared with me" is a plain SQL filter. Existing rows are filled from the app's file shares
 *    here and from the Nextcloud mount at the next scan/indexing for everything else.
 */
class Version1008Date20261008100000 extends SimpleMigrationStep {
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
		$changed = false;

		if (!$schema->hasTable('ebookreader_series_shares')) {
			$t = $schema->createTable('ebookreader_series_shares');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('owner_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('recipient_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('series', Types::STRING, ['notnull' => true, 'length' => 512]);
			// md5 of the lower-cased series name: uniqueness without a huge index
			$t->addColumn('series_key', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('synced_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['owner_id', 'recipient_id', 'series_key'], 'ebr_sesh_uniq');
			$t->addIndex(['recipient_id'], 'ebr_sesh_recip');
			$changed = true;
		}

		if (!$schema->hasTable('ebookreader_folder_shares')) {
			$t = $schema->createTable('ebookreader_folder_shares');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('owner_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('recipient_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			// file id of the shared folder (survives moves and renames); path = last known path, relative to the owner's home
			$t->addColumn('folder_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('path', Types::TEXT, ['notnull' => false]);
			// full id of the Nextcloud share created by the app ("ocinternal:42"); null = existing share of the user (never deleted by the app)
			$t->addColumn('share_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['owner_id', 'recipient_id', 'folder_id'], 'ebr_fosh_uniq');
			$t->addIndex(['recipient_id'], 'ebr_fosh_recip');
			$t->addIndex(['share_id'], 'ebr_fosh_share');
			$changed = true;
		}

		if ($schema->hasTable('ebookreader_books')) {
			$books = $schema->getTable('ebookreader_books');
			if (!$books->hasColumn('shared_owner')) {
				$books->addColumn('shared_owner', Types::STRING, ['notnull' => false, 'length' => 64]);
				$books->addIndex(['user_id', 'shared_owner'], 'ebr_books_shown');
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// books the app shared with a user are theirs only as a share: mark them (the rest follows at the next scan)
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct(['recipient_id', 'file_id', 'owner_id'])->from('ebookreader_file_shares');
		$res = $qb->executeQuery();
		$rows = $res->fetchAll();
		$res->closeCursor();
		foreach ($rows as $row) {
			$up = $this->db->getQueryBuilder();
			$up->update('ebookreader_books')
				->set('shared_owner', $up->createNamedParameter((string)$row['owner_id']))
				->where($up->expr()->eq('user_id', $up->createNamedParameter((string)$row['recipient_id'])))
				->andWhere($up->expr()->eq('file_id', $up->createNamedParameter((int)$row['file_id'], IQueryBuilder::PARAM_INT)))
				->andWhere($up->expr()->isNull('shared_owner'))
				->executeStatement();
		}
	}
}
