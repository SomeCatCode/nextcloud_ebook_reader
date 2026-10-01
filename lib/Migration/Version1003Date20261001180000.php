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
 * Adds ebookreader_books.sidecar_etag: change marker of the sidecar metadata file (".<book>.opf") seen at the last
 * indexing, so that a changed sidecar triggers re-indexing even if the book itself is unchanged.
 */
class Version1003Date20261001180000 extends SimpleMigrationStep {
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
		if ($table->hasColumn('sidecar_etag')) {
			return null;
		}
		$table->addColumn('sidecar_etag', Types::STRING, ['notnull' => false, 'length' => 64]);
		return $schema;
	}
}
