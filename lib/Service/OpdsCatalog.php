<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\Shelf;
use OCA\EbookReader\Db\ShelfMapper;
use OCA\EbookReader\Db\Tag;
use OCA\EbookReader\Db\TagMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Builds the OPDS catalog of one user: navigation feeds (sections, authors, series, ...) and acquisition feeds (books). Books come
 * from LibraryService::findBooks, so filters (author:, series:, genre:, tag:, shelf:) behave exactly like in the library UI and
 * only the user's own indexed books and shelves are ever listed.
 *
 * @psalm-import-type OpdsEntry from OpdsFeedBuilder
 * @psalm-import-type OpdsFeed from OpdsFeedBuilder
 * @psalm-import-type OpdsLink from OpdsFeedBuilder
 */
class OpdsCatalog {
	public const PER_PAGE = 50;
	public const NAV_PER_PAGE = 100;
	public const MAX_PAGE = 100000;
	public const MAX_SEARCH_LENGTH = 200;
	private const SUMMARY_MAX = 2000;
	/** filter types a client may ask for via /opds/books */
	public const FILTER_TYPES = ['genre', 'tag', 'author', 'series', 'shelf', 'format'];
	public const SORTS = ['title', 'author', 'series', 'added', 'read', 'shelf'];

	private const P = 'ebookreader.opds.';

	public function __construct(
		private LibraryService $library,
		private TagMapper $tags,
		private ShelfMapper $shelves,
		private IURLGenerator $urls,
		private IL10N $l10n,
		private ITimeFactory $time,
		private OpdsFeedBuilder $builder = new OpdsFeedBuilder(),
	) {
	}

	private function now(): int {
		return $this->time->now()->getTimestamp();
	}

	private function url(string $route, array $params = []): string {
		return $this->urls->linkToRouteAbsolute(self::P . $route, $params);
	}

	/** Parses "type:name" (one entry, allowed types only). @return array{type: string, name: string}|null */
	public static function parseFilter(string $filter): ?array {
		$entries = BookQuery::parseFilterEntries([$filter]);
		if ($entries === [] || !in_array($entries[0]['type'], self::FILTER_TYPES, true)) {
			return null;
		}
		return $entries[0];
	}

	public function root(): string {
		$now = $this->now();
		$nav = fn (string $id, string $title, string $content, string $route, string $kind, ?string $rel = null): array => [
			'id' => 'urn:ebookreader:nav:' . $id,
			'title' => $title,
			'updated' => $now,
			'content' => $content,
			'links' => [[
				'rel' => $rel ?? OpdsFeedBuilder::REL_SUBSECTION,
				'href' => $this->url($route),
				'type' => OpdsFeedBuilder::contentType($kind),
			]],
		];
		$acq = OpdsFeedBuilder::KIND_ACQUISITION;
		$navKind = OpdsFeedBuilder::KIND_NAVIGATION;
		return $this->builder->build([
			'id' => 'urn:ebookreader:root',
			'title' => $this->l10n->t('E-Book Reader'),
			'updated' => $now,
			'kind' => $navKind,
			'selfUrl' => $this->url('index'),
			'startUrl' => $this->url('index'),
			'searchUrl' => $this->url('opensearch'),
			'entries' => [
				$nav('recent', $this->l10n->t('Recently added'), $this->l10n->t('The newest books in your library'), 'recent', $acq, 'http://opds-spec.org/sort/new'),
				$nav('reading', $this->l10n->t('Continue reading'), $this->l10n->t('Books you started'), 'reading', $acq),
				$nav('all', $this->l10n->t('All books'), $this->l10n->t('Your whole library, sorted by title'), 'all', $acq),
				$nav('authors', $this->l10n->t('Authors'), $this->l10n->t('Browse by author'), 'authors', $navKind),
				$nav('series', $this->l10n->t('Series'), $this->l10n->t('Browse by series'), 'series', $navKind),
				$nav('genres', $this->l10n->t('Genres'), $this->l10n->t('Browse by genre'), 'genres', $navKind),
				$nav('tags', $this->l10n->t('Tags'), $this->l10n->t('Browse by tag'), 'tags', $navKind),
				$nav('shelves', $this->l10n->t('Shelves'), $this->l10n->t('Your shelves, including smart shelves'), 'shelves', $navKind),
			],
		]);
	}

