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
 * Adds ebookreader_books.overrides: JSON list of metadata fields that were edited in the app only and must
 * survive re-indexing of the file.
 */
class Version1001Date20261001000000 extends SimpleMigrationStep {
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
		if ($table->hasColumn('overrides')) {
			return null;
		}
		$table->addColumn('overrides', Types::TEXT, ['notnull' => false]);
		return $schema;
	}
}
