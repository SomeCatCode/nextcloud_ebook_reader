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
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string getLocator()
 * @method void setLocator(string $locator)
 * @method string|null getText()
 * @method void setText(?string $text)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method string|null getColor()
 * @method void setColor(?string $color)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $ts)
 * @method int getClientUpdatedAt()
 * @method void setClientUpdatedAt(int $ts)
 * @method int getDeleted()
 * @method void setDeleted(int $deleted) 0 = live, 1 = tombstone
 */
class Annotation extends Entity {
	use MarksFieldsOnCreate;

	public const TYPE_HIGHLIGHT = 'highlight';
	public const TYPE_NOTE = 'note';
	public const TYPE_BOOKMARK = 'bookmark';
	public const TYPES = [self::TYPE_HIGHLIGHT, self::TYPE_NOTE, self::TYPE_BOOKMARK];
	public const COLORS = ['yellow', 'green', 'blue', 'pink', 'purple'];

	protected string $userId = '';
	protected int $fileId = 0;
	protected string $type = self::TYPE_HIGHLIGHT;
	protected string $uuid = '';
	/** JSON string (Readium-like locator, same shape as the progress locator) */
	protected string $locator = '{}';
	protected ?string $text = null;
	protected ?string $note = null;
	protected ?string $color = null;
	/** server time, ms */
	protected int $createdAt = 0;
	/** server time, ms; drives the sync cursor */
	protected int $updatedAt = 0;
	/** client time of the last edit, ms; last write wins */
	protected int $clientUpdatedAt = 0;
	/** tombstone flag (smallint column: portable across databases) */
	protected int $deleted = 0;

	public function __construct() {
		$this->markAllFieldsUpdated();
		$this->addType('fileId', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
		$this->addType('clientUpdatedAt', 'integer');
		$this->addType('deleted', 'integer');
	}

	public function isDeleted(): bool {
		return $this->getDeleted() !== 0;
	}

	/** @return array<string, mixed> */
	public function getLocatorArray(): array {
		$decoded = json_decode($this->getLocator(), true);
		return is_array($decoded) ? $decoded : [];
	}

	/** @return array{uuid: string, fileId: int, type: string, locator: array<string, mixed>, text: ?string, note: ?string, color: ?string, createdAt: int, updatedAt: int, clientUpdatedAt: int, deleted: bool} */
	public function toApi(): array {
		return [
			'uuid' => $this->getUuid(),
			'fileId' => $this->getFileId(),
			'type' => $this->getType(),
			'locator' => $this->getLocatorArray(),
			'text' => $this->getText(),
			'note' => $this->getNote(),
			'color' => $this->getColor(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
			'clientUpdatedAt' => $this->getClientUpdatedAt(),
			'deleted' => $this->isDeleted(),
		];
	}
}
