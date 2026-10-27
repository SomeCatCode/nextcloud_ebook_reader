<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Tag;
use OCA\EbookReader\Db\TagMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * "Read next" after a book: the next volume of its series and similar unread titles.
 *
 * Similar titles are scored in SQL (one query on the books table with correlated sub-selects on the tag table),
 * so the library is never scanned in PHP. Score: +3 per shared author, +2 per shared genre, +1 per shared tag,
 * +1 same primary language, +1 same format family (comic vs. ebook). Only books with a score above 0 qualify.
 * The same algorithm runs in the Android app (Room).
 */
class SuggestionService {
	public const WEIGHT_AUTHOR = 3;
	public const WEIGHT_GENRE = 2;
	public const WEIGHT_TAG = 1;
	public const WEIGHT_LANGUAGE = 1;
	public const WEIGHT_FORMAT = 1;
	public const COMIC_FORMATS = ['cbz', 'cbr', 'cb7', 'cbt'];

	/** Highest scored rows read from the database before the "one per series" reduction. */
	private const CANDIDATES = 300;
	/** Authors / genres / tags of the source book that count (keeps the SQL short). */
	private const MAX_TERMS = 12;

	private const BOOKS = 'ebookreader_books';
	private const TAGS = 'ebookreader_tags';

	public function __construct(
		private IDBConnection $db,
		private BookMapper $bookMapper,
		private TagMapper $tagMapper,
		private LibraryService $library,
	) {
	}

	/**
	 * @return array{next: ?Book, similar: list<Book>}
	 */
	public function suggest(string $userId, Book $book, int $limit): array {
		$limit = max(1, min(10, $limit));
		$next = $this->library->nextVolume($userId, $book);
		if ($next !== null && $next->getReadStatus() === Book::STATUS_FINISHED) {
			$next = null;
		}
		return ['next' => $next, 'similar' => $this->similar($userId, $book, $limit)];
	}

	/**
	 * @return list<Book>
	 */
	public function similar(string $userId, Book $book, int $limit): array {
		$tags = $this->tagMapper->findByBooks([$book->getId()])[$book->getId()] ?? [];
		$rows = $this->candidates($userId, $book, $tags);
		$picked = self::pick($rows, $book, $limit);
		return $this->firstUnreadVolumes($userId, $picked);
	}

