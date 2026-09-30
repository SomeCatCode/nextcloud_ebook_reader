<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

/** Owner: W3 */
class EditorService {
	/** @return array<string, mixed> Structure */
	public function getStructure(string $userId, int $fileId): array {
		throw new \RuntimeException('Not implemented: W3');
	}

	/**
	 * @param array<string, mixed> $request EditRequest as array
	 * @return array{book: \OCA\EbookReader\Db\Book, warnings: list<string>}
	 */
	public function save(string $userId, int $fileId, array $request): array {
		throw new \RuntimeException('Not implemented: W3');
	}

	/**
	 * @param array<string, mixed> $metadataPatch
	 * @return array{book: \OCA\EbookReader\Db\Book, warnings: list<string>}
	 */
	public function saveMetadataOnly(string $userId, int $fileId, array $metadataPatch): array {
		throw new \RuntimeException('Not implemented: W3');
	}

	public function rename(string $userId, int $fileId, ?string $name, bool $usePattern): \OCA\EbookReader\Db\Book {
		throw new \RuntimeException('Not implemented: W3');
	}
}
