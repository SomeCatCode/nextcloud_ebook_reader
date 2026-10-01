<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getShelfId()
 * @method void setShelfId(int $shelfId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method int getPosition()
 * @method void setPosition(int $position)
 * @method int getAddedAt()
 * @method void setAddedAt(int $ts)
 */
class ShelfBook extends Entity {
	protected int $shelfId = 0;
	protected int $fileId = 0;
	protected int $position = 0;
	protected int $addedAt = 0;

	public function __construct() {
		$this->addType('shelfId', 'integer');
		$this->addType('fileId', 'integer');
		$this->addType('position', 'integer');
		$this->addType('addedAt', 'integer');
	}
}
