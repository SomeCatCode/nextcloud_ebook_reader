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
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;

/**
 * @psalm-import-type EbookReaderFacets from \OCA\EbookReader\ResponseDefinitions
 */
class FacetsController extends AbstractOCSController {
	public function __construct(
		IRequest $request,
		?string $userId,
		private LibraryService $library,
	) {
		parent::__construct($request, $userId);
	}

	/**
	 * Genres, tags, authors, series, formats, completion status and age ratings with their counts
	 *
	 * @return DataResponse<Http::STATUS_OK, EbookReaderFacets, array{}>
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Facets returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/facets')]
	public function index(): DataResponse {
		/** @var EbookReaderFacets $facets */
		$facets = $this->library->getFacets($this->uid());
		return new DataResponse($facets);
	}
}
