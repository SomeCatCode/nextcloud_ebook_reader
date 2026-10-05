<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Why the app shares a file with a user: directly (shelfShareId 0) or because the file is on a shared shelf.
 * Several rows (reasons) can point at the same Nextcloud share; it is deleted when the last one goes.
 *
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method string getRecipientId()
 * @method void setRecipientId(string $recipientId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method int getShelfShareId()
 * @method void setShelfShareId(int $shelfShareId)
 * @method string|null getShareId()
 * @method void setShareId(?string $shareId)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 */
class FileShare extends Entity {
	use MarksFieldsOnCreate;

	public const DIRECT = 0;

	protected string $ownerId = '';
	protected string $recipientId = '';
	protected int $fileId = 0;
	protected int $shelfShareId = self::DIRECT;
	/** full id of the Nextcloud share created by the app; null = existing share of the user (never deleted by the app) */
	protected ?string $shareId = null;
	protected int $createdAt = 0;

	public function __construct() {
		$this->markAllFieldsUpdated();
		$this->addType('fileId', 'integer');
		$this->addType('shelfShareId', 'integer');
		$this->addType('createdAt', 'integer');
	}

	public function isDirect(): bool {
		return $this->shelfShareId === self::DIRECT;
	}
}
