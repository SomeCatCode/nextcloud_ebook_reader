<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

/**
 * Filter/sort/paging parameters of a library query.
 */
final class BookQuery {
	public const SORTS = ['title', 'author', 'series', 'rating', 'added', 'read'];
	public const STATUSES = ['unread', 'reading', 'finished'];
	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT = 200;

	public function __construct(
		public readonly ?string $search = null,
		public readonly ?string $format = null,
		public readonly ?string $genre = null,
		public readonly ?string $tag = null,
		public readonly ?string $author = null,
		public readonly ?string $series = null,
		public readonly ?string $status = null,
		public readonly string $sort = 'title',
		public readonly string $order = 'asc',
		public readonly int $limit = self::DEFAULT_LIMIT,
		public readonly int $offset = 0,
	) {
	}

	/**
	 * Builds a sanitised query from (untrusted) request parameters.
	 * @param array<string, mixed> $params
	 */
	public static function fromRequestParams(array $params): self {
		$str = static function (mixed $v): ?string {
			if (!is_scalar($v)) {
				return null;
			}
			$v = trim((string)$v);
			return $v === '' ? null : $v;
		};
		$sort = $str($params['sort'] ?? null) ?? 'title';
		if (!in_array($sort, self::SORTS, true)) {
			$sort = 'title';
		}
		$order = strtolower($str($params['order'] ?? null) ?? 'asc');
		if ($order !== 'asc' && $order !== 'desc') {
			$order = 'asc';
		}
		$status = $str($params['status'] ?? null);
		if ($status !== null && !in_array($status, self::STATUSES, true)) {
			$status = null;
		}
		$limit = isset($params['limit']) && is_numeric($params['limit']) ? (int)$params['limit'] : self::DEFAULT_LIMIT;
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$offset = isset($params['offset']) && is_numeric($params['offset']) ? max(0, (int)$params['offset']) : 0;

		return new self(
			search: $str($params['search'] ?? null),
			format: $str($params['format'] ?? null),
			genre: $str($params['genre'] ?? null),
			tag: $str($params['tag'] ?? null),
			author: $str($params['author'] ?? null),
			series: $str($params['series'] ?? null),
			status: $status,
			sort: $sort,
			order: $order,
			limit: $limit,
			offset: $offset,
		);
	}
}
