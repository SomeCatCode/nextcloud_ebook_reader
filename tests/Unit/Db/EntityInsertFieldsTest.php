<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Db;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\Progress;
use OCA\EbookReader\Db\Tag;
use OCA\EbookReader\Db\Task;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression: Entity::setter() skips values equal to the property default, so they were missing
 * from the INSERT ("Field 'type' doesn't have a default value" on MariaDB for tags and tasks).
 */
class EntityInsertFieldsTest extends TestCase {
	public function testNewTagInsertsTypeAndSourceEvenWhenEqualToDefault(): void {
		$tag = new Tag();
		$tag->setBookId(1);
		$tag->setType(Tag::TYPE_TAG);
		$tag->setName('Futanari');
		$tag->setSource(Tag::SOURCE_FILE);
		$fields = array_keys($tag->getUpdatedFields());
		$this->assertContains('type', $fields);
		$this->assertContains('source', $fields);
		$this->assertNotContains('id', $fields);
	}

	public function testNewTaskInsertsDefaultTypeAndStatus(): void {
		$task = new Task();
		$task->setType(Task::TYPE_EDIT);
		$fields = array_keys($task->getUpdatedFields());
		foreach (['userId', 'fileId', 'type', 'status', 'progress', 'step', 'request', 'createdAt', 'updatedAt'] as $f) {
			$this->assertContains($f, $fields);
		}
	}

	/** @return list<array{class-string}> */
	public static function entities(): array {
		return [[Book::class], [Progress::class], [Tag::class], [Task::class]];
	}

	/** @param class-string $class */
	#[DataProvider('entities')]
	public function testLoadedRowsOnlyUpdateChangedFields(string $class): void {
		$entity = $class::fromRow(['id' => 7]);
		$this->assertSame([], $entity->getUpdatedFields());
	}
}
