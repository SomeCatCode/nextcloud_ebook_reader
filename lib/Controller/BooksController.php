<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Http\AbstractOCSController;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Service\BookQuery;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\ProgressService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;

/**
 * @psalm-import-type EbookReaderBook from \OCA\EbookReader\ResponseDefinitions
 * @psalm-import-type EbookReaderBookList from \OCA\EbookReader\ResponseDefinitions
 */
class BooksController extends AbstractOCSController {
	private const MAX_BULK_DELETE = 100;
	private const MAX_BULK_APP_DATA = 500;

	public function __construct(
		IRequest $request,
		?string $userId,
		private LibraryService $library,
		private BookMapper $bookMapper,
		private BookSerializer $serializer,
		private ITimeFactory $time,
		private ProgressService $progress,
	) {
		parent::__construct($request, $userId);
	}

	/**
	 * List and filter the library
	 *
	 * @param string|null $search Free text search
	 * @param string|null $format Filter by format (epub, mobi, ...)
	 * @param string|null $genre Filter by genre
	 * @param string|null $tag Filter by tag
	 * @param string|null $author Filter by author
	 * @param string|null $series Filter by series
	 * @param string|null $status Filter by read status (unread|reading|finished)
	 * @param string $sort Sort field (title|author|series|rating|added|read|shelf); shelf sorts by position inside a manual shelf (include shelf:<id>), series by series index
	 * @param string $order Sort order (asc|desc)
	 * @param int<1, 200> $limit Page size
	 * @param int $offset Offset
	 * @param list<string>|string|null $include Entries "genre:<name>", "tag:<name>", "author:<name>", "series:<name>", "format:<fmt>" or "shelf:<id>" the book must match (see match); "genre:X/*" and "tag:X/*" also match everything below X/
	 * @param list<string>|string|null $exclude Entries in the same form; books having any of them are excluded
	 * @param string $match "all" (every include must match) or "any" (at least one)
	 * @param int<0, 1>|null $inSeries 0 = only books without a series, 1 = only books in a series
	 * @param int<0, 1> $hideFinished 1 = leave out finished books (ignored when status is given)
	 * @return DataResponse<Http::STATUS_OK, EbookReaderBookList, array{}>
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Books found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/books')]
	public function index(
		?string $search = null,
		?string $format = null,
		?string $genre = null,
		?string $tag = null,
		?string $author = null,
		?string $series = null,
		?string $status = null,
		string $sort = 'title',
		string $order = 'asc',
		int $limit = BookQuery::DEFAULT_LIMIT,
		int $offset = 0,
		array|string|null $include = null,
		array|string|null $exclude = null,
		string $match = 'all',
		?int $inSeries = null,
		int $hideFinished = 0,
	): DataResponse {
		$userId = $this->uid();
		$query = BookQuery::fromRequestParams([
			'search' => $search, 'format' => $format, 'genre' => $genre, 'tag' => $tag,
			'author' => $author, 'series' => $series, 'status' => $status, 'hideFinished' => $hideFinished,
			'sort' => $sort, 'order' => $order, 'limit' => $limit, 'offset' => $offset,
			'include' => $include, 'exclude' => $exclude, 'match' => $match,
			'inSeries' => $inSeries,
		]);
		$result = $this->library->findBooks($userId, $query);
		return new DataResponse([
			'books' => $this->serializer->serializeMany($userId, $result['books']),
			'total' => $result['total'],
		]);
	}

	/**
	 * Get a single book
	 *
	 * @param int $fileId Nextcloud file id
	 * @return DataResponse<Http::STATUS_OK, EbookReaderBook, array{}>
	 * @throws OCSNotFoundException Book not found
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Book returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/books/{fileId}', requirements: ['fileId' => '\d+'])]
	public function show(int $fileId): DataResponse {
		$userId = $this->uid();
		return new DataResponse($this->serializer->serializeWithProgress($userId, $this->findBook($userId, $fileId)));
	}

	/**
	 * Delete a book: the file is moved to the Nextcloud trash bin (if enabled)
	 *
	 * @param int $fileId Nextcloud file id
	 * @return DataResponse<Http::STATUS_OK, array{deleted: int}, array{}>
	 * @throws OCSNotFoundException Book not found
	 * @throws OCSForbiddenException Not logged in or no permission to delete the file
	 *
	 * 200: File deleted
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/books/{fileId}', requirements: ['fileId' => '\d+'])]
	public function destroy(int $fileId): DataResponse {
		$userId = $this->uid();
		$this->findBook($userId, $fileId);
		try {
			$this->library->deleteFileForUser($userId, $fileId);
		} catch (NotFoundException) {
			throw new OCSNotFoundException('Book not found');
		} catch (NotPermittedException) {
			throw new OCSForbiddenException('No permission to delete this file');
		}
		return new DataResponse(['deleted' => $fileId]);
	}

	/**
	 * Delete several books (max. 100); files go to the trash bin (if enabled)
	 *
	 * @param list<int> $fileIds Nextcloud file ids
	 * @return DataResponse<Http::STATUS_OK, array{deleted: list<int>, failed: list<array{fileId: int, error: string}>}, array{}>
	 * @throws OCSBadRequestException Empty or too large selection
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Result per file
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/books/delete')]
	public function destroyMany(array $fileIds = []): DataResponse {
		$userId = $this->uid();
		$ids = array_values(array_unique(array_map('intval', $fileIds)));
		if ($ids === [] || count($ids) > self::MAX_BULK_DELETE) {
			throw new OCSBadRequestException('Select between 1 and ' . self::MAX_BULK_DELETE . ' books');
		}
		$deleted = [];
		$failed = [];
		foreach ($ids as $id) {
			try {
				$this->findBook($userId, $id);
				$this->library->deleteFileForUser($userId, $id);
				$deleted[] = $id;
			} catch (OCSNotFoundException|NotFoundException) {
				$failed[] = ['fileId' => $id, 'error' => 'not_found'];
			} catch (NotPermittedException) {
				$failed[] = ['fileId' => $id, 'error' => 'forbidden'];
			} catch (\Throwable) {
				$failed[] = ['fileId' => $id, 'error' => 'failed'];
			}
		}
		return new DataResponse(['deleted' => $deleted, 'failed' => $failed]);
	}

	/**
	 * Change app-only fields (rating, read status, completion, age rating) without touching the file
	 *
	 * Absent fields stay unchanged, an explicit null rating clears the rating.
	 * The read status also sets the reading progress: finished = 100 % (locator href "" with
	 * totalProgression 1), unread = 0 % (href "", position 1, totalProgression 0; nothing is created
	 * without stored progress), reading keeps it. The progress row gets clientUpdatedAt = server time,
	 * so it wins over older positions of other devices; the returned book carries the new progress.
	 * completion: "ongoing", "completed" or null (unknown). ageRating: 0, 6, 12, 16, 18 or null; any value
	 * given here (also null = "no rating") is a manual value that survives re-indexing (ageRatingManual = true).
	 * resetAgeRating = true drops the manual value: the rating from the file (ComicInfo.xml AgeRating, EPUB
	 * schema:typicalAgeRange) applies again. A change bumps updatedAt, so GET /sync delivers it.
	 *
	 * @param int $fileId Nextcloud file id
	 * @param int|null $rating Rating 0..5 or null
	 * @param string|null $readStatus unread|reading|finished (sets a manual status and the progress: finished 100 %, unread 0 %)
	 * @param string|null $completion ongoing|completed, or null to clear (unknown)
	 * @param int|null $ageRating Age rating 0|6|12|16|18, or null for "no rating" (both manual)
	 * @param bool $resetAgeRating true = use the age rating from the file again (not together with ageRating)
	 * @return DataResponse<Http::STATUS_OK, EbookReaderBook, array{}>
	 * @throws OCSBadRequestException Invalid value
	 * @throws OCSNotFoundException Book not found
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Book updated
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/books/{fileId}/app-data', requirements: ['fileId' => '\d+'])]
	public function patchAppData(int $fileId, ?int $rating = null, ?string $readStatus = null, ?string $completion = null, ?int $ageRating = null, bool $resetAgeRating = false): DataResponse {
		$userId = $this->uid();
		$book = $this->findBook($userId, $fileId);
		$params = $this->request->getParams();
		$flags = $this->flagChanges($params, $completion, $ageRating, $resetAgeRating);
		$changed = false;

		if (array_key_exists('rating', $params)) {
			if ($rating !== null && ($rating < 0 || $rating > 5)) {
				throw new OCSBadRequestException('rating must be between 0 and 5');
			}
			$book->setRating($rating);
			$changed = true;
		}
		if ($readStatus !== null) {
			if (!in_array($readStatus, BookQuery::STATUSES, true)) {
				throw new OCSBadRequestException('readStatus must be one of unread, reading, finished');
			}
			$book->setReadStatus($readStatus);
			$book->setReadStatusManual(true);
			$changed = true;
			$this->progress->applyReadStatus($userId, $fileId, $readStatus);
		}
		$changed = self::applyFlags($book, $flags) || $changed;
		if ($changed) {
			$book->setUpdatedAt((int)$this->time->now()->format('Uv'));
			$this->bookMapper->update($book);
		}
		return new DataResponse($this->serializer->serializeWithProgress($userId, $book));
	}

	/**
	 * Set completion status and/or age rating of several books (max. 500)
	 *
	 * Same semantics as the fields of PATCH /books/{fileId}/app-data: absent fields stay unchanged,
	 * completion null clears it, ageRating null sets "no rating" by hand, resetAgeRating uses the value
	 * from the file again. Changed books get a new updatedAt (delivered by GET /sync).
	 *
	 * @param list<int> $fileIds Nextcloud file ids
	 * @param string|null $completion ongoing|completed, or null to clear (unknown)
	 * @param int|null $ageRating Age rating 0|6|12|16|18, or null for "no rating"
	 * @param bool $resetAgeRating true = use the age rating from the file again
	 * @return DataResponse<Http::STATUS_OK, array{updated: int, unchanged: int, failed: list<array{fileId: int, error: string}>}, array{}>
	 * @throws OCSBadRequestException Empty or too large selection, no field or an invalid value
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Result per file
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/books/app-data')]
	public function patchAppDataMany(array $fileIds = [], ?string $completion = null, ?int $ageRating = null, bool $resetAgeRating = false): DataResponse {
		$userId = $this->uid();
		$ids = array_values(array_unique(array_map('intval', $fileIds)));
		if ($ids === [] || count($ids) > self::MAX_BULK_APP_DATA) {
			throw new OCSBadRequestException('Select between 1 and ' . self::MAX_BULK_APP_DATA . ' books');
		}
		$flags = $this->flagChanges($this->request->getParams(), $completion, $ageRating, $resetAgeRating);
		if ($flags === []) {
			throw new OCSBadRequestException('Nothing to change');
		}
		$now = (int)$this->time->now()->format('Uv');
		$byFile = [];
		foreach ($this->bookMapper->findByUserAndFiles($userId, $ids) as $b) {
			$byFile[$b->getFileId()] = $b;
		}
		$updated = 0;
		$unchanged = 0;
		$failed = [];
		foreach ($ids as $id) {
			$book = $byFile[$id] ?? null;
			if ($book === null) {
				$failed[] = ['fileId' => $id, 'error' => 'not_found'];
				continue;
			}
			if (!self::applyFlags($book, $flags)) {
				$unchanged++;
				continue;
			}
			$book->setUpdatedAt($now);
			try {
				$this->bookMapper->update($book);
				$updated++;
			} catch (\Throwable) {
				$failed[] = ['fileId' => $id, 'error' => 'failed'];
			}
		}
		return new DataResponse(['updated' => $updated, 'unchanged' => $unchanged, 'failed' => $failed]);
	}

	/**
	 * Next volume of the book's series
	 *
	 * Reading order: series index ascending (volumes without an index last), then title in natural order,
	 * then file id. Other copies of the same volume (same series index) are skipped. book is null for the
	 * last volume and for books without a series.
	 *
	 * @param int $fileId Nextcloud file id
	 * @return DataResponse<Http::STATUS_OK, array{book: ?EbookReaderBook}, array{}>
	 * @throws OCSNotFoundException Book not found
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Next volume returned (book is null when there is none)
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/books/{fileId}/next', requirements: ['fileId' => '\d+'])]
	public function next(int $fileId): DataResponse {
		$userId = $this->uid();
		$next = $this->library->nextVolume($userId, $this->findBook($userId, $fileId));
		return new DataResponse(['book' => $next === null ? null : $this->serializer->serializeWithProgress($userId, $next)]);
	}

	/**
	 * Validated completion/age rating changes of a request (only the fields that are present).
	 *
	 * @param array<array-key, mixed> $params request parameters (to tell an explicit null from an absent field)
	 * @return array{completion?: ?string, ageRating?: ?int, resetAgeRating?: true}
	 * @throws OCSBadRequestException
	 */
	private function flagChanges(array $params, ?string $completion, ?int $ageRating, bool $resetAgeRating): array {
		$out = [];
		if (array_key_exists('completion', $params)) {
			if ($completion !== null && !in_array($completion, Book::COMPLETIONS, true)) {
				throw new OCSBadRequestException('completion must be ongoing, completed or null');
			}
			$out['completion'] = $completion;
		}
		$hasAge = array_key_exists('ageRating', $params);
		if ($hasAge && $resetAgeRating) {
			throw new OCSBadRequestException('ageRating and resetAgeRating can not be combined');
		}
		if ($hasAge) {
			if ($ageRating !== null && !in_array($ageRating, Book::AGE_RATINGS, true)) {
				throw new OCSBadRequestException('ageRating must be one of 0, 6, 12, 16, 18 or null');
			}
			$out['ageRating'] = $ageRating;
		}
		if ($resetAgeRating) {
			$out['resetAgeRating'] = true;
		}
		return $out;
	}

	/**
	 * @param array{completion?: ?string, ageRating?: ?int, resetAgeRating?: true} $flags
	 * @return bool whether anything that is part of the API changed
	 */
	private static function applyFlags(Book $book, array $flags): bool {
		$changed = false;
		if (array_key_exists('completion', $flags) && $book->getCompletion() !== $flags['completion']) {
			$book->setCompletion($flags['completion']);
			$changed = true;
		}
		if (array_key_exists('ageRating', $flags)
			&& ($book->getAgeRating() !== $flags['ageRating'] || !$book->getAgeRatingManual())) {
			$book->setManualAgeRating($flags['ageRating']);
			$changed = true;
		}
		if (isset($flags['resetAgeRating']) && $book->getAgeRatingManual()) {
			$book->resetAgeRating();
			$changed = true;
		}
		return $changed;
	}

	/** @throws OCSNotFoundException */
	private function findBook(string $userId, int $fileId): Book {
		try {
			return $this->library->getBook($userId, $fileId);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException('Book not found');
		}
	}
}
