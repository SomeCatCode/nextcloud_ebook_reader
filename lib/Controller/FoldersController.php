<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\Http\AbstractOCSController;
use OCA\EbookReader\Service\FolderService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;

/**
 * @psalm-import-type EbookReaderFolder from \OCA\EbookReader\ResponseDefinitions
 */
class FoldersController extends AbstractOCSController {
	public function __construct(
		IRequest $request,
		?string $userId,
		private FolderService $folders,
	) {
		parent::__construct($request, $userId);
	}

	/**
	 * The folders of the library as a flat list sorted by path
	 *
	 * Every folder that holds at least one of the user's books, plus its ancestors up to the top-most folder inside a library folder or an incoming share.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{folders: list<EbookReaderFolder>}, array{}>
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Folders returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/folders')]
	public function index(): DataResponse {
		return new DataResponse(['folders' => $this->folders->listFolders($this->uid())]);
	}
}
