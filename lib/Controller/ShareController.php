<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\Http\AbstractOCSController;
use OCA\EbookReader\Service\ShareException;
use OCA\EbookReader\Service\ShareService;
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
 * Sharing books, shelves, series and folders with other users (read-only Nextcloud user shares managed by the app).
 *
 * @psalm-import-type EbookReaderShareOverview from \OCA\EbookReader\ResponseDefinitions
 * @psalm-import-type EbookReaderShareCreated from \OCA\EbookReader\ResponseDefinitions
 */
class ShareController extends AbstractOCSController {
	public function __construct(
		IRequest $request,
		?string $userId,
		private ShareService $sharing,
	) {
		parent::__construct($request, $userId);
	}

	/**
	 * List what the user shares with whom and what is shared with the user
	 *
	 * `outgoing`: books, shelves, series and folders the user shared (with the recipient); `incoming`: those shared with the user
	 * (with the owner). Entries have `type` book|shelf|series|folder; series entries carry `series`, outgoing folder entries `path`. Only shares made through the app are listed, not other Nextcloud shares.
	 *
	 * @return DataResponse<Http::STATUS_OK, EbookReaderShareOverview, array{}>
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Shares returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/shares')]
	public function index(): DataResponse {
		$userId = $this->uid();
		try {
			$this->sharing->ensureIncomingIndexed($userId);
		} catch (\Throwable) {
			// the overview must not fail because of it
		}
		return new DataResponse($this->sharing->overview($userId));
	}

	/**
	 * Share a book of the own library with a user (read-only)
	 *
	 * Creates a read-only Nextcloud user share of the book file. Admin sharing settings apply. Sharing again with the same
	 * user returns the existing share.
	 *
	 * @param int $fileId File id of the book
	 * @param string $shareWith User id of the recipient
	 * @return DataResponse<Http::STATUS_OK, EbookReaderShareCreated, array{}>
	 * @throws OCSBadRequestException Missing or unknown recipient, or the user themselves
	 * @throws OCSForbiddenException Not logged in, sharing disabled or not allowed for this book (message says why)
	 * @throws OCSNotFoundException Book not in the user's library
	 *
	 * 200: Book shared
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/books/{fileId}/shares', requirements: ['fileId' => '\d+'])]
	public function shareBook(int $fileId, string $shareWith = ''): DataResponse {
		$userId = $this->uid();
		try {
			return new DataResponse(['share' => $this->sharing->shareBook($userId, $fileId, $shareWith), 'skipped' => 0]);
		} catch (ShareException $e) {
			throw $this->map($e);
		}
	}

	/**
	 * Stop sharing a book
	 *
	 * With `shareWith` the owner removes the share with that user. Without it the caller removes a book shared with them
	 * (from `sharedBy`, or from everybody). A shared shelf that still contains the book keeps it shared.
	 *
	 * @param int $fileId File id of the book
	 * @param string $shareWith Recipient to stop sharing with (owner)
	 * @param string $sharedBy Owner whose share to remove (recipient; empty = all)
	 * @return DataResponse<Http::STATUS_OK, array{removed: int}, array{}>
	 * @throws OCSForbiddenException Not logged in
	 * @throws OCSNotFoundException No such share
	 *
	 * 200: Share removed
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/books/{fileId}/shares', requirements: ['fileId' => '\d+'])]
	public function unshareBook(int $fileId, string $shareWith = '', string $sharedBy = ''): DataResponse {
		$userId = $this->uid();
		try {
			if (trim($shareWith) !== '') {
				$this->sharing->unshareBook($userId, $fileId, $shareWith);
				return new DataResponse(['removed' => 1]);
			}
			return new DataResponse(['removed' => $this->sharing->leaveBook($userId, $fileId, $sharedBy)]);
		} catch (ShareException $e) {
			throw $this->map($e);
		}
	}

	/**
	 * Share a shelf live with a user (read-only)
	 *
	 * The recipient sees the shelf in their shelf list. Its books are shared as read-only Nextcloud shares; books added
	 * to or removed from the shelf (or matching a smart shelf) later are shared and unshared automatically.
	 *
	 * @param int $id Shelf id
	 * @param string $shareWith User id of the recipient
	 * @return DataResponse<Http::STATUS_OK, EbookReaderShareCreated, array{}>
	 * @throws OCSBadRequestException Missing or unknown recipient, the user themselves, or too many recipients (max. 50)
	 * @throws OCSForbiddenException Not logged in, or sharing disabled for the user
	 * @throws OCSNotFoundException Shelf not found
	 *
	 * 200: Shelf shared; skipped = books that could not be shared (no share permission, more than 1000 books)
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/shelves/{id}/shares', requirements: ['id' => '\d+'])]
	public function shareShelf(int $id, string $shareWith = ''): DataResponse {
		$userId = $this->uid();
		try {
			return new DataResponse($this->sharing->shareShelf($userId, $id, $shareWith));
		} catch (ShareException $e) {
			throw $this->map($e);
		}
	}

	/**
	 * Stop sharing a shelf
	 *
	 * With `shareWith` the owner stops sharing the shelf with that user. Without it the caller removes a shelf shared with
	 * them. The books shared for the shelf are unshared unless another share of the app still needs them.
	 *
	 * @param int $id Shelf id
	 * @param string $shareWith Recipient to stop sharing with (owner)
	 * @return DataResponse<Http::STATUS_OK, array{removed: int}, array{}>
	 * @throws OCSForbiddenException Not logged in
	 * @throws OCSNotFoundException Shelf or share not found
	 *
	 * 200: Share removed
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/shelves/{id}/shares', requirements: ['id' => '\d+'])]
	public function unshareShelf(int $id, string $shareWith = ''): DataResponse {
		$userId = $this->uid();
		try {
			if (trim($shareWith) !== '') {
				$this->sharing->unshareShelf($userId, $id, $shareWith);
			} else {
				$this->sharing->leaveShelf($userId, $id);
			}
		} catch (ShareException $e) {
			throw $this->map($e);
		}
		return new DataResponse(['removed' => 1]);
	}

	/**
	 * Share a series live with a user (read-only)
	 *
	 * All of the user's own books with that series name are shared as read-only Nextcloud shares; books that join the
	 * series later are shared automatically (the sync runs every 15 minutes). Admin sharing settings apply.
	 *
	 * @param string $series Series name (case-insensitive)
	 * @param string $shareWith User id of the recipient
	 * @return DataResponse<Http::STATUS_OK, EbookReaderShareCreated, array{}>
	 * @throws OCSBadRequestException Missing or unknown recipient, the user themselves, or too many recipients (max. 50)
	 * @throws OCSForbiddenException Not logged in, or sharing disabled for the user
	 * @throws OCSNotFoundException The user has no books in that series
	 *
	 * 200: Series shared; skipped = books that could not be shared (no share permission, more than 1000 books)
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/series/shares')]
	public function shareSeries(string $series = '', string $shareWith = ''): DataResponse {
		$userId = $this->uid();
		try {
			return new DataResponse($this->sharing->shareSeries($userId, $series, $shareWith));
		} catch (ShareException $e) {
			throw $this->map($e);
		}
	}

	/**
	 * Stop sharing a series with a user
	 *
	 * The books shared for the series are unshared unless another share of the app still needs them.
	 *
	 * @param string $series Series name
	 * @param string $shareWith Recipient to stop sharing with
	 * @return DataResponse<Http::STATUS_OK, array{removed: int}, array{}>
	 * @throws OCSForbiddenException Not logged in
	 * @throws OCSNotFoundException No such share
	 *
	 * 200: Share removed
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/series/shares')]
	public function unshareSeries(string $series = '', string $shareWith = ''): DataResponse {
		$userId = $this->uid();
		try {
			$this->sharing->unshareSeries($userId, $series, $shareWith);
		} catch (ShareException $e) {
			throw $this->map($e);
		}
		return new DataResponse(['removed' => 1]);
	}

	/**
	 * Share a folder of the own library with a user (read-only)
	 *
	 * Creates ONE read-only Nextcloud user share of the folder (not one share per book). Only the user's own folders inside
	 * the library folders can be shared. The recipient's library picks up the books inside, wherever the share is mounted.
	 * Admin sharing settings apply.
	 *
	 * @param string $path Folder path relative to the user's home, e.g. "/Books/Comics/Saga"
	 * @param string $shareWith User id of the recipient
	 * @return DataResponse<Http::STATUS_OK, EbookReaderShareCreated, array{}>
	 * @throws OCSBadRequestException Missing or unknown recipient, the user themselves, bad path or too many recipients (max. 50)
	 * @throws OCSForbiddenException Not logged in, sharing disabled, folder outside the library or not shareable (message says why)
	 * @throws OCSNotFoundException Folder not found
	 *
	 * 200: Folder shared
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/folders/shares')]
	public function shareFolder(string $path = '', string $shareWith = ''): DataResponse {
		$userId = $this->uid();
		try {
			return new DataResponse($this->sharing->shareFolder($userId, $path, $shareWith));
		} catch (ShareException $e) {
			throw $this->map($e);
		}
	}

	/**
	 * Stop sharing a folder with a user
	 *
	 * The Nextcloud share of the folder is deleted when the app created it (a share the user made in Files stays).
	 *
	 * @param string $path Folder path relative to the user's home
	 * @param string $shareWith Recipient to stop sharing with
	 * @return DataResponse<Http::STATUS_OK, array{removed: int}, array{}>
	 * @throws OCSBadRequestException Bad path
	 * @throws OCSForbiddenException Not logged in
	 * @throws OCSNotFoundException No such share
	 *
	 * 200: Share removed
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/folders/shares')]
	public function unshareFolder(string $path = '', string $shareWith = ''): DataResponse {
		$userId = $this->uid();
		try {
			$this->sharing->unshareFolder($userId, $path, $shareWith);
		} catch (ShareException $e) {
			throw $this->map($e);
		}
		return new DataResponse(['removed' => 1]);
	}

	private function map(ShareException $e): OCSBadRequestException|OCSNotFoundException|OCSForbiddenException {
		return match ($e->reason) {
			ShareException::NOT_FOUND => new OCSNotFoundException($e->getMessage()),
			ShareException::FORBIDDEN => new OCSForbiddenException($e->getMessage()),
			default => new OCSBadRequestException($e->getMessage()),
		};
	}
}
