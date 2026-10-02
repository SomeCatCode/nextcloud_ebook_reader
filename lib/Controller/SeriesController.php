<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\Http\AbstractOCSController;
use OCA\EbookReader\Service\BookQuery;
use OCA\EbookReader\Service\LibraryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;

/**
 * @psalm-import-type EbookReaderSeries from \OCA\EbookReader\ResponseDefinitions
 */
class SeriesController extends AbstractOCSController {
	public function __construct(
		IRequest $request,
		?string $userId,
		private LibraryService $library,
	) {
		parent::__construct($request, $userId);
	}

	/**
	 * List the series of the books matching the filters (at most 2000)
	 *
	 * @param string|null $search Free text search
	 * @param string|null $format Filter by format (epub, mobi, ...)
	 * @param string|null $genre Filter by genre
	 * @param string|null $tag Filter by tag
	 * @param string|null $author Filter by author
	 * @param string|null $status Filter by read status (unread|reading|finished)
	 * @param int<0, 1> $hideFinished 1 = leave out finished books (ignored when status is given)
	 * @param string $sort "added" sorts by the newest book of the series, everything else by name (natural order)
	 * @param string $order Sort order (asc|desc)
	 * @param list<string>|string|null $include Entries "genre:<name>", "tag:<name>", "author:<name>", "format:<fmt>" or "shelf:<id>" the book must match (see match)
	 * @param list<string>|string|null $exclude Entries in the same form; books having any of them are excluded
	 * @param string $match "all" (every include must match) or "any" (at least one)
	 * @return DataResponse<Http::STATUS_OK, array{series: list<EbookReaderSeries>}, array{}>
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Series found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/series')]
	public function index(
		?string $search = null,
		?string $format = null,
		?string $genre = null,
		?string $tag = null,
		?string $author = null,
		?string $status = null,
		int $hideFinished = 0,
		string $sort = 'title',
		string $order = 'asc',
		array|string|null $include = null,
		array|string|null $exclude = null,
		string $match = 'all',
	): DataResponse {
		$userId = $this->uid();
		$query = BookQuery::fromRequestParams([
			'search' => $search, 'format' => $format, 'genre' => $genre, 'tag' => $tag,
			'author' => $author, 'status' => $status, 'hideFinished' => $hideFinished, 'sort' => $sort, 'order' => $order,
			'include' => $include, 'exclude' => $exclude, 'match' => $match,
		]);
		return new DataResponse(['series' => $this->library->listSeries($userId, $query)]);
	}
}
