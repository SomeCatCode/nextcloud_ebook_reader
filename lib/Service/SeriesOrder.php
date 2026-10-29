<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\Db\Book;

/**
 * Reading order of the volumes of a series and "continue the series" (pure, no database).
 *
 * Order: series index ascending (volumes without an index last), then the title in natural order
 * ("Vol 2" before "Vol 10", case-insensitive; the file name when there is no title), then the file id.
 */
final class SeriesOrder {
	/** Grouping key of a series name (case-insensitive, trimmed); '' = no series. */
	public static function key(?string $series): string {
		return mb_strtolower(trim($series ?? ''));
	}

	/**
	 * Sort key of a title for SQL ORDER BY (column title_sort): lower case, every run of digits zero-padded to 12 places,
	 * so a plain string comparison orders "Vol 2" before "Vol 10". Cut to 600 characters.
	 */
	public static function sortKey(string $text): string {
		$text = mb_strtolower(trim($text));
		$text = (string)preg_replace_callback('/\d+/u', static function (array $m): string {
			$digits = ltrim($m[0], '0');
			return str_pad($digits === '' ? '0' : $digits, 12, '0', STR_PAD_LEFT);
		}, $text);
		return mb_substr($text, 0, 600);
	}

	/**
	 * Digits a volume number gets in file names: the width of the highest number in the series, at least 2
	 * (01 .. 10; 001 .. 120 when the series has 100 or more volumes). Only the integer part counts.
	 *
	 * @param iterable<?float> $indices series indices of all volumes of the series
	 */
	public static function indexWidth(iterable $indices): int {
		$max = 0.0;
		foreach ($indices as $index) {
			if ($index !== null && $index > $max) {
				$max = $index;
			}
		}
		return max(2, strlen((string)(int)floor($max)));
	}

	/**
	 * "5" or "5.5" as text, zero-padded to $width digits before the decimal point ("05", "05.5"); $width 0 = unpadded.
	 */
	public static function formatIndex(float $index, int $width = 0): string {
		$text = rtrim(rtrim(sprintf('%.4F', $index), '0'), '.');
		$parts = explode('.', $text, 2);
		$negative = str_starts_with($parts[0], '-');
		$int = ltrim($parts[0], '-');
		$int = str_pad($int, max(0, min(10, $width)), '0', STR_PAD_LEFT);
		return ($negative ? '-' : '') . $int . (isset($parts[1]) ? '.' . $parts[1] : '');
	}

	/**
	 * @param list<Book> $volumes
	 * @return list<Book>
	 */
	public static function sort(array $volumes): array {
		usort($volumes, static function (Book $a, Book $b): int {
			$ia = $a->getSeriesIndex();
			$ib = $b->getSeriesIndex();
			if ($ia === null || $ib === null) {
				if ($ia !== $ib) {
					return $ia === null ? 1 : -1;
				}
			} elseif ($ia !== $ib) {
				return $ia <=> $ib;
			}
			$cmp = strnatcasecmp(self::title($a), self::title($b));
			return $cmp !== 0 ? $cmp : $a->getFileId() <=> $b->getFileId();
		});
		return $volumes;
	}

	/**
	 * The volume after $current in reading order, or null if it is the last one (or not part of $volumes).
	 * Other copies of the same volume (same series index, e.g. an EPUB and a CBZ of volume 3) are skipped.
	 *
	 * @param list<Book> $volumes all volumes of the series (any order, may include $current)
	 * @param ?callable(Book): bool $accept only volumes passing this are returned; the search goes on behind rejected ones
	 */
	public static function next(array $volumes, Book $current, ?callable $accept = null): ?Book {
		$key = self::key($current->getSeries());
		if ($key === '') {
			return null;
		}
		$sorted = self::sort(array_values(array_filter(
			$volumes,
			static fn (Book $b): bool => self::key($b->getSeries()) === $key && $b->getDeletedAt() === null,
		)));
		$pos = null;
		foreach ($sorted as $i => $b) {
			if ($b->getFileId() === $current->getFileId()) {
				$pos = $i;
				break;
			}
		}
		if ($pos === null) {
			return null;
		}
		$index = $current->getSeriesIndex();
		for ($i = $pos + 1, $n = count($sorted); $i < $n; $i++) {
			$candidate = $sorted[$i];
			if ($index !== null && $candidate->getSeriesIndex() === $index) {
				continue;
			}
			if ($accept === null || $accept($candidate)) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * "Up next" for continue reading: for every series whose most recently read volume is finished, the next volume that
	 * has not been started yet (status unread). Series whose latest volume is still being read are skipped (that volume is
	 * in "continue reading" already), as are series without such a volume.
	 *
	 * @param list<Book> $recent books in the order they were last read (most recent first)
	 * @param array<string, list<Book>> $volumesBySeries series key (see key()) => all volumes
	 * @param array<int, true> $exclude file ids already shown (continue reading)
	 * @return list<array{previous: Book, next: Book}>
	 */
	public static function upNext(array $recent, array $volumesBySeries, array $exclude, int $limit): array {
		$out = [];
		$seen = [];
		foreach (self::latestPerSeries($recent) as $key => $book) {
			if ($book->getReadStatus() !== Book::STATUS_FINISHED) {
				continue;
			}
			$next = self::next($volumesBySeries[$key] ?? [], $book, static fn (Book $b): bool => $b->getReadStatus() !== Book::STATUS_FINISHED);
			if ($next === null || $next->getReadStatus() !== Book::STATUS_UNREAD || isset($exclude[$next->getFileId()]) || isset($seen[$next->getFileId()])) {
				continue;
			}
			$seen[$next->getFileId()] = true;
			$out[] = ['previous' => $book, 'next' => $next];
			if (count($out) >= $limit) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The most recently read volume of each series (in the order of $recent).
	 * @param list<Book> $recent
	 * @return array<string, Book>
	 */
	public static function latestPerSeries(array $recent): array {
		$out = [];
		foreach ($recent as $book) {
			$key = self::key($book->getSeries());
			if ($key !== '' && !isset($out[$key])) {
				$out[$key] = $book;
			}
		}
		return $out;
	}

	private static function title(Book $b): string {
		$title = $b->getTitle();
		if ($title !== null && $title !== '') {
			return $title;
		}
		return pathinfo($b->getPath(), PATHINFO_FILENAME);
	}
}
