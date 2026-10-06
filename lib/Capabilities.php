<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader;

use OCA\EbookReader\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;

class Capabilities implements ICapability {
	public function __construct(
		private IAppManager $appManager,
	) {
	}

	/**
	 * `version` is the installed app version (e.g. "0.8.0"), so clients can check which features the server
	 * offers and tell users when the server app is too old. Servers before 0.8.0 do not send it.
	 *
	 * @return array{ebookreader: array{version: string, apiVersion: int, apiStable: bool, formats: list<string>, editor: bool, annotations: bool, sharing: bool}}
	 */
	public function getCapabilities(): array {
		return [
			'ebookreader' => [
				'version' => $this->appManager->getAppVersion(Application::APP_ID),
				'apiVersion' => 1,
				'apiStable' => false,
				'formats' => ['epub', 'mobi', 'azw3', 'fb2', 'fbz', 'cbz', 'cbr', 'cb7', 'cbt'],
				'editor' => true,
				'annotations' => true,
				'sharing' => true,
			],
		];
	}
}
