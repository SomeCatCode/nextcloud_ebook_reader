<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Http;

use OCA\EbookReader\AppInfo\Application;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Base class of the OCS controllers: app name + current user id handling.
 */
abstract class AbstractOCSController extends OCSController {
	public function __construct(
		IRequest $request,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** @throws OCSForbiddenException */
	protected function uid(): string {
		if ($this->userId === null || $this->userId === '') {
			throw new OCSForbiddenException('Not logged in');
		}
		return $this->userId;
	}
}
