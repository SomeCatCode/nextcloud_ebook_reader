<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A folder of the owner's library shared with a user through the app: ONE read-only Nextcloud user share of the folder.
 * The folder is identified by its file id (it survives moves and renames); `path` is the last known path.
 *
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method string getRecipientId()
 * @method void setRecipientId(string $recipientId)
 * @method int getFolderId()
 * @method void setFolderId(int $folderId)
 * @method string|null getPath()
 * @method void setPath(?string $path)
 * @method string|null getShareId()
 * @method void setShareId(?string $shareId)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 */
class FolderShare extends Entity {
	use MarksFieldsOnCreate;

	protected string $ownerId = '';
	protected string $recipientId = '';
	protected int $folderId = 0;
	protected ?string $path = null;
	/** full id of the Nextcloud share created by the app; null = existing share of the user (never deleted by the app) */
	protected ?string $shareId = null;
	protected int $createdAt = 0;

	public function __construct() {
		$this->markAllFieldsUpdated();
		$this->addType('folderId', 'integer');
		$this->addType('createdAt', 'integer');
	}
}
