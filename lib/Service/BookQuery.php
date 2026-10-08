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
	public const SORTS = ['title', 'author', 'series', 'rating', 'added', 'read', 'shelf'];
	public const STATUSES = ['unread', 'reading', 'finished'];
	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT = 200;
	public const FILTER_TYPES = ['genre', 'tag', 'author', 'series', 'format', 'shelf', 'missing', 'completion', 'age'];
	/** names a `completion:<name>` term can use (unknown = not set) */
	public const COMPLETION_TERMS = ['ongoing', 'completed', 'unknown'];
	/** fields a `missing:<field>` term can ask for */
	public const MISSING_FIELDS = ['genre', 'tag', 'author', 'series', 'description', 'cover', 'language'];
	public const MATCH_ALL = 'all';
	public const MATCH_ANY = 'any';
	public const MAX_FILTER_ENTRIES = 50;
	/**
	 * Values of the OCS `format` query parameter (response format). They share the name with the book-format filter
	 * of GET /books and /series, so `format=json` must not filter for books "in the format json" (clients such as the
	 * Android app up to 0.2.0 add it to every OCS request, which made every book list come back empty).
	 */
	public const OCS_RESPONSE_FORMATS = ['json', 'xml'];

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
		/** @var list<array{type: string, name: string}> */
		public readonly array $include = [],
		/** @var list<array{type: string, name: string}> */
		public readonly array $exclude = [],
		public readonly string $match = self::MATCH_ALL,
		/** true: only books with a series, false: only books without one, null: no restriction */
		public readonly ?bool $inSeries = null,
		/** true: leave out finished books (ignored when $status is set) */
		public readonly bool $hideFinished = false,
		/** user-relative folder path with a leading slash ("/Books/Saga"): only books whose parent folder is this one */
		public readonly ?string $folder = null,
		/** true: also the books of all subfolders of $folder */
		public readonly bool $folderRecursive = false,
	) {
	}

	/**
	 * All include entries: the explicit ones plus the legacy single params (genre, tag, author, series, format).
	 * @return list<array{type: string, name: string}>
	 */
	public function effectiveIncludes(): array {
		$out = $this->include;
		foreach (['format' => $this->format, 'genre' => $this->genre, 'tag' => $this->tag, 'author' => $this->author, 'series' => $this->series] as $type => $name) {
			if ($name !== null) {
				$out[] = ['type' => $type, 'name' => $name];
			}
		}
		return $out;
	}

	/**
	 * Parses "type:name" filter entries (array, or one comma-separated string). Malformed entries are ignored.
	 * @return list<array{type: string, name: string}>
	 */
	public static function parseFilterEntries(mixed $raw): array {
		if (is_string($raw)) {
			// split only at commas followed by a "type:" prefix so names containing commas survive
			$raw = preg_split('/,(?=\s*(?:' . implode('|', self::FILTER_TYPES) . '):)/iu', $raw) ?: [];
		}
		if (!is_array($raw)) {
			return [];
		}
		$out = [];
		$seen = [];
		foreach ($raw as $entry) {
			if (!is_string($entry)) {
				continue;
			}
			$pos = strpos($entry, ':');
			if ($pos === false) {
				continue;
			}
			$type = strtolower(trim(substr($entry, 0, $pos)));
			$name = trim(substr($entry, $pos + 1));
			if ($name === '' || !in_array($type, self::FILTER_TYPES, true)) {
				continue;
			}
			if ($type === 'missing') {
				$name = strtolower($name);
				if (!in_array($name, self::MISSING_FIELDS, true)) {
					continue;
				}
			} elseif ($type === 'completion') {
				$name = strtolower($name);
				if (!in_array($name, self::COMPLETION_TERMS, true)) {
					continue;
				}
			} elseif ($type === 'age') {
				$name = FilterTerms::normalizeAge($name);
				if ($name === null) {
					continue;
				}
			}
			$key = $type . '|' . mb_strtolower($name);
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$out[] = ['type' => $type, 'name' => $name];
			if (count($out) >= self::MAX_FILTER_ENTRIES) {
				break;
			}
		}
		return $out;
	}

	/** "1"/true => true, "0"/false => false, anything else => null */
	public static function parseInSeries(mixed $raw): ?bool {
		if ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true') {
			return true;
		}
		if ($raw === false || $raw === 0 || $raw === '0' || $raw === 'false') {
			return false;
		}
		return null;
	}

	/**
	 * Normalises a folder path of a request: leading slash, no trailing slash, no empty or "." segments. Null for an
	 * empty value or a path with "..".
	 */
	public static function normaliseFolder(mixed $raw): ?string {
		if (!is_string($raw) || trim($raw) === '') {
			return null;
		}
		$segments = [];
		foreach (explode('/', str_replace('\\', '/', trim($raw))) as $segment) {
			$segment = trim($segment);
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..' || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
				return null;
			}
			$segments[] = $segment;
		}
		return '/' . implode('/', $segments);
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

		$match = strtolower($str($params['match'] ?? null) ?? self::MATCH_ALL);
		if ($match !== self::MATCH_ANY) {
			$match = self::MATCH_ALL;
		}

		return new self(
			search: $str($params['search'] ?? null),
			format: in_array(strtolower($str($params['format'] ?? null) ?? ''), self::OCS_RESPONSE_FORMATS, true) ? null : $str($params['format'] ?? null),
			genre: $str($params['genre'] ?? null),
			tag: $str($params['tag'] ?? null),
			author: $str($params['author'] ?? null),
			series: $str($params['series'] ?? null),
			status: $status,
			sort: $sort,
			order: $order,
			limit: $limit,
			offset: $offset,
			include: self::parseFilterEntries($params['include'] ?? null),
			exclude: self::parseFilterEntries($params['exclude'] ?? null),
			match: $match,
			inSeries: self::parseInSeries($params['inSeries'] ?? null),
			hideFinished: in_array($params['hideFinished'] ?? null, [1, '1', true, 'true'], true),
			folder: self::normaliseFolder($params['folder'] ?? null),
			folderRecursive: in_array($params['folderRecursive'] ?? null, [1, '1', true, 'true'], true),
		);
	}
}
