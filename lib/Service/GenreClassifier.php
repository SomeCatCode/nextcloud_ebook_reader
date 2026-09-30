<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

/** Owner: W1 */
class GenreClassifier {
	/**
	 * Splits subjects into known genres and remaining tags.
	 * @param list<string> $subjects
	 * @return array{genres: list<string>, tags: list<string>}
	 */
	public function classify(array $subjects): array {
		throw new \RuntimeException('Not implemented: W1');
	}
}
