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

	public function __construct(
		IRequest $request,
		?string $userId,
		private LibraryService $library,
		private BookMapper $bookMapper,
		private BookSerializer $serializer,
		private ITimeFactory $time,
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
	 * @param string $sort Sort field (title|author|series|rating|added|read)
	 * @param string $order Sort order (asc|desc)
	 * @param int<1, 200> $limit Page size
	 * @param int $offset Offset
	 * @param list<string>|string|null $include Entries "genre:<name>", "tag:<name>", "author:<name>", "series:<name>" or "format:<fmt>" the book must match (see match)
	 * @param list<string>|string|null $exclude Entries in the same form; books having any of them are excluded
	 * @param string $match "all" (every include must match) or "any" (at least one)
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
	): DataResponse {
		$userId = $this->uid();
		$query = BookQuery::fromRequestParams([
			'search' => $search, 'format' => $format, 'genre' => $genre, 'tag' => $tag,
			'author' => $author, 'series' => $series, 'status' => $status,
			'sort' => $sort, 'order' => $order, 'limit' => $limit, 'offset' => $offset,
			'include' => $include, 'exclude' => $exclude, 'match' => $match,
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
	 * Change app-only fields (rating, read status) without touching the file
	 *
	 * Absent fields stay unchanged, an explicit null rating clears the rating.
	 *
	 * @param int $fileId Nextcloud file id
	 * @param int|null $rating Rating 0..5 or null
	 * @param string|null $readStatus unread|reading|finished (sets a manual status)
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
	public function patchAppData(int $fileId, ?int $rating = null, ?string $readStatus = null): DataResponse {
		$userId = $this->uid();
		$book = $this->findBook($userId, $fileId);
		$params = $this->request->getParams();
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
		}
		if ($changed) {
			$book->setUpdatedAt((int)$this->time->now()->format('Uv'));
			$this->bookMapper->update($book);
		}
		return new DataResponse($this->serializer->serializeWithProgress($userId, $book));
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