	public function recent(string $userId, int $page): string {
		return $this->books($userId, new BookQuery(sort: 'added', order: 'desc'), $page, 'recent', [], 'recent', $this->l10n->t('Recently added'));
	}

	public function reading(string $userId, int $page): string {
		return $this->books($userId, new BookQuery(status: Book::STATUS_READING, sort: 'read', order: 'desc'), $page, 'reading', [], 'reading', $this->l10n->t('Continue reading'));
	}

	public function all(string $userId, int $page): string {
		return $this->books($userId, new BookQuery(sort: 'title'), $page, 'all', [], 'all', $this->l10n->t('All books'));
	}

	/**
	 * Books of one filter term (author:, series:, ...). Null if the filter or sort is not acceptable.
	 */
	public function filtered(string $userId, string $filter, string $sort, string $order, int $page): ?string {
		$entry = self::parseFilter($filter);
		if ($entry === null) {
			return null;
		}
		if (!in_array($sort, self::SORTS, true)) {
			$sort = $entry['type'] === 'series' ? 'series' : 'title';
		}
		$order = strtolower($order) === 'desc' ? 'desc' : 'asc';
		$query = new BookQuery(sort: $sort, order: $order, include: [$entry]);
		$title = match ($entry['type']) {
			'author' => $this->l10n->t('Author: %s', [$entry['name']]),
			'series' => $this->l10n->t('Series: %s', [$entry['name']]),
			'genre' => $this->l10n->t('Genre: %s', [$entry['name']]),
			'tag' => $this->l10n->t('Tag: %s', [$entry['name']]),
			'shelf' => $this->l10n->t('Shelf: %s', [$this->shelfName($userId, $entry['name'])]),
			default => $this->l10n->t('Format: %s', [$entry['name']]),
		};
		$params = ['filter' => $entry['type'] . ':' . $entry['name']];
		if ($sort !== 'title') {
			$params['sort'] = $sort;
		}
		if ($order === 'desc') {
			$params['order'] = 'desc';
		}
		return $this->books($userId, $query, $page, 'books', $params, 'books:' . $params['filter'], $title);
	}

	public function search(string $userId, string $q, int $page): string {
		$q = trim(mb_substr($q, 0, self::MAX_SEARCH_LENGTH));
		$title = $this->l10n->t('Search: %s', [$q]);
		if ($q === '') {
			return $this->builder->build($this->feed('search', $title, OpdsFeedBuilder::KIND_ACQUISITION, $this->url('search'), []));
		}
		return $this->books($userId, new BookQuery(search: $q, sort: 'title'), $page, 'search', ['q' => $q], 'search:' . $q, $title);
	}

