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
 * Book flags on ebookreader_books (all nullable, existing rows keep NULL = unknown):
 * - completion: "ongoing" | "completed" | NULL, set in the app only
 * - age_rating: effective age rating (0, 6, 12, 16, 18 or NULL)
 * - age_rating_file: the value read from the file at the last indexing (ComicInfo.xml AgeRating, EPUB meta)
 * - age_rating_manual: age_rating was set in the app and survives re-indexing
 */
class Version1006Date20261005150000 extends SimpleMigrationStep {
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
		if (!$table->hasColumn('completion')) {
			$table->addColumn('completion', Types::STRING, ['notnull' => false, 'length' => 12]);
			$changed = true;
		}
		if (!$table->hasColumn('age_rating')) {
			$table->addColumn('age_rating', Types::SMALLINT, ['notnull' => false]);
			$changed = true;
		}
		if (!$table->hasColumn('age_rating_file')) {
			$table->addColumn('age_rating_file', Types::SMALLINT, ['notnull' => false]);
			$changed = true;
		}
		if (!$table->hasColumn('age_rating_manual')) {
			$table->addColumn('age_rating_manual', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
