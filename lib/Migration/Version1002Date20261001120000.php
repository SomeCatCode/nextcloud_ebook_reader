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
 * Creates ebookreader_tasks: long running edits and conversions that run after the HTTP response
 * (or in a background job) and report their progress.
 */
class Version1002Date20261001120000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('ebookreader_tasks')) {
			return null;
		}
		$t = $schema->createTable('ebookreader_tasks');
		$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$t->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
		$t->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16]);
		$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 12, 'default' => 'queued']);
		$t->addColumn('progress', Types::FLOAT, ['notnull' => true, 'default' => 0]);
		$t->addColumn('step', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
		$t->addColumn('request', Types::TEXT, ['notnull' => true]);
		$t->addColumn('result', Types::TEXT, ['notnull' => false]);
		$t->addColumn('error', Types::STRING, ['notnull' => false, 'length' => 1000]);
		$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		$t->setPrimaryKey(['id']);
		$t->addIndex(['user_id', 'status'], 'ebr_tasks_user');
		return $schema;
	}
}