	/** @param 'authors'|'series'|'genres'|'tags'|'shelves' $type */
	public function navigation(string $userId, string $type, int $page): string {
		$items = [];
		$acq = OpdsFeedBuilder::contentType(OpdsFeedBuilder::KIND_ACQUISITION);
		$now = $this->now();
		$entry = function (string $term, string $name, string $content, array $extra = []) use ($acq, $now, $type): array {
			return [
				'id' => 'urn:ebookreader:' . $type . ':' . $term,
				'title' => $name,
				'updated' => $now,
				'content' => $content,
				'links' => [[
					'rel' => OpdsFeedBuilder::REL_SUBSECTION,
					'href' => $this->url('books', ['filter' => $term] + $extra),
					'type' => $acq,
				]],
			];
		};
		switch ($type) {
			case 'shelves':
				$title = $this->l10n->t('Shelves');
				foreach ($this->shelves->findByUser($userId) as $shelf) {
					$items[] = $entry(
						'shelf:' . $shelf->getId(),
						$shelf->getName(),
						$shelf->isSmart() ? $this->l10n->t('Smart shelf') : $this->l10n->t('Shelf'),
						$shelf->isSmart() ? [] : ['sort' => 'shelf'],
					);
				}
				break;
			default:
				$facets = $this->library->getFacets($userId);
				$term = ['authors' => 'author', 'series' => 'series', 'genres' => 'genre', 'tags' => 'tag'][$type] ?? 'author';
				$title = match ($type) {
					'series' => $this->l10n->t('Series'),
					'genres' => $this->l10n->t('Genres'),
					'tags' => $this->l10n->t('Tags'),
					default => $this->l10n->t('Authors'),
				};
				/** @var list<array{name: string, count: int}> $list */
				$list = is_array($facets[$type] ?? null) ? $facets[$type] : [];
				foreach ($list as $row) {
					$extra = $type === 'series' ? ['sort' => 'series'] : [];
					$items[] = $entry($term . ':' . $row['name'], $row['name'], $this->l10n->n('%n book', '%n books', (int)$row['count']), $extra);
				}
		}

		$total = count($items);
		$page = max(1, min(self::MAX_PAGE, $page));
		$slice = array_slice($items, ($page - 1) * self::NAV_PER_PAGE, self::NAV_PER_PAGE);
		$feed = $this->feed($type, $title, OpdsFeedBuilder::KIND_NAVIGATION, $this->url($type, $page > 1 ? ['page' => $page] : []), $slice);
		$feed['links'] = $this->pagingLinks($type, [], $page, $total, self::NAV_PER_PAGE, OpdsFeedBuilder::KIND_NAVIGATION);
		return $this->builder->build($feed);
	}

	public function openSearch(): string {
		return $this->builder->buildOpenSearch(
			$this->l10n->t('E-Book Reader'),
			$this->l10n->t('Search your e-book library'),
			$this->url('search') . '?q={searchTerms}',
		);
	}

	/**
	 * @param array<string, mixed> $params route params of the feed (without page)
	 */
	private function books(string $userId, BookQuery $base, int $page, string $route, array $params, string $feedId, string $title): string {
		$page = max(1, min(self::MAX_PAGE, $page));
		$query = new BookQuery(
			search: $base->search,
			status: $base->status,
			sort: $base->sort,
			order: $base->order,
			limit: self::PER_PAGE,
			offset: ($page - 1) * self::PER_PAGE,
			include: $base->include,
		);
		$result = $this->library->findBooks($userId, $query);
		$books = $result['books'];
		$tags = $books === [] ? [] : $this->tags->findByBooks(array_map(static fn (Book $b): int => $b->getId(), $books));
		$entries = [];
		$updated = 0;
		foreach ($books as $book) {
			$entries[] = $this->bookEntry($book, $tags[$book->getId()] ?? []);
			$updated = max($updated, intdiv($book->getUpdatedAt(), 1000));
		}
		$feed = $this->feed($feedId, $title, OpdsFeedBuilder::KIND_ACQUISITION, $this->url($route, $params + ($page > 1 ? ['page' => $page] : [])), $entries, $updated > 0 ? $updated : null);
		$feed['links'] = $this->pagingLinks($route, $params, $page, $result['total'], self::PER_PAGE, OpdsFeedBuilder::KIND_ACQUISITION);
		$feed['total'] = $result['total'];
		$feed['perPage'] = self::PER_PAGE;
		$feed['startIndex'] = $query->offset + 1;
		return $this->builder->build($feed);
	}

	/**
	 * @param list<OpdsEntry> $entries
	 * @return OpdsFeed
	 */
	private function feed(string $id, string $title, string $kind, string $selfUrl, array $entries, ?int $updated = null): array {
		return [
			'id' => 'urn:ebookreader:feed:' . $id,
			'title' => $title,
			'updated' => $updated ?? $this->now(),
			'kind' => $kind,
			'selfUrl' => $selfUrl,
			'startUrl' => $this->url('index'),
			'upUrl' => $this->url('index'),
			'searchUrl' => $this->url('opensearch'),
			'entries' => $entries,
		];
	}

