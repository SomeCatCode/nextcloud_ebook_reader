<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\Http\AbstractOCSController;
use OCA\EbookReader\Service\OpdsSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;

/**
 * Switches of the OPDS catalog: per user (enabled) and, for administrators, for the instance (allowed).
 */
class OpdsSettingsController extends AbstractOCSController {
	public function __construct(
		IRequest $request,
		?string $userId,
		private OpdsSettings $settings,
		private IGroupManager $groups,
	) {
		parent::__construct($request, $userId);
	}

	/**
	 * Get the OPDS switches and the catalog URL
	 *
	 * @return DataResponse<Http::STATUS_OK, array{enabled: bool, allowed: bool, isAdmin: bool, url: string}, array{}>
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Settings returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/opds')]
	public function get(): DataResponse {
		return new DataResponse($this->state($this->uid()));
	}

	/**
	 * Switch the OPDS catalog on or off
	 *
	 * @param bool|null $enabled Catalog for the current user
	 * @param bool|null $allowed Catalog allowed on this instance (administrators only)
	 * @return DataResponse<Http::STATUS_OK, array{enabled: bool, allowed: bool, isAdmin: bool, url: string}, array{}>
	 * @throws OCSForbiddenException Not logged in or not an administrator
	 *
	 * 200: Settings stored
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/opds')]
	public function put(?bool $enabled = null, ?bool $allowed = null): DataResponse {
		$userId = $this->uid();
		if ($allowed !== null) {
			if (!$this->groups->isAdmin($userId)) {
				throw new OCSForbiddenException('Only administrators can change this');
			}
			$this->settings->setAllowed($allowed);
		}
		if ($enabled !== null) {
			$this->settings->setEnabledForUser($userId, $enabled);
		}
		return new DataResponse($this->state($userId));
	}

	/** @return array{enabled: bool, allowed: bool, isAdmin: bool, url: string} */
	private function state(string $userId): array {
		return [
			'enabled' => $this->settings->isEnabledForUser($userId),
			'allowed' => $this->settings->isAllowed(),
			'isAdmin' => $this->groups->isAdmin($userId),
			'url' => $this->settings->feedUrl(),
		];
	}
}
