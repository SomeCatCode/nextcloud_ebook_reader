<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCP\Files\Node;
use OCP\Files\Storage\ISharedStorage;

/**
 * Whether a node belongs to the requesting user or only reached them through a native Nextcloud share.
 * Destructive operations (delete, move, rename) on shared nodes would change the owner's files, so the app refuses them.
 */
final class FileOwnership {
	/** The node lives on a share mount (a file or folder shared by another user). */
	public static function isShared(Node $node): bool {
		return (bool)$node->getStorage()->instanceOfStorage(ISharedStorage::class);
	}

	/** Nextcloud user id of the owner of a shared node, null when it is not shared (or the owner is unknown). */
	public static function shareOwner(Node $node): ?string {
		$storage = $node->getStorage();
		if (!$storage->instanceOfStorage(ISharedStorage::class)) {
			return null;
		}
		/** @var ISharedStorage $storage */
		$owner = $storage->getShare()->getShareOwner();
		return $owner !== '' ? $owner : null;
	}

	/** Mount root of a share (the shared folder/file itself): never to be removed or moved by the recipient. */
	public static function isShareRoot(Node $node): bool {
		return self::isShared($node) && $node->getInternalPath() === '';
	}
}
