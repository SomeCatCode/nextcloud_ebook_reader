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
 * Shelves: ebookreader_shelves (manual and smart shelves per user) and ebookreader_shelf_books (assignments of manual shelves).
 */
class Version1004Date20261002090000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('ebookreader_shelves')) {
			$t = $schema->createTable('ebookreader_shelves');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'manual']);
			$t->addColumn('query', Types::TEXT, ['notnull' => false]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['user_id'], 'ebr_shelves_user');
			$changed = true;
		}

		if (!$schema->hasTable('ebookreader_shelf_books')) {
			$t = $schema->createTable('ebookreader_shelf_books');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('shelf_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('added_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['shelf_id', 'file_id'], 'ebr_shelfb_sf');
			$t->addIndex(['file_id'], 'ebr_shelfb_file');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