	/**
	 * first/previous/next/last links of a paged feed.
	 * @param array<string, mixed> $params
	 * @return list<OpdsLink>
	 */
	public function pagingLinks(string $route, array $params, int $page, int $total, int $perPage, string $kind): array {
		$type = OpdsFeedBuilder::contentType($kind);
		$last = max(1, (int)ceil($total / $perPage));
		$link = fn (string $rel, int $p): array => [
			'rel' => $rel,
			'href' => $this->url($route, $params + ($p > 1 ? ['page' => $p] : [])),
			'type' => $type,
		];
		$links = [];
		if ($page > 1) {
			$links[] = $link('first', 1);
			$links[] = $link('previous', min($page - 1, $last));
		}
		if ($page < $last) {
			$links[] = $link('next', $page + 1);
			$links[] = $link('last', $last);
		}
		return $links;
	}

	/**
	 * @param list<Tag> $tags
	 * @return OpdsEntry
	 */
	public function bookEntry(Book $book, array $tags = []): array {
		$fileId = $book->getFileId();
		$links = [[
			'rel' => OpdsFeedBuilder::REL_ACQUISITION,
			'href' => $this->url('download', ['fileId' => $fileId]),
			'type' => OpdsFeedBuilder::mimeFor($book->getFormat()),
			'length' => $book->getSize(),
		]];
		if ($book->getHasCover()) {
			$links[] = ['rel' => OpdsFeedBuilder::REL_IMAGE, 'href' => $this->url('cover', ['fileId' => $fileId, 'size' => 'large']), 'type' => 'image/jpeg'];
			$links[] = ['rel' => OpdsFeedBuilder::REL_THUMBNAIL, 'href' => $this->url('cover', ['fileId' => $fileId, 'size' => 'small']), 'type' => 'image/jpeg'];
		}
		$categories = [];
		foreach ($tags as $tag) {
			$categories[] = $tag->getName();
		}
		$entry = [
			'id' => 'urn:ebookreader:' . $fileId,
			'title' => self::titleOf($book),
			'updated' => max(1, intdiv($book->getUpdatedAt(), 1000)),
			'authors' => $book->getAuthorsArray(),
			'categories' => array_values(array_unique($categories)),
			'links' => $links,
		];
		$language = trim((string)$book->getLanguage());
		if ($language !== '') {
			$entry['language'] = $language;
		}
		$publisher = trim((string)$book->getPublisher());
		if ($publisher !== '') {
			$entry['publisher'] = $publisher;
		}
		if (preg_match('/^\d{4}(-\d{2}(-\d{2})?)?/', trim((string)$book->getPublishedAt()), $m) === 1) {
			$entry['issued'] = $m[0];
		}
		$isbn = preg_replace('/[^0-9Xx]/', '', (string)$book->getIsbn()) ?? '';
		if (strlen($isbn) === 10 || strlen($isbn) === 13) {
			$entry['identifier'] = 'urn:isbn:' . strtoupper($isbn);
		}
		$summary = self::plainText($book->getDescription());
		$series = trim((string)$book->getSeries());
		if ($series !== '') {
			$index = $book->getSeriesIndex();
			$label = $series . ($index !== null ? ' #' . rtrim(rtrim(number_format($index, 2, '.', ''), '0'), '.') : '');
			$summary = $summary === '' ? $label : $label . "\n\n" . $summary;
		}
		if ($summary !== '') {
			$entry['summary'] = $summary;
		}
		return $entry;
	}

	public static function titleOf(Book $book): string {
		$title = trim((string)$book->getTitle());
		return $title !== '' ? $title : pathinfo($book->getPath(), PATHINFO_FILENAME);
	}

	/** Description (may contain HTML) as plain text. */
	public static function plainText(?string $html, int $max = self::SUMMARY_MAX): string {
		if ($html === null || trim($html) === '') {
			return '';
		}
		$text = preg_replace('#<\s*(?:br|/p|/div|/li|/h[1-6])\b[^>]*>#i', "\n", $html) ?? $html;
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
		$text = trim(preg_replace("/\n\\s*\n+/", "\n\n", $text) ?? $text);
		return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
	}

	private function shelfName(string $userId, string $term): string {
		$id = FilterTerms::shelfId($term);
		if ($id === null) {
			return $term;
		}
		try {
			/** @var Shelf $shelf */
			$shelf = $this->shelves->findByUserAndId($userId, $id);
			return $shelf->getName();
		} catch (\Throwable) {
			return $term;
		}
	}
}
