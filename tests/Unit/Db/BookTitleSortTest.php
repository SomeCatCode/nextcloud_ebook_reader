<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Db;

use OCA\EbookReader\Db\Book;
use PHPUnit\Framework\TestCase;

class BookTitleSortTest extends TestCase {
	public function testTitleSortOrdersNumbersNaturally(): void {
		$keys = [];
		foreach (['Saga 10', 'Saga 2', 'Saga 1', 'saga 11'] as $title) {
			$b = new Book();
			$b->setTitle($title);
			$b->setPath('/Books/x.epub');
			$b->refreshTitleSort();
			$keys[$title] = (string)$b->getTitleSort();
		}
		asort($keys, SORT_STRING);
		$this->assertSame(['Saga 1', 'Saga 2', 'Saga 10', 'saga 11'], array_keys($keys));
	}

	public function testFileNameWithoutExtensionWhenThereIsNoTitle(): void {
		$b = new Book();
		$b->setPath('/Books/Saga 9.cbz');
		$b->refreshTitleSort();
		$this->assertSame('saga 000000000009', $b->getTitleSort());
	}
}
