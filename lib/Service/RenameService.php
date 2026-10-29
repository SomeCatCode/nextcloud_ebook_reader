<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\Db\Book;

/** Owner: W3 */
class RenameService {
	private const MAX_LENGTH = 150;

	/**
	 * Expands a filename pattern such as "{author} - {title}".
	 * Placeholders: {author} (first author), {authors}, {title}, {series}, {series_index}.
	 * {series_index} is zero-padded to $indexWidth digits (see SeriesOrder::indexWidth, default 2: 01, 02, ... 10),
	 * {series_index:N} to exactly N digits, {series_index:raw} is not padded.
	 * The result has no extension and contains no characters that are invalid in file names.
	 */
	public function buildFilename(Book $book, string $pattern, int $indexWidth = 2): string {
		$authors = $book->getAuthorsArray();
		$index = $book->getSeriesIndex();
		$replace = [
			'{author}' => $authors[0] ?? '',
			'{authors}' => implode(', ', $authors),
			'{title}' => trim((string)$book->getTitle()),
			'{series}' => trim((string)$book->getSeries()),
		];
		$pattern = (string)preg_replace_callback('/\{series_index(?::(raw|\d+))?\}/u', static function (array $m) use ($index, $indexWidth): string {
			if ($index === null) {
				return '';
			}
			$mode = $m[1] ?? '';
			return SeriesOrder::formatIndex($index, $mode === 'raw' ? 0 : ($mode === '' ? $indexWidth : (int)$mode));
		}, $pattern);
		$name = strtr($pattern, $replace);

		// drop separators/brackets left over by empty placeholders
		$name = (string)preg_replace('/\(\s*\)|\[\s*\]|\{\s*\}/u', '', $name);
		$name = (string)preg_replace('/\s+/u', ' ', $name);
		$name = (string)preg_replace('/(\s*[-\x{2013}\x{2014}_]\s+){2,}/u', ' - ', $name);
		$name = trim($name, " \t-\u{2013}\u{2014}_");
		$name = $this->sanitize($name);
		if ($name === '') {
			$name = $this->sanitize(trim((string)$book->getTitle()));
		}
		if ($name === '') {
			$name = $this->sanitize(pathinfo($book->getPath(), PATHINFO_FILENAME));
		}
		return $name === '' ? 'Book' : $name;
	}

	/** Replaces characters that are invalid in file names and shortens overlong names. */
	public function sanitize(string $name): string {
		$name = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
		$name = (string)preg_replace('/[\/\\\\:*?"<>|]/u', '_', $name);
		$name = (string)preg_replace('/\s+/u', ' ', $name);
		$name = trim($name, " .\t");
		if (mb_strlen($name) > self::MAX_LENGTH) {
			$name = rtrim(mb_substr($name, 0, self::MAX_LENGTH), ' .');
		}
		return $name;
	}
}
