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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Sharing: ebookreader_shelf_shares (a shelf shared live with another user) and ebookreader_file_shares
 * (the Nextcloud file shares the app manages for direct book shares and shared shelves).
 */
class Version1006Date20261005140000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('ebookreader_shelf_shares')) {
			$t = $schema->createTable('ebookreader_shelf_shares');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('shelf_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('owner_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('recipient_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('synced_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['shelf_id', 'recipient_id'], 'ebr_shsh_uniq');
			$t->addIndex(['recipient_id'], 'ebr_shsh_recip');
			$t->addIndex(['owner_id'], 'ebr_shsh_owner');
			$changed = true;
		}

		if (!$schema->hasTable('ebookreader_file_shares')) {
			$t = $schema->createTable('ebookreader_file_shares');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('owner_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('recipient_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			// 0 = shared directly as a book, otherwise the id of the shelf share that needs the file
			$t->addColumn('shelf_share_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			// full id of the Nextcloud share created by the app ("ocinternal:42"); null = not created by the app (never deleted by it)
			$t->addColumn('share_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['owner_id', 'recipient_id', 'file_id', 'shelf_share_id'], 'ebr_fsh_uniq');
			$t->addIndex(['recipient_id', 'file_id'], 'ebr_fsh_recip');
			$t->addIndex(['shelf_share_id'], 'ebr_fsh_shelf');
			$t->addIndex(['share_id'], 'ebr_fsh_share');
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
