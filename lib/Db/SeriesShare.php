<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A series shared live (read-only) with another user: all of the owner's books with that series name.
 * Like a shelf share, the files shared for it are FileShare rows whose reason is FileShare::seriesReason(id).
 *
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method string getRecipientId()
 * @method void setRecipientId(string $recipientId)
 * @method string getSeries()
 * @method void setSeries(string $series)
 * @method string getSeriesKey()
 * @method void setSeriesKey(string $seriesKey)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 * @method int getSyncedAt()
 * @method void setSyncedAt(int $ts)
 */
class SeriesShare extends Entity {
	use MarksFieldsOnCreate;

	protected string $ownerId = '';
	protected string $recipientId = '';
	protected string $series = '';
	/** md5 of the lower-cased series name (names compare case-insensitively) */
	protected string $seriesKey = '';
	protected int $createdAt = 0;
	protected int $syncedAt = 0;

	public function __construct() {
		$this->markAllFieldsUpdated();
		$this->addType('createdAt', 'integer');
		$this->addType('syncedAt', 'integer');
	}

	public static function keyOf(string $series): string {
		return md5(mb_strtolower(trim($series)));
	}
}
