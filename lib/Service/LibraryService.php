<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\BackgroundJob\ScanFileJob;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Tag;
use OCA\EbookReader\Db\TagMapper;
use OCA\EbookReader\Metadata\MetadataService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/** Owner: W1 */
class LibraryService {
	private const BOOKS = 'ebookreader_books';
	private const TAGS = 'ebookreader_tags';
	private const PROGRESS = 'ebookreader_progress';

	public function __construct(
		private BookMapper $bookMapper,
		private TagMapper $tagMapper,
		private MetadataService $metadata,
		private CoverService $covers,
		private GenreClassifier $classifier,
		private SettingsService $settings,
		private IRootFolder $rootFolder,
		private IJobList $jobList,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	private static function nowMs(): int {
		return (int)(microtime(true) * 1000.0);
	}

	public function indexFile(string $userId, File $file, bool $force = false): ?Book {
		$format = $this->metadata->detectFormat($file->getName(), $file->getMimeType());
		if ($format === null) {
			return null;
		}
		$fileId = $file->getId();
		$mtime = $file->getMTime();
		$etag = (string)$file->getEtag();
		$path = $this->relativePath($userId, $file);

		try {
			$existing = $this->bookMapper->findByUserAndFile($userId, $fileId, true);
		} catch (DoesNotExistException) {
			$existing = null;
		}

		if ($existing !== null && !$force && $existing->getDeletedAt() === null
			&& $existing->getFileMtime() === $mtime && $existing->getFileEtag() === $etag) {
			if ($existing->getPath() !== $path || $existing->getFormat() !== $format) {
				$existing->setPath($path);
				$existing->setFormat($format);
				$existing->setUpdatedAt(self::nowMs());
				$this->bookMapper->update($existing);
			}
			return $existing;
		}

		$meta = $this->metadata->extract($file, $format);
		$now = self::nowMs();

		$book = $existing ?? new Book();
		$isNew = $existing === null;
		if ($isNew) {
			$book->setUserId($userId);
			$book->setFileId($fileId);
			$book->setAddedAt($now);
		}
		$book->setFormat($format);
		$book->setPath($path);
		$book->setSize((int)$file->getSize());
		$book->setTitle($meta->title);
		$book->setAuthorsArray($meta->authors);
		$book->setSeries($meta->series);
		$book->setSeriesIndex($meta->seriesIndex);
		$book->setDescription($meta->description);
		$book->setLanguage($meta->language !== null ? mb_substr($meta->language, 0, 32) : null);
		$book->setPublisher($meta->publisher !== null ? mb_substr($meta->publisher, 0, 255) : null);
		$book->setIsbn($meta->isbn !== null ? mb_substr($meta->isbn, 0, 32) : null);
		$book->setPublishedAt($meta->publishedAt !== null ? substr($meta->publishedAt, 0, 10) : null);
		$book->setFileMtime($mtime);
		$book->setFileEtag($etag);
		$book->setDeletedAt(null);
		$book->setUpdatedAt($now);
		if ($book->getTitle() !== null) {
			$book->setTitle(mb_substr($book->getTitle(), 0, 512));
		}
		if ($book->getSeries() !== null) {
			$book->setSeries(mb_substr($book->getSeries(), 0, 512));
		}

		// cover
		if ($meta->coverData !== null) {
			try {
				$coverEtag = $this->covers->storeCover($fileId, $meta->coverData);
				$book->setHasCover(true);
				$book->setCoverEtag($coverEtag);
			} catch (\Throwable $e) {
				$this->logger->info('Cover could not be stored for file ' . $fileId . ': ' . $e->getMessage(), ['app' => 'ebookreader']);
				$meta = $meta->with(['coverData' => null]);
			}
		}
		if ($meta->coverData === null && $format !== 'cbr' && $book->getHasCover()) {
			$this->covers->deleteCover($fileId);
			$book->setHasCover(false);
			$book->setCoverEtag(null);
		}

		if ($isNew) {
			try {
				$book = $this->bookMapper->insert($book);
			} catch (DbException $e) {
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				// concurrent indexing (listener + job): take the row that won and update it
				$other = $this->bookMapper->findByUserAndFile($userId, $fileId, true);
				$other->setFormat($format);
				$other->setPath($path);
				$other->setSize($book->getSize());
				$other->setTitle($book->getTitle());
				$other->setAuthors($book->getAuthors());
				$other->setSeries($book->getSeries());
				$other->setSeriesIndex($book->getSeriesIndex());
				$other->setDescription($book->getDescription());
				$other->setLanguage($book->getLanguage());
				$other->setPublisher($book->getPublisher());
				$other->setIsbn($book->getIsbn());
				$other->setPublishedAt($book->getPublishedAt());
				$other->setHasCover($book->getHasCover());
				$other->setCoverEtag($book->getCoverEtag());
				$other->setFileMtime($mtime);
				$other->setFileEtag($etag);
				$other->setDeletedAt(null);
				$other->setUpdatedAt($now);
				$book = $this->bookMapper->update($other);
			}
		} else {
			$book = $this->bookMapper->update($book);
		}

		$this->replaceFileTags($userId, $book, $meta->genres, $meta->tags, $meta->subjects);
		return $book;
	}

	/**
	 * Replaces the source=file tags of a book, keeping source=app tags.
	 * @param list<string> $genres
	 * @param list<string> $tags
	 * @param list<string> $subjects
	 */
	private function replaceFileTags(string $userId, Book $book, array $genres, array $tags, array $subjects): void {
		$classified = $this->classifier->classify($subjects, $userId);
		$genreNames = $this->uniqueNames(array_merge($genres, $classified['genres']));
		$tagNames = $this->uniqueNames(array_merge($tags, $classified['tags']));
		// a genre wins over a tag with the same name
		$genreKeys = array_map('mb_strtolower', $genreNames);
		$tagNames = array_values(array_filter($tagNames, static fn (string $t): bool => !in_array(mb_strtolower($t), $genreKeys, true)));

		$this->tagMapper->deleteByBook($book->getId(), null, Tag::SOURCE_FILE);
		$app = $this->tagMapper->findByBook($book->getId());
		$appKeys = [];
		foreach ($app as $t) {
			$appKeys[$t->getType() . '|' . mb_strtolower($t->getName())] = true;
		}
		foreach ([Tag::TYPE_GENRE => $genreNames, Tag::TYPE_TAG => $tagNames] as $type => $names) {
			foreach ($names as $name) {
				if (isset($appKeys[$type . '|' . mb_strtolower($name)])) {
					continue;
				}
				$this->insertTag($book->getId(), $type, $name, Tag::SOURCE_FILE);
			}
		}
	}

	/**
	 * @param list<string> $names
	 * @return list<string>
	 */
	private function uniqueNames(array $names): array {
		$out = [];
		$seen = [];
		foreach ($names as $n) {
			$n = GenreClassifier::normalise($n);
			if ($n === null || isset($seen[mb_strtolower($n)])) {
				continue;
			}
			$seen[mb_strtolower($n)] = true;
			$out[] = $n;
		}
		return $out;
	}

	private function insertTag(int $bookId, string $type, string $name, string $source): void {
		$tag = new Tag();
		$tag->setBookId($bookId);
		$tag->setType($type);
		$tag->setName($name);
		$tag->setSource($source);
		$this->tagMapper->insert($tag);
	}

	private function relativePath(string $userId, Node $node): string {
		try {
			$rel = $this->rootFolder->getUserFolder($userId)->getRelativePath($node->getPath());
		} catch (\Throwable) {
			$rel = null;
		}
		return $rel !== null ? '/' . trim((string)$rel, '/') : '/' . $node->getName();
	}

	/** Marks the row as deleted (tombstone). */
	public function removeFile(string $userId, int $fileId): void {
		try {
			$book = $this->bookMapper->findByUserAndFile($userId, $fileId);
		} catch (DoesNotExistException) {
			return;
		}
		$now = self::nowMs();
		$book->setDeletedAt($now);
		$book->setUpdatedAt($now);
		$this->bookMapper->update($book);
		$this->tagMapper->deleteByBook($book->getId());
		if ($this->bookMapper->countActiveByFileId($fileId) === 0) {
			$this->covers->deleteCover($fileId);
		}
	}

	/** Tombstones the rows of all users for a file id (file deleted). */
	public function removeFileForAllUsers(int $fileId): void {
		foreach ($this->bookMapper->findByFileId($fileId) as $book) {
			$this->removeFile($book->getUserId(), $fileId);
		}
	}

	/** Re-indexes a file for all users having it (after editing). */
	public function reindexFileForAllUsers(int $fileId): void {
		foreach ($this->bookMapper->findByFileId($fileId) as $book) {
			$userId = $book->getUserId();
			try {
				$file = $this->getFileForUser($userId, $fileId);
				$this->indexFile($userId, $file, true);
			} catch (NotFoundException) {
				$this->removeFile($userId, $fileId);
			} catch (\Throwable $e) {
				$this->logger->warning('Reindex failed for user ' . $userId . ', file ' . $fileId . ': ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
			}
		}
	}

	public function isInLibrary(string $userId, Node $node): bool {
		try {
			$rel = $this->rootFolder->getUserFolder($userId)->getRelativePath($node->getPath());
		} catch (\Throwable) {
			return false;
		}
		return $rel !== null && $this->isPathInLibrary($userId, (string)$rel);
	}

	/** Whether a user-relative path lies below one of the user's library folders. */
	public function isPathInLibrary(string $userId, string $path): bool {
		$path = '/' . trim($path, '/');
		foreach ($this->settings->get($userId)['libraryFolders'] as $folder) {
			$folder = '/' . trim($folder, '/');
			if ($folder === '/' || $path === $folder || str_starts_with($path, $folder . '/')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Updates the paths of books below a moved/renamed folder, or tombstones them when the new location
	 * is outside the library ($newPrefix null = folder deleted).
	 */
	public function moveFolder(string $userId, string $oldPrefix, ?string $newPrefix): void {
		$oldPrefix = '/' . trim($oldPrefix, '/');
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id', 'path')->from(self::BOOKS)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->like('path', $qb->createNamedParameter($this->db->escapeLikeParameter($oldPrefix . '/') . '%')));
		$res = $qb->executeQuery();
		$rows = $res->fetchAll();
		$res->closeCursor();
		foreach ($rows as $row) {
			$fileId = (int)$row['file_id'];
			$old = (string)$row['path'];
			$new = $newPrefix === null ? null : '/' . trim($newPrefix, '/') . substr($old, strlen($oldPrefix));
			if ($new === null || !$this->isPathInLibrary($userId, $new)) {
				$this->removeFile($userId, $fileId);
				continue;
			}
			$book = $this->bookMapper->findByUserAndFile($userId, $fileId);
			$book->setPath($new);
			$book->setUpdatedAt(self::nowMs());
			$this->bookMapper->update($book);
		}
	}

	/**
	 * Walks the library folders of a user and calls $onFile(File, format) for each e-book.
	 * @param callable(File, string): void $onFile
	 * @return bool true if all folders could be listed (safe to tombstone missing books)
	 */
	public function walkLibrary(string $userId, callable $onFile): bool {
		$complete = true;
		$seen = [];
		$userFolder = $this->rootFolder->getUserFolder($userId);
		foreach ($this->settings->get($userId)['libraryFolders'] as $path) {
			try {
				$root = $path === '/' ? $userFolder : $userFolder->get(ltrim($path, '/'));
			} catch (NotFoundException) {
				continue;
			} catch (\Throwable $e) {
				$this->logger->warning('Library folder ' . $path . ' not accessible: ' . $e->getMessage(), ['app' => 'ebookreader']);
				$complete = false;
				continue;
			}
			if ($root instanceof Folder && !$this->walkFolder($root, $onFile, $seen)) {
				$complete = false;
			}
		}
		return $complete;
	}

	/**
	 * @param callable(File, string): void $onFile
	 * @param array<int, true> $seen
	 */
	private function walkFolder(Folder $root, callable $onFile, array &$seen): bool {
		$complete = true;
		$stack = [$root];
		while ($stack !== []) {
			$folder = array_pop($stack);
			try {
				$children = $folder->getDirectoryListing();
			} catch (\Throwable $e) {
				$this->logger->warning('Cannot list folder: ' . $e->getMessage(), ['app' => 'ebookreader']);
				$complete = false;
				continue;
			}
			foreach ($children as $child) {
				if ($child instanceof Folder) {
					$stack[] = $child;
				} elseif ($child instanceof File) {
					$id = $child->getId();
					if (isset($seen[$id])) {
						continue;
					}
					$format = $this->metadata->detectFormat($child->getName(), $child->getMimeType());
					if ($format !== null) {
						$seen[$id] = true;
						$onFile($child, $format);
					}
				}
			}
		}
		return $complete;
	}

	/**
	 * Queues ScanFileJobs (or indexes inline), returns the number of files that needed (re)indexing.
	 * Books whose file vanished are tombstoned.
	 * @param ?callable(File, bool): void $progress called for every e-book found (bool = needs indexing)
	 */
	public function scanUser(string $userId, bool $inline = false, ?callable $progress = null): int {
		$stats = $this->scan($userId, $inline ? null : 0.0, $progress);
		return $stats['indexed'] + $stats['queued'];
	}

	/**
	 * Scan triggered from the UI: indexes inline until the time budget is used up and queues the rest,
	 * so a click shows results right away even when background jobs run rarely (AJAX cron).
	 * @return array{found: int, indexed: int, queued: int}
	 */
	public function scanUserInteractive(string $userId, float $budgetSeconds = 20.0): array {
		return $this->scan($userId, $budgetSeconds);
	}

	/**
	 * @param ?float $inlineSeconds seconds to index inline before queueing (null = all inline, 0 = queue all)
	 * @param ?callable(File, bool): void $progress
	 * @return array{found: int, indexed: int, queued: int}
	 */
	private function scan(string $userId, ?float $inlineSeconds, ?callable $progress = null): array {
		$existing = [];
		foreach ($this->bookMapper->findAllByUser($userId) as $b) {
			$existing[$b->getFileId()] = $b;
		}
		$deadline = $inlineSeconds === null ? INF : microtime(true) + $inlineSeconds;
		$found = [];
		$stats = ['found' => 0, 'indexed' => 0, 'queued' => 0];
		$complete = $this->walkLibrary($userId, function (File $file, string $format) use ($userId, $existing, &$found, &$stats, $deadline, $progress): void {
			$id = $file->getId();
			$found[$id] = true;
			$stats['found']++;
			$b = $existing[$id] ?? null;
			$stale = $b === null || $b->getDeletedAt() !== null || $b->getFileMtime() !== $file->getMTime()
				|| $b->getFileEtag() !== (string)$file->getEtag() || $b->getFormat() !== $format;
			if ($progress !== null) {
				$progress($file, $stale);
			}
			if (!$stale) {
				return;
			}
			if (microtime(true) < $deadline) {
				try {
					$this->indexFile($userId, $file);
					$stats['indexed']++;
					return;
				} catch (\Throwable $e) {
					$this->logger->warning('Indexing failed for file ' . $id . ': ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
					return;
				}
			}
			$this->jobList->add(ScanFileJob::class, ['userId' => $userId, 'fileId' => $id]);
			$stats['queued']++;
		});
		if ($complete) {
			foreach ($existing as $fileId => $b) {
				if (!isset($found[$fileId]) && $b->getDeletedAt() === null) {
					$this->removeFile($userId, $fileId);
				}
			}
		}
		return $stats;
	}

	/** Queues an indexing job for every e-book below a folder that lies in the library. */
	public function queueFolder(string $userId, Folder $folder): int {
		$count = 0;
		$seen = [];
		$this->walkFolder($folder, function (File $file) use ($userId, &$count): void {
			if ($this->isInLibrary($userId, $file)) {
				$this->jobList->add(ScanFileJob::class, ['userId' => $userId, 'fileId' => $file->getId()]);
				$count++;
			}
		}, $seen);
		return $count;
	}

	/** @return array{books: list<Book>, total: int} */
	public function findBooks(string $userId, BookQuery $q): array {
		$countQb = $this->db->getQueryBuilder();
		$countQb->select($countQb->func()->count('*', 'cnt'))->from(self::BOOKS, 'b');
		$this->applyFilters($countQb, $userId, $q);
		$res = $countQb->executeQuery();
		$total = (int)$res->fetchOne();
		$res->closeCursor();

		$qb = $this->db->getQueryBuilder();
		$qb->select('b.*')->from(self::BOOKS, 'b');
		$this->applyFilters($qb, $userId, $q);
		$this->applySort($qb, $q);
		$qb->setFirstResult(max(0, $q->offset))->setMaxResults(max(1, min(BookQuery::MAX_LIMIT, $q->limit)));
		$res = $qb->executeQuery();
		$books = [];
		while ($row = $res->fetch()) {
			/** @var array<string, mixed> $row */
			$books[] = Book::fromRow($row);
		}
		$res->closeCursor();
		return ['books' => $books, 'total' => $total];
	}

	private function applyFilters(IQueryBuilder $qb, string $userId, BookQuery $q): void {
		$e = $qb->expr();
		$qb->where($e->eq('b.user_id', $qb->createNamedParameter($userId)))
			->andWhere($e->isNull('b.deleted_at'));
		if ($q->format !== null) {
			$qb->andWhere($e->eq('b.format', $qb->createNamedParameter($q->format)));
		}
		if ($q->status !== null) {
			$qb->andWhere($e->eq('b.read_status', $qb->createNamedParameter($q->status)));
		}
		if ($q->genre !== null) {
			$qb->andWhere($e->in('b.id', $this->tagSubquery($qb, Tag::TYPE_GENRE, $this->db->escapeLikeParameter($q->genre))));
		}
		if ($q->tag !== null) {
			$qb->andWhere($e->in('b.id', $this->tagSubquery($qb, Tag::TYPE_TAG, $this->db->escapeLikeParameter($q->tag))));
		}
		if ($q->author !== null) {
			$qb->andWhere($e->iLike('b.authors', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($q->author) . '%')));
		}
		if ($q->series !== null) {
			$qb->andWhere($e->iLike('b.series', $qb->createNamedParameter($this->db->escapeLikeParameter($q->series))));
		}
		if ($q->search !== null) {
			$terms = array_slice(preg_split('/\s+/u', trim($q->search)) ?: [], 0, 8);
			foreach ($terms as $term) {
				if ($term === '') {
					continue;
				}
				$like = '%' . $this->db->escapeLikeParameter($term) . '%';
				$p = $qb->createNamedParameter($like);
				$qb->andWhere($e->orX(
					$e->iLike('b.title', $p),
					$e->iLike('b.authors', $p),
					$e->iLike('b.series', $p),
					$e->iLike('b.description', $p),
					$e->in('b.id', $this->tagSubquery($qb, null, $like)),
				));
			}
		}
	}

	/** Sub-select of book ids having a tag matching the (already escaped) LIKE pattern, case-insensitively. */
	private function tagSubquery(IQueryBuilder $qb, ?string $type, string $pattern): \OCP\DB\QueryBuilder\IQueryFunction {
		$sub = $this->db->getQueryBuilder();
		$sub->select('t.book_id')->from(self::TAGS, 't')
			->where($sub->expr()->iLike('t.name', $qb->createNamedParameter($pattern)));
		if ($type !== null) {
			$sub->andWhere($sub->expr()->eq('t.type', $qb->createNamedParameter($type)));
		}
		return $qb->createFunction('(' . $sub->getSQL() . ')');
	}

	private function applySort(IQueryBuilder $qb, BookQuery $q): void {
		$dir = strtolower($q->order) === 'desc' ? 'DESC' : 'ASC';
		$title = 'LOWER(COALESCE(b.title, b.path))';
		switch ($q->sort) {
			case 'author':
				$qb->orderBy($qb->createFunction("LOWER(COALESCE(b.authors, ''))"), $dir);
				$qb->addOrderBy($qb->createFunction($title), 'ASC');
				break;
			case 'series':
				$qb->orderBy($qb->createFunction('CASE WHEN b.series IS NULL THEN 1 ELSE 0 END'), 'ASC');
				$qb->addOrderBy($qb->createFunction("LOWER(COALESCE(b.series, ''))"), $dir);
				$qb->addOrderBy('b.series_index', $dir);
				$qb->addOrderBy($qb->createFunction($title), 'ASC');
				break;
			case 'rating':
				$qb->orderBy($qb->createFunction('COALESCE(b.rating, 0)'), $dir);
				$qb->addOrderBy($qb->createFunction($title), 'ASC');
				break;
			case 'added':
				$qb->orderBy('b.added_at', $dir);
				break;
			case 'read':
				$qb->leftJoin('b', self::PROGRESS, 'p', $qb->expr()->andX(
					$qb->expr()->eq('p.user_id', 'b.user_id'),
					$qb->expr()->eq('p.file_id', 'b.file_id'),
				));
				$qb->orderBy($qb->createFunction('COALESCE(p.updated_at, 0)'), $dir);
				$qb->addOrderBy($qb->createFunction($title), 'ASC');
				break;
			case 'title':
			default:
				$qb->orderBy($qb->createFunction($title), $dir);
		}
		$qb->addOrderBy('b.id', 'ASC');
	}

	/** @throws DoesNotExistException */
	public function getBook(string $userId, int $fileId): Book {
		return $this->bookMapper->findByUserAndFile($userId, $fileId);
	}

	/** @return array<string, mixed> */
	public function getFacets(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('authors', 'series', 'format')->from(self::BOOKS)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		$res = $qb->executeQuery();
		$authors = [];
		$series = [];
		$formats = [];
		while ($row = $res->fetch()) {
			$raw = $row['authors'] ?? null;
			$list = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
			foreach (is_array($list) ? $list : [] as $a) {
				$a = (string)$a;
				$authors[$a] = ($authors[$a] ?? 0) + 1;
			}
			if (is_string($row['series'] ?? null) && $row['series'] !== '') {
				$series[$row['series']] = ($series[$row['series']] ?? 0) + 1;
			}
			$f = (string)$row['format'];
			$formats[$f] = ($formats[$f] ?? 0) + 1;
		}
		$res->closeCursor();

		$toList = static function (array $map): array {
			uksort($map, static fn ($a, $b): int => strcasecmp((string)$a, (string)$b));
			$out = [];
			foreach ($map as $name => $count) {
				$out[] = ['name' => (string)$name, 'count' => (int)$count];
			}
			return $out;
		};
		return [
			'genres' => $this->tagMapper->countByNameForUser($userId, Tag::TYPE_GENRE),
			'tags' => $this->tagMapper->countByNameForUser($userId, Tag::TYPE_TAG),
			'authors' => $toList($authors),
			'series' => $toList($series),
			'formats' => $toList($formats),
		];
	}

	/** @return list<Tag> */
	public function getTags(int $bookId): array {
		return $this->tagMapper->findByBook($bookId);
	}

	/**
	 * Replaces the tags of the given type and source of a book.
	 * @param list<string> $names
	 */
	public function setTags(int $bookId, string $type, array $names, string $source): void {
		if ($type !== Tag::TYPE_GENRE && $type !== Tag::TYPE_TAG) {
			throw new \InvalidArgumentException('Invalid tag type');
		}
		if ($source !== Tag::SOURCE_FILE && $source !== Tag::SOURCE_APP) {
			throw new \InvalidArgumentException('Invalid tag source');
		}
		$names = $this->uniqueNames($names);
		$this->tagMapper->deleteByBook($bookId, $type, $source);
		$other = [];
		foreach ($this->tagMapper->findByBook($bookId, $type) as $t) {
			$other[mb_strtolower($t->getName())] = true;
		}
		foreach ($names as $name) {
			if (!isset($other[mb_strtolower($name)])) {
				$this->insertTag($bookId, $type, $name, $source);
			}
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::BOOKS)
			->set('updated_at', $qb->createNamedParameter(self::nowMs(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($bookId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @throws NotFoundException */
	public function getFileForUser(string $userId, int $fileId): File {
		$node = $this->getNodeForUser($userId, $fileId);
		if (!$node instanceof File || !$node->isReadable()) {
			throw new NotFoundException('File not found');
		}
		return $node;
	}

	/** @throws NotFoundException */
	public function getNodeForUser(string $userId, int $fileId): Node {
		$node = $this->rootFolder->getUserFolder($userId)->getFirstNodeById($fileId);
		if ($node === null) {
			throw new NotFoundException('Node not found');
		}
		return $node;
	}
}
