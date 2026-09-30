<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\ProgressMapper;
use OCA\EbookReader\Http\AbstractOCSController;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\ProgressService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\Files\NotFoundException;
use OCP\IRequest;

/**
 * @psalm-import-type EbookReaderBook from \OCA\EbookReader\ResponseDefinitions
 * @psalm-import-type EbookReaderProgress from \OCA\EbookReader\ResponseDefinitions
 * @psalm-import-type EbookReaderLocator from \OCA\EbookReader\ResponseDefinitions
 * @psalm-import-type EbookReaderProgressBatchResult from \OCA\EbookReader\ResponseDefinitions
 */
class ProgressController extends AbstractOCSController {
	public const MAX_BATCH = 100;
	public const MAX_RECENT = 50;

	public function __construct(
		IRequest $request,
		?string $userId,
		private ProgressService $progress,
		private ProgressMapper $progressMapper,
		private BookMapper $bookMapper,
		private LibraryService $library,
		private BookSerializer $serializer,
	) {
		parent::__construct($request, $userId);
	}

	/**
	 * Most recently read books ("continue reading")
	 *
	 * @param int<1, 50> $limit Maximum number of books
	 * @return DataResponse<Http::STATUS_OK, array{books: list<EbookReaderBook>}, array{}>
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Books returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/progress/recent')]
	public function recent(int $limit = 10): DataResponse {
		$userId = $this->uid();
		$limit = max(1, min(self::MAX_RECENT, $limit));
		$rows = $this->progressMapper->findRecent($userId, $limit);
		$byFile = [];
		foreach ($this->bookMapper->findByUserAndFiles($userId, array_map(static fn ($p): int => $p->getFileId(), $rows)) as $book) {
			$byFile[$book->getFileId()] = $book;
		}
		$books = [];
		foreach ($rows as $row) {
			if (isset($byFile[$row->getFileId()])) {
				$books[] = $byFile[$row->getFileId()];
			}
		}
		return new DataResponse(['books' => $this->serializer->serializeMany($userId, $books)]);
	}

	/**
	 * Get the reading progress of a book
	 *
	 * @param int $fileId Nextcloud file id
	 * @return DataResponse<Http::STATUS_OK, EbookReaderProgress, array{}>
	 * @throws OCSNotFoundException No progress stored
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Progress returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/progress/{fileId}', requirements: ['fileId' => '\d+'])]
	public function show(int $fileId): DataResponse {
		$progress = $this->progress->get($this->uid(), $fileId);
		if ($progress === null) {
			throw new OCSNotFoundException('No progress');
		}
		return new DataResponse($progress->toApi());
	}

	/**
	 * Store the reading progress of a book (last writer wins on clientUpdatedAt)
	 *
	 * @param int $fileId Nextcloud file id
	 * @param EbookReaderLocator $locator Readium-like locator
	 * @param float $percentage Overall progress 0..1
	 * @param string|null $device Device name
	 * @param int $clientUpdatedAt Client timestamp in milliseconds
	 * @return DataResponse<Http::STATUS_OK, EbookReaderProgress, array{}>|DataResponse<Http::STATUS_CONFLICT, array{current: EbookReaderProgress}, array{}>
	 * @throws OCSBadRequestException Invalid locator or percentage
	 * @throws OCSNotFoundException Book file not accessible
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Progress stored
	 * 409: The server has a newer progress, it is returned as current
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 240, period: 60)]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/progress/{fileId}', requirements: ['fileId' => '\d+'])]
	public function put(int $fileId, array $locator, float $percentage, int $clientUpdatedAt, ?string $device = null): DataResponse {
		$userId = $this->uid();
		$this->assertAccess($userId, $fileId);
		try {
			$result = $this->progress->put($userId, $fileId, $locator, $percentage, $device, $clientUpdatedAt);
		} catch (\InvalidArgumentException $e) {
			throw new OCSBadRequestException($e->getMessage());
		}
		if ($result['status'] === 'conflict') {
			return new DataResponse(['current' => $result['progress']->toApi()], Http::STATUS_CONFLICT);
		}
		return new DataResponse($result['progress']->toApi());
	}

	/**
	 * Store several progress entries at once (offline queue of a mobile client)
	 *
	 * @param list<array{fileId: int, locator: EbookReaderLocator, percentage: float|int, device?: string, clientUpdatedAt: int}> $items Progress entries (max 100)
	 * @return DataResponse<Http::STATUS_OK, array{results: list<EbookReaderProgressBatchResult>}, array{}>
	 * @throws OCSBadRequestException Too many or malformed items
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Per-item results
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/progress/batch')]
	public function batch(array $items): DataResponse {
		$userId = $this->uid();
		if (count($items) > self::MAX_BATCH) {
			throw new OCSBadRequestException('At most ' . self::MAX_BATCH . ' items per batch');
		}
		$results = [];
		foreach ($items as $item) {
			if (!is_array($item) || !isset($item['fileId'], $item['locator'], $item['percentage'], $item['clientUpdatedAt'])
				|| !is_numeric($item['fileId']) || !is_array($item['locator'])
				|| !is_numeric($item['percentage']) || !is_numeric($item['clientUpdatedAt'])) {
				throw new OCSBadRequestException('Malformed item');
			}
			$fileId = (int)$item['fileId'];
			try {
				$this->assertAccess($userId, $fileId);
				$device = isset($item['device']) && is_string($item['device']) ? $item['device'] : null;
				$result = $this->progress->put($userId, $fileId, $item['locator'], (float)$item['percentage'], $device, (int)$item['clientUpdatedAt']);
				$results[] = ['fileId' => $fileId, 'status' => $result['status'] === 'conflict' ? 'conflict' : 'ok', 'progress' => $result['progress']->toApi()];
			} catch (OCSNotFoundException) {
				$results[] = ['fileId' => $fileId, 'status' => 'error', 'progress' => null, 'error' => 'not found'];
			} catch (\InvalidArgumentException $e) {
				$results[] = ['fileId' => $fileId, 'status' => 'error', 'progress' => null, 'error' => $e->getMessage()];
			}
		}
		return new DataResponse(['results' => $results]);
	}

	/** @throws OCSNotFoundException */
	private function assertAccess(string $userId, int $fileId): void {
		try {
			$this->library->getFileForUser($userId, $fileId);
		} catch (NotFoundException) {
			throw new OCSNotFoundException('File not found');
		}
	}
}
