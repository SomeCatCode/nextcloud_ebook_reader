<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\Http\AbstractOCSController;
use OCA\EbookReader\Service\OrganizeException;
use OCA\EbookReader\Service\OrganizeService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;

/**
 * @psalm-import-type EbookReaderOrganizePreview from \OCA\EbookReader\ResponseDefinitions
 * @psalm-import-type EbookReaderOrganizeResult from \OCA\EbookReader\ResponseDefinitions
 */
class OrganizeController extends AbstractOCSController {
	public function __construct(
		IRequest $request,
		?string $userId,
		private OrganizeService $organize,
	) {
		parent::__construct($request, $userId);
	}

	/**
	 * Preview where books would be moved to by a pattern
	 *
	 * @param list<int> $fileIds Nextcloud file ids (max 500)
	 * @param string $pattern Path pattern, e.g. "{author}/{series}/{series_index:2} - {title}"
	 * @param string|null $targetFolder Base folder inside a library folder (default: first library folder)
	 * @return DataResponse<Http::STATUS_OK, EbookReaderOrganizePreview, array{}>
	 * @throws OCSBadRequestException Invalid selection, pattern or target folder
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Preview computed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/organize/preview')]
	public function preview(array $fileIds = [], string $pattern = '', ?string $targetFolder = null): DataResponse {
		$userId = $this->uid();
		$ids = $this->validate($fileIds, $pattern);
		try {
			return new DataResponse($this->organize->preview($userId, $ids, $pattern, $targetFolder));
		} catch (OrganizeException $e) {
			throw new OCSBadRequestException($e->getMessage());
		}
	}

	/**
	 * Move and rename books by a pattern
	 *
	 * @param list<int> $fileIds Nextcloud file ids (max 500)
	 * @param string $pattern Path pattern, e.g. "{author}/{series}/{series_index:2} - {title}"
	 * @param string|null $targetFolder Base folder inside a library folder (default: first library folder)
	 * @return DataResponse<Http::STATUS_OK, EbookReaderOrganizeResult, array{}>
	 * @throws OCSBadRequestException Invalid selection, pattern or target folder
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Books moved (see the status of each item)
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/organize/apply')]
	public function apply(array $fileIds = [], string $pattern = '', ?string $targetFolder = null): DataResponse {
		$userId = $this->uid();
		$ids = $this->validate($fileIds, $pattern);
		try {
			return new DataResponse($this->organize->apply($userId, $ids, $pattern, $targetFolder));
		} catch (OrganizeException $e) {
			throw new OCSBadRequestException($e->getMessage());
		}
	}

	/**
	 * @param array<array-key, mixed> $fileIds
	 * @return list<int>
	 * @throws OCSBadRequestException
	 */
	private function validate(array $fileIds, string $pattern): array {
		$ids = [];
		foreach ($fileIds as $id) {
			if (is_int($id) || (is_string($id) && ctype_digit($id))) {
				$id = (int)$id;
				if ($id > 0) {
					$ids[$id] = $id;
				}
			}
		}
		$ids = array_values($ids);
		if ($ids === []) {
			throw new OCSBadRequestException('fileIds must contain at least one file id');
		}
		if (count($ids) > OrganizeService::MAX_FILES) {
			throw new OCSBadRequestException('At most ' . OrganizeService::MAX_FILES . ' files per request');
		}
		if (trim($pattern) === '' || strlen($pattern) > OrganizeService::MAX_PATTERN_LENGTH) {
			throw new OCSBadRequestException('pattern must not be empty or longer than ' . OrganizeService::MAX_PATTERN_LENGTH . ' characters');
		}
		return $ids;
	}
}
