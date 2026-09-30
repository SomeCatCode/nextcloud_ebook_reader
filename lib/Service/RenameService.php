<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

/** Owner: W3 */
class RenameService {
	/** Expands a filename pattern such as "{author} - {title}". */
	public function buildFilename(\OCA\EbookReader\Db\Book $book, string $pattern): string {
		throw new \RuntimeException('Not implemented: W3');
	}
}
