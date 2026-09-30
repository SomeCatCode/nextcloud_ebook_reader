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
 * @method string getLocator()
 * @method void setLocator(string $locator)
 * @method float getPercentage()
 * @method void setPercentage(float $percentage)
 * @method string|null getDevice()
 * @method void setDevice(?string $device)
 * @method int getClientUpdatedAt()
 * @method void setClientUpdatedAt(int $ts)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $ts)
 */
class Progress extends Entity {
	protected string $userId = '';
	protected int $fileId = 0;
	/** JSON string (Readium-like locator) */
	protected string $locator = '{}';
	protected float $percentage = 0.0;
	protected ?string $device = null;
	protected int $clientUpdatedAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('percentage', 'float');
		$this->addType('clientUpdatedAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/** @return array<string, mixed> */
	public function getLocatorArray(): array {
		$decoded = json_decode($this->getLocator(), true);
		return is_array($decoded) ? $decoded : [];
	}

	/** @return array{fileId: int, locator: array<string, mixed>, percentage: float, device: ?string, clientUpdatedAt: int, updatedAt: int} */
	public function toApi(): array {
		return [
			'fileId' => $this->getFileId(),
			'locator' => $this->getLocatorArray(),
			'percentage' => $this->getPercentage(),
			'device' => $this->getDevice(),
			'clientUpdatedAt' => $this->getClientUpdatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
