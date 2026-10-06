<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A shelf shared live (read-only) with another user.
 *
 * @method int getShelfId()
 * @method void setShelfId(int $shelfId)
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method string getRecipientId()
 * @method void setRecipientId(string $recipientId)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 * @method int getSyncedAt()
 * @method void setSyncedAt(int $ts)
 */
class ShelfShare extends Entity {
	use MarksFieldsOnCreate;

	protected int $shelfId = 0;
	protected string $ownerId = '';
	protected string $recipientId = '';
	protected int $createdAt = 0;
	protected int $syncedAt = 0;

	public function __construct() {
		$this->markAllFieldsUpdated();
		$this->addType('shelfId', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('syncedAt', 'integer');
	}
}
