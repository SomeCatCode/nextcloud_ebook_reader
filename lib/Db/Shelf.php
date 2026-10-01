<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getType()
 * @method void setType(string $type)
 * @method string|null getQuery()
 * @method void setQuery(?string $query)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $ts)
 */
class Shelf extends Entity {
	use MarksFieldsOnCreate;

	public const TYPE_MANUAL = 'manual';
	public const TYPE_SMART = 'smart';

	protected string $userId = '';
	protected string $name = '';
	protected string $type = self::TYPE_MANUAL;
	/** JSON (smart shelves only) */
	protected ?string $query = null;
	protected int $sortOrder = 0;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->markAllFieldsUpdated();
		$this->addType('sortOrder', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function isSmart(): bool {
		return $this->type === self::TYPE_SMART;
	}

	/** @return array<string, mixed>|null decoded query of a smart shelf */
	public function getQueryArray(): ?array {
		$raw = $this->query;
		if ($raw === null || $raw === '') {
			return null;
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : null;
	}
}
