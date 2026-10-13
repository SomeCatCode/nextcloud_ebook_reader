<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCP\App\IAppManager;
use OCP\Files\IFilenameValidator;
use OCP\Share\IShare;
use OCP\User\Events\UserDeletedEvent;

/**
 * Guarded shims for server APIs that were added after Nextcloud 31 (the oldest supported version).
 * Each helper uses the newer API when the running server has it and falls back to the 31 behaviour otherwise.
 * Remove the helper (and call the API directly) once the minimum supported version reaches the API's "@since".
 */
final class Compat {
	/** IAppManager::isEnabledForAnyone() exists since 32; before, isInstalled() meant "enabled". */
	public static function isAppEnabledForAnyone(IAppManager $appManager, string $appId): bool {
		if (method_exists($appManager, 'isEnabledForAnyone')) {
			return $appManager->isEnabledForAnyone($appId);
		}
		return $appManager->isInstalled($appId);
	}

	/**
	 * IFilenameValidator::sanitizeFilename() exists since 32. Older servers return the name unchanged: callers already
	 * stripped forbidden characters themselves and validate the result with validateFilename() afterwards.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function sanitizeFilename(IFilenameValidator $validator, string $name): string {
		if (method_exists($validator, 'sanitizeFilename')) {
			return $validator->sanitizeFilename($name);
		}
		return $name;
	}

	/**
	 * IShare::canSeeContent() exists since 32 (adds the admin setting "view without download"). Before, the share's
	 * download attribute (checked by the caller) was the only restriction.
	 */
	public static function canSeeShareContent(IShare $share): bool {
		if (method_exists($share, 'canSeeContent')) {
			return $share->canSeeContent();
		}
		return true;
	}
}
