<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;

/**
 * OPDS switches: the admin allows the feature for the whole instance (app config `opds_enabled`, default allowed),
 * every user enables it for themselves (user config `opds_enabled`, default off).
 */
class OpdsSettings {
	public const KEY = 'opds_enabled';

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
	) {
	}

	/** Allowed by the administrator (default true). */
	public function isAllowed(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY, true);
	}

	public function setAllowed(bool $allowed): void {
		$this->appConfig->setValueBool(Application::APP_ID, self::KEY, $allowed);
	}

	/** Switched on by the user themselves (default false). */
	public function isEnabledForUser(string $userId): bool {
		return $this->config->getUserValue($userId, Application::APP_ID, self::KEY, '0') === '1';
	}

	public function setEnabledForUser(string $userId, bool $enabled): void {
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY, $enabled ? '1' : '0');
	}

	/** True if the catalog may be served to the user: allowed globally and enabled by the user. */
	public function isActiveFor(string $userId): bool {
		return $this->isAllowed() && $this->isEnabledForUser($userId);
	}

	/** Absolute URL of the catalog root. */
	public function feedUrl(): string {
		return $this->urlGenerator->linkToRouteAbsolute('ebookreader.opds.index');
	}
}
