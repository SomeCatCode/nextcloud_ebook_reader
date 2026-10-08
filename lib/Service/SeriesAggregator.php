<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

/**
 * Pure aggregation of book rows into series (GET /series).
 */
final class SeriesAggregator {
	public const MAX_SERIES = 2000;
	public const MAX_COVERS = 3;

	/**
	 * @param list<array<string, mixed>> $rows book rows with file_id, series, series_index, added_at, read_status, title, path
	 * @param 'name'|'added' $sort name (natural, case-insensitive) or lastAddedAt
	 * @return list<array{name: string, count: int, readCount: int, coverFileIds: list<int>, firstFileId: int, lastAddedAt: int, shared: bool}> shared = the series contains books of other users (shared_owner set)
	 */
	public static function aggregate(array $rows, string $sort = 'name', string $order = 'asc', int $limit = self::MAX_SERIES): array {
		/** @var array<string, array{name: string, books: list<array{id: int, index: ?float, title: string}>, read: int, last: int, shared: bool}> $groups */
		$groups = [];
		foreach ($rows as $row) {
			$name = trim(is_scalar($row['series'] ?? null) ? (string)$row['series'] : '');
			if ($name === '') {
				continue;
			}
			$key = mb_strtolower($name);
			if (!isset($groups[$key])) {
				$groups[$key] = ['name' => $name, 'books' => [], 'read' => 0, 'last' => 0, 'shared' => false];
			}
			if (isset($row['shared_owner']) && $row['shared_owner'] !== '') {
				$groups[$key]['shared'] = true;
			}
			$index = $row['series_index'] ?? null;
			$title = is_scalar($row['title'] ?? null) && (string)$row['title'] !== '' ? (string)$row['title'] : (is_scalar($row['path'] ?? null) ? (string)$row['path'] : '');
			$groups[$key]['books'][] = [
				'id' => is_numeric($row['file_id'] ?? null) ? (int)$row['file_id'] : 0,
				'index' => is_numeric($index) ? (float)$index : null,
				'title' => mb_strtolower($title),
			];
			if (($row['read_status'] ?? null) === 'finished') {
				$groups[$key]['read']++;
			}
			$added = is_numeric($row['added_at'] ?? null) ? (int)$row['added_at'] : 0;
			$groups[$key]['last'] = max($groups[$key]['last'], $added);
		}

		$out = [];
		foreach ($groups as $g) {
			$books = $g['books'];
			usort($books, static function (array $a, array $b): int {
				if ($a['index'] === null || $b['index'] === null) {
					if ($a['index'] !== $b['index']) {
						return $a['index'] === null ? 1 : -1;
					}
				} elseif ($a['index'] !== $b['index']) {
					return $a['index'] <=> $b['index'];
				}
				return [$a['title'], $a['id']] <=> [$b['title'], $b['id']];
			});
			$ids = array_map(static fn (array $b): int => $b['id'], $books);
			$out[] = [
				'name' => $g['name'],
				'count' => count($books),
				'readCount' => $g['read'],
				'coverFileIds' => array_slice($ids, 0, self::MAX_COVERS),
				'firstFileId' => $ids[0],
				'lastAddedAt' => $g['last'],
				'shared' => $g['shared'],
			];
		}

		$dir = strtolower($order) === 'desc' ? -1 : 1;
		usort($out, static function (array $a, array $b) use ($sort, $dir): int {
			$cmp = $sort === 'added'
				? $a['lastAddedAt'] <=> $b['lastAddedAt']
				: strnatcasecmp($a['name'], $b['name']);
			if ($cmp === 0) {
				$cmp = strnatcasecmp($a['name'], $b['name']);
			}
			return $cmp * $dir;
		});
		return array_slice($out, 0, max(0, $limit));
	}
}
