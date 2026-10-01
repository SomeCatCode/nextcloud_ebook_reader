<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\BackgroundJob\ScanFileJob;
use OCA\EbookReader\BackgroundJob\WriteMetadataJob;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Shelf;
use OCA\EbookReader\Db\ShelfMapper;
use OCA\EbookReader\Db\Tag;
use OCA\EbookReader\Db\TagMapper;
use OCA\EbookReader\Metadata\BookMetadata;
use OCA\EbookReader\Metadata\MetadataService;
use OCA\EbookReader\Metadata\SidecarData;
use OCA\EbookReader\Metadata\SidecarService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\Storage\ISharedStorage;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/** Owner: W1 */
class LibraryService {
	private const BOOKS = 'ebookreader_books';
	private const TAGS = 'ebookreader_tags';
	private const PROGRESS = 'ebookreader_progress';
	private const SHELF_BOOKS = 'ebookreader_shelf_books';
	/** Interactive scans index inline only files up to this size; larger ones are queued as jobs */
	public const INTERACTIVE_MAX_BYTES = 50 * 1024 * 1024;

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
		private SidecarService $sidecar,
		private ?ShelfMapper $shelves = null,
	) {
	}

	private static function nowMs(): int {
		return (int)(microtime(true) * 1000.0);
	}

	public function indexFile(string $userId, File $file, bool $force = false): ?Book {
		if (SidecarService::isSidecarName($file->getName())) {
			return null;
		}
		$format = $this->metadata->detectFormat($file->getName(), $file->getMimeType());
		if ($format === null) {
			return null;
		}
		$fileId = $file->getId();
		$mtime = $file->getMTime();
		$etag = (string)$file->getEtag();
		$path = $this->relativePath($userId, $file);
		$sidecarEtag = $this->sidecar->etagOf($file);

		try {
			$existing = $this->bookMapper->findByUserAndFile($userId, $fileId, true);
		} catch (DoesNotExistException) {
			$existing = null;
		}

		if ($existing !== null && !$force && $existing->getDeletedAt() === null
			&& $existing->getFileMtime() === $mtime && $existing->getFileEtag() === $etag && $existing->getSidecarEtag() === $sidecarEtag) {
			if ($existing->getPath() !== $path || $existing->getFormat() !== $format) {
				$existing->setPath($path);
				$existing->setFormat($format);
				$existing->setUpdatedAt(self::nowMs());
				$this->bookMapper->update($existing);
			}
			return $existing;
		}

		$meta = $this->metadata->extract($file, $format);
		// precedence: app overrides (below) > sidecar > embedded metadata > file name
		$sidecarData = $sidecarEtag !== null ? $this->sidecar->read($file) : null;
		if ($sidecarData !== null) {
			$meta = self::applySidecar($meta, $sidecarData);
		}
		$now = self::nowMs();

		// metadata edits waiting to be written into the file (WriteMetadataJob) must not be overwritten by the old file content
		$keepMetadata = $existing !== null
			&& $this->jobList->has(WriteMetadataJob::class, WriteMetadataJob::argument($userId, $fileId));

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
		// fields edited in the app only (overrides) and edits waiting to be written into the file stay as they are
		$overrides = $existing?->getOverridesArray() ?? [];
		$take = static fn (string $field): bool => !$keepMetadata && !in_array($field, $overrides, true);
		if ($take('title')) {
			$book->setTitle($meta->title !== null ? mb_substr($meta->title, 0, 512) : null);
		}
		if ($take('authors')) {
			$book->setAuthorsArray($meta->authors);
		}
		if ($take('series')) {
			$book->setSeries($meta->series !== null ? mb_substr($meta->series, 0, 512) : null);
		}
		if ($take('seriesIndex')) {
			$book->setSeriesIndex($meta->seriesIndex);
		}
		if ($take('description')) {
			$book->setDescription($meta->description);
		}
		if ($take('language')) {
			$book->setLanguage($meta->language !== null ? mb_substr($meta->language, 0, 32) : null);
		}
		if ($take('publisher')) {
			$book->setPublisher($meta->publisher !== null ? mb_substr($meta->publisher, 0, 255) : null);
		}
		if ($take('isbn')) {
			$book->setIsbn($meta->isbn !== null ? mb_substr($meta->isbn, 0, 32) : null);
		}
		if ($take('publishedAt')) {
			$book->setPublishedAt($meta->publishedAt !== null ? substr($meta->publishedAt, 0, 10) : null);
		}
		$book->setFileMtime($mtime);
		$book->setFileEtag($etag);
		$book->setSidecarEtag($sidecarEtag);
		$book->setDeletedAt(null);
		$book->setUpdatedAt($now);

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
		if ($meta->coverData === null && !in_array($format, ['cbr', 'cb7', 'cbt'], true) && $book->getHasCover()) {
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
				$other->setSidecarEtag($sidecarEtag);
				$other->setDeletedAt(null);
				$other->setUpdatedAt($now);
				$book = $this->bookMapper->update($other);
			}
		} else {
			$book = $this->bookMapper->update($book);
		}

		if (!$keepMetadata) {
			$this->replaceFileTags($userId, $book, $meta->genres, $meta->tags, $meta->subjects);
		}
		return $book;
	}

	/**
	 * Sidecar fields that exist override the embedded ones field by field; its genres/tags replace the embedded ones.
	 */
	private static function applySidecar(BookMetadata $meta, SidecarData $sidecar): BookMetadata {
		$s = $sidecar->metadata;
		$changes = [];
		foreach (['title', 'series', 'seriesIndex', 'description', 'language', 'publisher', 'isbn', 'publishedAt'] as $field) {
			if ($s->{$field} !== null) {
				$changes[$field] = $s->{$field};
			}
		}
		if ($s->authors !== []) {
			$changes['authors'] = $s->authors;
		}
		if ($sidecar->hasLabels()) {
			$changes['genres'] = $s->genres;
			$changes['tags'] = $s->tags;
			$changes['subjects'] = $s->subjects;
		}
		return $changes === [] ? $meta : $meta->with($changes);
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
	 * @param callable(File, string, ?string): void $onFile called with the book, its format and the change marker of its sidecar (null = none)
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
	 * @param callable(File, string, ?string): void $onFile
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
			/** @var array<string, File> $sidecars sidecar files of this folder by name (taken from the listing, no extra lookups) */
			$sidecars = [];
			foreach ($children as $child) {
				if ($child instanceof File && SidecarService::isSidecarName($child->getName())) {
					$sidecars[$child->getName()] = $child;
				}
			}
			foreach ($children as $child) {
				if ($child instanceof Folder) {
					$stack[] = $child;
				} elseif ($child instanceof File) {
					if (SidecarService::isSidecarName($child->getName())) {
						continue; // sidecars are never books
					}
					$id = $child->getId();
					if (isset($seen[$id])) {
						continue;
					}
					$format = $this->metadata->detectFormat($child->getName(), $child->getMimeType());
					if ($format !== null) {
						$seen[$id] = true;
						$onFile($child, $format, SidecarService::stateOf($sidecars[SidecarService::nameFor($child->getName())] ?? null));
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
	 * so a click shows results right away even when background jobs run rarely (AJAX cron). Files over
	 * INTERACTIVE_MAX_BYTES are always queued: indexing a 400 MB archive must not block the request.
	 * @return array{found: int, indexed: int, queued: int}
	 */
	public function scanUserInteractive(string $userId, float $budgetSeconds = 20.0): array {
		return $this->scan($userId, $budgetSeconds, null, self::INTERACTIVE_MAX_BYTES);
	}

	/**
	 * @param ?float $inlineSeconds seconds to index inline before queueing (null = all inline, 0 = queue all)
	 * @param ?callable(File, bool): void $progress
	 * @param ?int $inlineMaxBytes files larger than this are never indexed inline (null = no limit)
	 * @return array{found: int, indexed: int, queued: int}
	 */
	private function scan(string $userId, ?float $inlineSeconds, ?callable $progress = null, ?int $inlineMaxBytes = null): array {
		$existing = [];
		foreach ($this->bookMapper->findAllByUser($userId) as $b) {
			$existing[$b->getFileId()] = $b;
		}
		$deadline = $inlineSeconds === null ? INF : microtime(true) + $inlineSeconds;
		$found = [];
		$stats = ['found' => 0, 'indexed' => 0, 'queued' => 0];
		$complete = $this->walkLibrary($userId, function (File $file, string $format, ?string $sidecarEtag = null) use ($userId, $existing, &$found, &$stats, $deadline, $progress, $inlineMaxBytes): void {
			$id = $file->getId();
			$found[$id] = true;
			$stats['found']++;
			$b = $existing[$id] ?? null;
			$stale = $b === null || $b->getDeletedAt() !== null || $b->getFileMtime() !== $file->getMTime()
				|| $b->getFileEtag() !== (string)$file->getEtag() || $b->getFormat() !== $format
				|| $b->getSidecarEtag() !== $sidecarEtag;
			if ($progress !== null) {
				$progress($file, $stale);
			}
			if (!$stale) {
				return;
			}
			if (microtime(true) < $deadline && ($inlineMaxBytes === null || (int)$file->getSize() <= $inlineMaxBytes)) {
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
		$this->applySort($qb, $userId, $q);
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

	/** Number of books matching the filters of the query (sort/paging ignored). */
	public function countBooks(string $userId, BookQuery $q): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from(self::BOOKS, 'b');
		$this->applyFilters($qb, $userId, $q);
		$res = $qb->executeQuery();
		$total = (int)$res->fetchOne();
		$res->closeCursor();
		return $total;
	}

	/**
	 * Series of the books matching the filters (same filters as findBooks), see SeriesAggregator.
	 * @return list<array{name: string, count: int, readCount: int, coverFileIds: list<int>, firstFileId: int, lastAddedAt: int}>
	 */
	public function listSeries(string $userId, BookQuery $q): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('b.file_id', 'b.series', 'b.series_index', 'b.added_at', 'b.read_status', 'b.title', 'b.path')->from(self::BOOKS, 'b');
		$this->applyFilters($qb, $userId, $q);
		$qb->andWhere($qb->expr()->isNotNull('b.series'))
			->andWhere($qb->expr()->neq('b.series', $qb->createNamedParameter('')));
		$res = $qb->executeQuery();
		$rows = [];
		while ($row = $res->fetch()) {
			/** @var array<string, mixed> $row */
			$rows[] = $row;
		}
		$res->closeCursor();
		return SeriesAggregator::aggregate($rows, $q->sort === 'added' ? 'added' : 'name', $q->order, SeriesAggregator::MAX_SERIES);
	}

	private function applyFilters(IQueryBuilder $qb, string $userId, BookQuery $q): void {
		$e = $qb->expr();
		$qb->where($e->eq('b.user_id', $qb->createNamedParameter($userId)))
			->andWhere($e->isNull('b.deleted_at'));
		foreach ($this->filterConditions($qb, $userId, $q, true) as $condition) {
			$qb->andWhere($condition);
		}
	}

	/**
	 * All filter conditions of a query (ANDed by the caller). $allowShelf is false inside the resolved query of a smart shelf:
	 * `shelf:` terms are ignored there, so smart shelves can never reference each other (no loops).
	 * @return list<string>
	 */
	private function filterConditions(IQueryBuilder $qb, string $userId, BookQuery $q, bool $allowShelf): array {
		$e = $qb->expr();
		$out = [];
		if ($q->status !== null) {
			$out[] = $e->eq('b.read_status', $qb->createNamedParameter($q->status));
		}
		if ($q->inSeries !== null) {
			$out[] = self::sql($q->inSeries
				? $e->andX($e->isNotNull('b.series'), $e->neq('b.series', $qb->createNamedParameter('')))
				: $e->orX($e->isNull('b.series'), $e->eq('b.series', $qb->createNamedParameter(''))));
		}
		$includes = [];
		foreach ($q->effectiveIncludes() as $entry) {
			$cond = $this->entryCondition($qb, $entry['type'], $entry['name'], false, $userId, $allowShelf);
			if ($cond !== null) {
				$includes[] = $cond;
			}
		}
		if ($includes !== []) {
			$out[] = self::sql($q->match === BookQuery::MATCH_ANY ? $e->orX(...$includes) : $e->andX(...$includes));
		}
		foreach ($q->exclude as $entry) {
			$cond = $this->entryCondition($qb, $entry['type'], $entry['name'], true, $userId, $allowShelf);
			if ($cond !== null) {
				$out[] = $cond;
			}
		}
		if ($q->search !== null) {
			$terms = array_slice(preg_split('/\s+/u', trim($q->search)) ?: [], 0, 8);
			foreach ($terms as $term) {
				if ($term === '') {
					continue;
				}
				$like = '%' . $this->db->escapeLikeParameter($term) . '%';
				$p = $qb->createNamedParameter($like);
				$out[] = self::sql($e->orX(
					$e->iLike('b.title', $p),
					$e->iLike('b.authors', $p),
					$e->iLike('b.series', $p),
					$e->iLike('b.description', $p),
					$e->in('b.id', $this->tagSubquery($qb, null, $like)),
				));
			}
		}
		return $out;
	}

	/**
	 * SQL condition for one filter entry (type genre|tag|author|series|format|shelf), case-insensitive.
	 * $negate builds the opposite (books without it, NULL columns count as "without").
	 * genre/tag terms of the form "Name/*" match Name and everything below "Name/".
	 * Returns null if the term has no effect (shelf term where shelves are not allowed, unknown shelf when excluding).
	 */
	private function entryCondition(IQueryBuilder $qb, string $type, string $name, bool $negate, string $userId = '', bool $allowShelf = false): ?string {
		$e = $qb->expr();
		switch ($type) {
			case 'genre':
			case 'tag':
				$tagType = $type === 'genre' ? Tag::TYPE_GENRE : Tag::TYPE_TAG;
				$base = FilterTerms::hierarchyBase($name);
				if ($base !== null) {
					$escaped = $this->db->escapeLikeParameter($base);
					$sub = $this->tagSubquery($qb, $tagType, $escaped, $escaped . FilterTerms::SEPARATOR . '%');
				} else {
					$sub = $this->tagSubquery($qb, $tagType, $this->db->escapeLikeParameter($name));
				}
				return $negate ? $e->notIn('b.id', $sub) : $e->in('b.id', $sub);
			case 'author':
				$cond = $e->iLike('b.authors', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($name) . '%'));
				return $negate ? '(' . $e->isNull('b.authors') . ' OR NOT (' . $cond . '))' : $cond;
			case 'series':
				$cond = $e->iLike('b.series', $qb->createNamedParameter($this->db->escapeLikeParameter($name)));
				return $negate ? '(' . $e->isNull('b.series') . ' OR NOT (' . $cond . '))' : $cond;
			case 'shelf':
				return $allowShelf ? $this->shelfCondition($qb, $userId, $name, $negate) : null;
			default:
				$p = $qb->createNamedParameter(strtolower($name));
				return $negate ? $e->neq($qb->createFunction('LOWER(b.format)'), $p) : $e->eq($qb->createFunction('LOWER(b.format)'), $p);
		}
	}

	/**
	 * `shelf:<id>`: manual shelf = assignment, smart shelf = its saved query (shelf terms inside it are ignored).
	 * A shelf of another user or an unknown id matches nothing (include) or has no effect (exclude).
	 */
	private function shelfCondition(IQueryBuilder $qb, string $userId, string $name, bool $negate): ?string {
		$shelf = $this->findShelf($userId, FilterTerms::shelfId($name));
		if ($shelf === null) {
			return $negate ? null : '1 = 0';
		}
		$e = $qb->expr();
		if ($shelf->isSmart()) {
			$conditions = $this->filterConditions($qb, $userId, ShelfService::toBookQuery($shelf->getQueryArray() ?? []), false);
			if ($conditions === []) {
				return $negate ? '1 = 0' : '1 = 1';
			}
			$all = self::sql($e->andX(...$conditions));
			return $negate ? 'NOT (' . $all . ')' : $all;
		}
		$sub = $this->db->getQueryBuilder();
		$sub->select('sbm.file_id')->from(self::SHELF_BOOKS, 'sbm')
			->where($sub->expr()->eq('sbm.shelf_id', $qb->createNamedParameter($shelf->getId(), IQueryBuilder::PARAM_INT)));
		$fn = $qb->createFunction('(' . $sub->getSQL() . ')');
		return $negate ? $e->notIn('b.file_id', $fn) : $e->in('b.file_id', $fn);
	}

	/** SQL text of a condition (the composite expressions of the query builder render themselves as SQL). */
	private static function sql(string|\OCP\DB\QueryBuilder\ICompositeExpression $condition): string {
		/** @psalm-suppress InvalidCast */
		return (string)$condition;
	}

	private function findShelf(string $userId, ?int $id): ?Shelf {
		if ($id === null || $this->shelves === null || $userId === '') {
			return null;
		}
		try {
			return $this->shelves->findByUserAndId($userId, $id);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Sub-select of book ids having a tag matching the (already escaped) LIKE pattern or, if given, the child pattern,
	 * case-insensitively.
	 */
	private function tagSubquery(IQueryBuilder $qb, ?string $type, string $pattern, ?string $childPattern = null): \OCP\DB\QueryBuilder\IQueryFunction {
		$sub = $this->db->getQueryBuilder();
		$nameCond = $sub->expr()->iLike('t.name', $qb->createNamedParameter($pattern));
		if ($childPattern !== null) {
			$nameCond = $sub->expr()->orX($nameCond, $sub->expr()->iLike('t.name', $qb->createNamedParameter($childPattern)));
		}
		$sub->select('t.book_id')->from(self::TAGS, 't')->where($nameCond);
		if ($type !== null) {
			$sub->andWhere($sub->expr()->eq('t.type', $qb->createNamedParameter($type)));
		}
		return $qb->createFunction('(' . $sub->getSQL() . ')');
	}

	private function applySort(IQueryBuilder $qb, string $userId, BookQuery $q): void {
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
			case 'shelf':
				// shelf order only makes sense inside one manual shelf (an include of shelf:<id>), otherwise sort by title
				$shelfId = $this->manualShelfInclude($userId, $q);
				if ($shelfId === null) {
					$qb->orderBy($qb->createFunction($title), $dir);
					break;
				}
				$qb->leftJoin('b', self::SHELF_BOOKS, 'sbo', $qb->expr()->andX(
					$qb->expr()->eq('sbo.file_id', 'b.file_id'),
					$qb->expr()->eq('sbo.shelf_id', $qb->createNamedParameter($shelfId, IQueryBuilder::PARAM_INT)),
				));
				$qb->orderBy($qb->createFunction('COALESCE(sbo.position, 0)'), $dir);
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

	/** Id of the first manual shelf among the include terms of the query. */
	private function manualShelfInclude(string $userId, BookQuery $q): ?int {
		foreach ($q->effectiveIncludes() as $entry) {
			if ($entry['type'] !== 'shelf') {
				continue;
			}
			$shelf = $this->findShelf($userId, FilterTerms::shelfId($entry['name']));
			if ($shelf !== null && !$shelf->isSmart()) {
				return $shelf->getId();
			}
		}
		return null;
	}

	/**
	 * Removes metadata overrides (one field or all) and re-reads those fields from the file.
	 *
	 * @param ?string $field one of Book::OVERRIDABLE_FIELDS, null = all
	 * @throws DoesNotExistException
	 * @throws NotFoundException
	 * @throws \InvalidArgumentException unknown field
	 */
	public function resetOverrides(string $userId, int $fileId, ?string $field = null): Book {
		if ($field !== null && !in_array($field, Book::OVERRIDABLE_FIELDS, true)) {
			throw new \InvalidArgumentException('Unknown field: ' . $field);
		}
		$book = $this->bookMapper->findByUserAndFile($userId, $fileId);
		$remaining = $field === null ? [] : array_values(array_diff($book->getOverridesArray(), [$field]));
		$book->setOverridesArray($remaining);
		$book = $this->bookMapper->update($book);
		$file = $this->getFileForUser($userId, $fileId);
		return $this->indexFile($userId, $file, true) ?? $book;
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

	/**
	 * False for files from a share with "download disabled" or "hide download" (view-only share): the content must not be handed
	 * out in any form (raw bytes, comic pages, archive entries, editor structure). Covers/thumbnails stay allowed.
	 */
	public function canReadContent(File $file): bool {
		$storage = $file->getStorage();
		if (!$storage->instanceOfStorage(ISharedStorage::class)) {
			return true;
		}
		/** @var ISharedStorage $storage */
		$share = $storage->getShare();
		if ($share->getAttributes()?->getAttribute('permissions', 'download') === false) {
			return false;
		}
		return $share->canSeeContent();
	}

	/** @throws NotFoundException */
	public function getFileForUser(string $userId, int $fileId): File {
		$node = $this->getNodeForUser($userId, $fileId);
		if (!$node instanceof File || !$node->isReadable()) {
			throw new NotFoundException('File not found');
		}
		return $node;
	}

	/**
	 * Deletes the e-book file. Nextcloud moves it to the trash bin when the files_trashbin app is
	 * enabled, so it can be restored from Files → Deleted files. The book is tombstoned for the
	 * user right away; other users of a shared file follow via the node-deleted event.
	 *
	 * @throws NotFoundException the user can not see the file
	 * @throws NotPermittedException the user may not delete it (e.g. read-only share)
	 */
	public function deleteFileForUser(string $userId, int $fileId): void {
		$file = $this->getFileForUser($userId, $fileId);
		if (!$file->isDeletable()) {
			throw new NotPermittedException('File can not be deleted');
		}
		$parent = $file->getParent();
		$name = $file->getName();
		$file->delete();
		// the sidecar goes along (to the trash bin as well); the delete event usually did that already
		$this->sidecar->deleteFor($parent, $name);
		$this->removeFile($userId, $fileId);
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
