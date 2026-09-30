<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\Http\AbstractOCSController;
use OCA\EbookReader\Service\LibraryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;

class ScanController extends AbstractOCSController {
	public function __construct(
		IRequest $request,
		?string $userId,
		private LibraryService $library,
	) {
		parent::__construct($request, $userId);
	}

	/**
	 * Re-scan the library folders of the current user
	 *
	 * Books are indexed right away for up to 20 seconds; the rest is handed to background jobs.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{found: int, indexed: int, queued: int}, array{}>
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: E-books found in the library folders, indexed now, and queued for background indexing
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 300)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/scan')]
	public function scan(): DataResponse {
		return new DataResponse($this->library->scanUserInteractive($this->uid()));
	}
}
