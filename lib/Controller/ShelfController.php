<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\Http\AbstractOCSController;
use OCA\EbookReader\Service\ShareService;
use OCA\EbookReader\Service\ShelfException;
use OCA\EbookReader\Service\ShelfService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;

/**
 * @psalm-import-type EbookReaderShelf from \OCA\EbookReader\ResponseDefinitions
 * @psalm-import-type EbookReaderSmartQuery from \OCA\EbookReader\ResponseDefinitions
 */
class ShelfController extends AbstractOCSController {
	public function __construct(
		IRequest $request,
		?string $userId,
		private ShelfService $shelves,
		private ?ShareService $sharing = null,
	) {
		parent::__construct($request, $userId);
	}

	/**
	 * List the shelves of the user
	 *
	 * Own shelves come first (sorted by sortOrder, then name), followed by the shelves other users share with the user
	 * (`readOnly: true`, `owner`/`ownerDisplayName` of the sharing user, `query: null`).
	 *
	 * @return DataResponse<Http::STATUS_OK, array{shelves: list<EbookReaderShelf>}, array{}>
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Shelves returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/shelves')]
	public function index(): DataResponse {
		$userId = $this->uid();
		// books shared with the user since the last scan show up right away
		try {
			$this->sharing?->ensureIncomingIndexed($userId);
		} catch (\Throwable) {
			// listing must not fail because of it
		}
		return new DataResponse(['shelves' => $this->shelves->list($userId)]);
	}

	/**
	 * Create a shelf
	 *
	 * @param string $name Name (1 to 255 characters, unique per user, case-insensitive)
	 * @param string $type "manual" or "smart"
	 * @param EbookReaderSmartQuery|null $query Library filter state, smart shelves only
	 * @return DataResponse<Http::STATUS_OK, EbookReaderShelf, array{}>
	 * @throws OCSBadRequestException Invalid name, type or query, duplicate name or too many shelves (max. 200)
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Shelf created
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/shelves')]
	public function create(string $name = '', string $type = 'manual', ?array $query = null): DataResponse {
		$userId = $this->uid();
		try {
			return new DataResponse($this->shelves->create($userId, $name, $type, $query));
		} catch (ShelfException $e) {
			throw $this->map($e);
		}
	}

	/**
	 * Change a shelf (name, smart query, position in the list)
	 *
	 * @param int $id Shelf id
	 * @param string|null $name New name
	 * @param EbookReaderSmartQuery|null $query New query (smart shelves only)
	 * @param int|null $sortOrder New position in the list
	 * @return DataResponse<Http::STATUS_OK, EbookReaderShelf, array{}>
	 * @throws OCSBadRequestException Invalid value or duplicate name
	 * @throws OCSNotFoundException Shelf not found
	 * @throws OCSForbiddenException Not logged in, or the shelf is shared with the user (read-only)
	 *
	 * 200: Shelf updated
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/shelves/{id}', requirements: ['id' => '\d+'])]
	public function update(int $id, ?string $name = null, ?array $query = null, ?int $sortOrder = null): DataResponse {
		$userId = $this->uid();
		$queryGiven = array_key_exists('query', $this->request->getParams());
		try {
			return new DataResponse($this->shelves->update($userId, $id, $name, $queryGiven, $query, $sortOrder));
		} catch (ShelfException $e) {
			throw $this->map($e);
		}
	}

	/**
	 * Delete a shelf; the books stay in the library
	 *
	 * @param int $id Shelf id
	 * @return DataResponse<Http::STATUS_OK, array{deleted: int}, array{}>
	 * @throws OCSNotFoundException Shelf not found
	 * @throws OCSForbiddenException Not logged in, or the shelf is shared with the user (read-only)
	 *
	 * 200: Shelf deleted
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/shelves/{id}', requirements: ['id' => '\d+'])]
	public function destroy(int $id): DataResponse {
		$userId = $this->uid();
		try {
			$this->shelves->delete($userId, $id);
		} catch (ShelfException $e) {
			throw $this->map($e);
		}
		return new DataResponse(['deleted' => $id]);
	}

	/**
	 * Add books to a manual shelf (only books of the own library)
	 *
	 * @param int $id Shelf id
	 * @param list<int> $fileIds Nextcloud file ids (max. 500)
	 * @return DataResponse<Http::STATUS_OK, array{added: int, skipped: int}, array{}>
	 * @throws OCSBadRequestException Empty or too large selection, or a smart shelf
	 * @throws OCSNotFoundException Shelf not found
	 * @throws OCSForbiddenException Not logged in, or the shelf is shared with the user (read-only)
	 *
	 * 200: Books added; skipped counts unknown, foreign and already assigned books
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/shelves/{id}/books', requirements: ['id' => '\d+'])]
	public function addBooks(int $id, array $fileIds = []): DataResponse {
		$userId = $this->uid();
		try {
			$this->requireFileIds($fileIds);
			return new DataResponse($this->shelves->addBooks($userId, $id, $fileIds));
		} catch (ShelfException $e) {
			throw $this->map($e);
		}
	}

	/**
	 * Remove books from a manual shelf
	 *
	 * @param int $id Shelf id
	 * @param list<int> $fileIds Nextcloud file ids (max. 500)
	 * @return DataResponse<Http::STATUS_OK, array{removed: int}, array{}>
	 * @throws OCSBadRequestException Empty or too large selection, or a smart shelf
	 * @throws OCSNotFoundException Shelf not found
	 * @throws OCSForbiddenException Not logged in, or the shelf is shared with the user (read-only)
	 *
	 * 200: Books removed from the shelf
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/shelves/{id}/books', requirements: ['id' => '\d+'])]
	public function removeBooks(int $id, array $fileIds = []): DataResponse {
		$userId = $this->uid();
		try {
			$this->requireFileIds($fileIds);
			return new DataResponse($this->shelves->removeBooks($userId, $id, $fileIds));
		} catch (ShelfException $e) {
			throw $this->map($e);
		}
	}

	/**
	 * Set the order of the books of a manual shelf
	 *
	 * @param int $id Shelf id
	 * @param list<int> $fileIds Nextcloud file ids in the new order (max. 500; books not listed follow)
	 * @return DataResponse<Http::STATUS_OK, array{fileIds: list<int>}, array{}>
	 * @throws OCSBadRequestException Empty or too large selection, or a smart shelf
	 * @throws OCSNotFoundException Shelf not found
	 * @throws OCSForbiddenException Not logged in, or the shelf is shared with the user (read-only)
	 *
	 * 200: New order of the shelf
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/shelves/{id}/books/order', requirements: ['id' => '\d+'])]
	public function reorder(int $id, array $fileIds = []): DataResponse {
		$userId = $this->uid();
		try {
			$this->requireFileIds($fileIds);
			return new DataResponse($this->shelves->reorder($userId, $id, $fileIds));
		} catch (ShelfException $e) {
			throw $this->map($e);
		}
	}

	/** @throws ShelfException */
	private function requireFileIds(array $fileIds): void {
		if ($fileIds === []) {
			throw new ShelfException('fileIds must not be empty', ShelfException::INVALID);
		}
		ShelfService::cleanFileIds($fileIds);
	}

	private function map(ShelfException $e): OCSBadRequestException|OCSNotFoundException|OCSForbiddenException {
		return match ($e->reason) {
			ShelfException::NOT_FOUND => new OCSNotFoundException($e->getMessage()),
			ShelfException::FORBIDDEN => new OCSForbiddenException($e->getMessage()),
			default => new OCSBadRequestException($e->getMessage()),
		};
	}
}
