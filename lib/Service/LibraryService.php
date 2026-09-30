<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\Db\Book;
use OCP\Files\File;
use OCP\Files\Node;

/** Owner: W1 */
class LibraryService {
	public function indexFile(string $userId, File $file, bool $force = false): ?Book {
		throw new \RuntimeException('Not implemented: W1');
	}

	/** Marks the row as deleted (tombstone). */
	public function removeFile(string $userId, int $fileId): void {
		throw new \RuntimeException('Not implemented: W1');
	}

	/** Re-indexes a file for all users having it (after editing). */
	public function reindexFileForAllUsers(int $fileId): void {
		throw new \RuntimeException('Not implemented: W1');
	}

	public function isInLibrary(string $userId, Node $node): bool {
		throw new \RuntimeException('Not implemented: W1');
	}

	/** Queues ScanFileJobs, returns the number of queued jobs. */
	public function scanUser(string $userId): int {
		throw new \RuntimeException('Not implemented: W1');
	}

	/** @return array{books: list<Book>, total: int} */
	public function findBooks(string $userId, BookQuery $q): array {
		throw new \RuntimeException('Not implemented: W1');
	}

	/** @throws \OCP\AppFramework\Db\DoesNotExistException */
	public function getBook(string $userId, int $fileId): Book {
		throw new \RuntimeException('Not implemented: W1');
	}

	/** @return array<string, mixed> */
	public function getFacets(string $userId): array {
		throw new \RuntimeException('Not implemented: W1');
	}

	/** @return list<\OCA\EbookReader\Db\Tag> */
	public function getTags(int $bookId): array {
		throw new \RuntimeException('Not implemented: W1');
	}

	/** @param list<string> $names */
	public function setTags(int $bookId, string $type, array $names, string $source): void {
		throw new \RuntimeException('Not implemented: W1');
	}

	/** @throws \OCP\Files\NotFoundException */
	public function getFileForUser(string $userId, int $fileId): File {
		throw new \RuntimeException('Not implemented: W1');
	}
}
