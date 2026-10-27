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
 * Long strip flag on ebookreader_books (comics read as one seamless vertical scroll, e.g. webtoons):
 * - longstrip: effective value
 * - longstrip_file: recognised in the file at the last indexing (ComicInfo.xml Format/Tags)
 * - longstrip_manual: longstrip was set in the app and survives re-indexing
 * Existing rows get false; the value from the file is filled in at the next indexing (occ ebookreader:scan --force).
 */
class Version1009Date20261025100000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('ebookreader_books')) {
			return null;
		}
		$table = $schema->getTable('ebookreader_books');
		$changed = false;
		foreach (['longstrip', 'longstrip_file', 'longstrip_manual'] as $column) {
			if (!$table->hasColumn($column)) {
				$table->addColumn($column, Types::BOOLEAN, ['notnull' => false, 'default' => false]);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}
}