	/**
	 * One query: unread books of the user that score above 0, best first.
	 *
	 * @param list<Tag> $tags
	 * @return list<array{book: Book, score: int}>
	 */
	protected function candidates(string $userId, Book $book, array $tags): array {
		$qb = $this->db->getQueryBuilder();
		$e = $qb->expr();
		$score = self::scoreSql($qb, $this->db, $book, $tags);
		$qb->select('b.*')->selectAlias($qb->createFunction($score), 'suggest_score')->from(self::BOOKS, 'b')
			->where($e->eq('b.user_id', $qb->createNamedParameter($userId)))
			->andWhere($e->isNull('b.deleted_at'))
			->andWhere($e->eq('b.read_status', $qb->createNamedParameter(Book::STATUS_UNREAD)))
			->andWhere($e->neq('b.file_id', $qb->createNamedParameter($book->getFileId(), IQueryBuilder::PARAM_INT)));
		$series = trim((string)$book->getSeries());
		if ($series !== '') {
			$same = $e->iLike('b.series', $qb->createNamedParameter($this->db->escapeLikeParameter($series)));
			$qb->andWhere($e->orX($e->isNull('b.series'), $e->eq('b.series', $qb->createNamedParameter('')), $qb->createFunction('NOT (' . $same . ')')));
		}
		$qb->andWhere($qb->createFunction('(' . $score . ') > 0'))
			->orderBy($qb->createFunction('(' . $score . ')'), 'DESC')
			->addOrderBy('b.added_at', 'DESC')
			->addOrderBy($qb->createFunction('LOWER(COALESCE(b.title, b.path))'), 'ASC')
			->addOrderBy('b.id', 'ASC')
			->setMaxResults(self::CANDIDATES);
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			/** @var array<string, mixed> $row */
			$score = (int)($row['suggest_score'] ?? 0);
			unset($row['suggest_score']);
			$out[] = ['book' => Book::fromRow($row), 'score' => $score];
		}
		$res->closeCursor();
		return $out;
	}

	/**
	 * SQL expression (a number) of the similarity of a row of `b` to the given book.
	 *
	 * @param list<Tag> $tags genres and tags of the source book
	 */
	public static function scoreSql(IQueryBuilder $qb, IDBConnection $db, Book $book, array $tags): string {
		$e = $qb->expr();
		$parts = [];
		foreach (array_slice(array_values(array_unique($book->getAuthorsArray())), 0, self::MAX_TERMS) as $author) {
			if (trim($author) === '') {
				continue;
			}
			// authors are stored as JSON array text: match the quoted, JSON encoded name
			$needle = '%' . $db->escapeLikeParameter(json_encode($author, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) . '%';
			$parts[] = 'CASE WHEN ' . $e->iLike('b.authors', $qb->createNamedParameter($needle)) . ' THEN ' . self::WEIGHT_AUTHOR . ' ELSE 0 END';
		}
		foreach ([[Tag::TYPE_GENRE, self::WEIGHT_GENRE], [Tag::TYPE_TAG, self::WEIGHT_TAG]] as [$type, $weight]) {
			$names = [];
			foreach ($tags as $tag) {
				if ($tag->getType() === $type && trim($tag->getName()) !== '') {
					$names[mb_strtolower($tag->getName())] = true;
				}
			}
			$names = array_map('strval', array_slice(array_keys($names), 0, self::MAX_TERMS));
			if ($names === []) {
				continue;
			}
			$sub = $db->getQueryBuilder();
			$sub->select($sub->createFunction('COUNT(*)'))->from(self::TAGS, 'st')
				->where($sub->expr()->eq('st.book_id', 'b.id'))
				->andWhere($sub->expr()->eq('st.type', $qb->createNamedParameter($type)))
				->andWhere($sub->expr()->in($qb->createFunction('LOWER(st.name)'), $qb->createNamedParameter($names, IQueryBuilder::PARAM_STR_ARRAY)));
			$parts[] = '(' . $sub->getSQL() . ') * ' . $weight;
		}
		$language = self::primaryLanguage($book->getLanguage());
		if ($language !== null) {
			$parts[] = 'CASE WHEN ' . $e->iLike('b.language', $qb->createNamedParameter($db->escapeLikeParameter($language) . '%')) . ' THEN ' . self::WEIGHT_LANGUAGE . ' ELSE 0 END';
		}
		$formats = implode(',', array_map(static fn (string $f): string => (string)$qb->createNamedParameter($f), self::COMIC_FORMATS));
		$comic = self::isComic($book->getFormat());
		$parts[] = 'CASE WHEN LOWER(b.format) ' . ($comic ? '' : 'NOT ') . 'IN (' . $formats . ') THEN ' . self::WEIGHT_FORMAT . ' ELSE 0 END';
		return implode(' + ', $parts);
	}

	/** "en-US" and "en_GB" give "en"; empty gives null. */
	public static function primaryLanguage(?string $language): ?string {
		$l = mb_strtolower(trim((string)$language));
		if ($l === '') {
			return null;
		}
		$primary = preg_split('/[-_]/', $l)[0] ?? '';
		return $primary === '' ? null : $primary;
	}

	public static function isComic(string $format): bool {
		return in_array(strtolower($format), self::COMIC_FORMATS, true);
	}

	/**
	 * Reduces the scored rows (best first) to at most $limit books: never the book itself, nothing unread-less,
	 * nothing of its series, and one book per other series (the best scored row represents the series).
	 *
	 * @param list<array{book: Book, score: int}> $rows
	 * @return list<Book>
	 */
	public static function pick(array $rows, Book $source, int $limit): array {
		$ownSeries = SeriesOrder::key($source->getSeries());
		$seen = [];
		$out = [];
		foreach ($rows as $row) {
			$b = $row['book'];
			if ($row['score'] <= 0 || $b->getFileId() === $source->getFileId() || $b->getReadStatus() !== Book::STATUS_UNREAD) {
				continue;
			}
			$key = SeriesOrder::key($b->getSeries());
			if ($key !== '') {
				if ($key === $ownSeries || isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
			}
			$out[] = $b;
			if (count($out) >= $limit) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Replaces each book of a series by the first unread volume of that series in reading order (one query).
	 *
	 * @param list<Book> $books
	 * @return list<Book>
	 */
	private function firstUnreadVolumes(string $userId, array $books): array {
		$names = [];
		foreach ($books as $b) {
			if (SeriesOrder::key($b->getSeries()) !== '') {
				$names[] = (string)$b->getSeries();
			}
		}
		if ($names === []) {
			return $books;
		}
		$unread = [];
		foreach ($this->bookMapper->findBySeries($userId, $names) as $volume) {
			if ($volume->getReadStatus() === Book::STATUS_UNREAD) {
				$unread[SeriesOrder::key($volume->getSeries())][] = $volume;
			}
		}
		return array_map(static function (Book $b) use ($unread): Book {
			$key = SeriesOrder::key($b->getSeries());
			return $key !== '' && isset($unread[$key]) ? SeriesOrder::sort($unread[$key])[0] : $b;
		}, $books);
	}
}
