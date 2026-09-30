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

class Version1000Date20260930000000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ebookreader_books')) {
			$t = $schema->createTable('ebookreader_books');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('format', Types::STRING, ['notnull' => true, 'length' => 8]);
			$t->addColumn('path', Types::STRING, ['notnull' => true, 'length' => 4000]);
			$t->addColumn('size', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 512]);
			$t->addColumn('authors', Types::TEXT, ['notnull' => false]);
			$t->addColumn('series', Types::STRING, ['notnull' => false, 'length' => 512]);
			$t->addColumn('series_index', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('language', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('publisher', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('isbn', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('published_at', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('rating', Types::SMALLINT, ['notnull' => false]);
			$t->addColumn('read_status', Types::STRING, ['notnull' => true, 'length' => 12, 'default' => 'unread']);
			$t->addColumn('read_status_manual', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('has_cover', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('cover_etag', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('file_mtime', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('file_etag', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
			$t->addColumn('added_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('deleted_at', Types::BIGINT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['user_id', 'file_id'], 'ebr_books_uf');
			$t->addIndex(['user_id', 'updated_at', 'id'], 'ebr_books_sync');
			$t->addIndex(['file_id'], 'ebr_books_file');
		}

		if (!$schema->hasTable('ebookreader_tags')) {
			$t = $schema->createTable('ebookreader_tags');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('book_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 8]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'file']);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['book_id'], 'ebr_tags_book');
			$t->addIndex(['type', 'name'], 'ebr_tags_tn');
		}

		if (!$schema->hasTable('ebookreader_progress')) {
			$t = $schema->createTable('ebookreader_progress');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('locator', Types::TEXT, ['notnull' => true]);
			$t->addColumn('percentage', Types::FLOAT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('device', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('client_updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['user_id', 'file_id'], 'ebr_prog_uf');
			$t->addIndex(['user_id', 'updated_at', 'id'], 'ebr_prog_sync');
		}

		return $schema;
	}
}
