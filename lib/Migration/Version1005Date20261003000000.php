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
 * Highlights, notes and bookmarks: ebookreader_annotations (soft deleted with a tombstone so that clients can sync deletions).
 */
class Version1005Date20261003000000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('ebookreader_annotations')) {
			return null;
		}
		$t = $schema->createTable('ebookreader_annotations');
		$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$t->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
		$t->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'highlight']);
		$t->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$t->addColumn('locator', Types::TEXT, ['notnull' => true]);
		$t->addColumn('text', Types::TEXT, ['notnull' => false]);
		$t->addColumn('note', Types::TEXT, ['notnull' => false]);
		$t->addColumn('color', Types::STRING, ['notnull' => false, 'length' => 16]);
		$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		$t->addColumn('client_updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		$t->addColumn('deleted', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		$t->setPrimaryKey(['id']);
		$t->addUniqueIndex(['user_id', 'uuid'], 'ebr_annot_uuid');
		$t->addIndex(['user_id', 'file_id'], 'ebr_annot_ufile');
		$t->addIndex(['user_id', 'updated_at'], 'ebr_annot_uupd');
		return $schema;
	}
}
