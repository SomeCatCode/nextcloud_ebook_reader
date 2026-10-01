<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Long running edit/convert task (table ebookreader_tasks).
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method float getProgress()
 * @method void setProgress(float $progress)
 * @method string getStep()
 * @method void setStep(string $step)
 * @method string getRequest()
 * @method void setRequest(string $request)
 * @method string|null getResult()
 * @method void setResult(?string $result)
 * @method string|null getError()
 * @method void setError(?string $error)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $ts)
 */
class Task extends Entity {
	public const TYPE_EDIT = 'edit';
	public const TYPE_CONVERT = 'convert';
	public const STATUS_QUEUED = 'queued';
	public const STATUS_RUNNING = 'running';
	public const STATUS_DONE = 'done';
	public const STATUS_FAILED = 'failed';

	protected string $userId = '';
	protected int $fileId = 0;
	protected string $type = self::TYPE_EDIT;
	protected string $status = self::STATUS_QUEUED;
	protected float $progress = 0.0;
	protected string $step = '';
	/** JSON */
	protected string $request = '{}';
	/** JSON */
	protected ?string $result = null;
	protected ?string $error = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('progress', 'float');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/** @return array<string, mixed> */
	public function getRequestArray(): array {
		$decoded = json_decode($this->getRequest(), true);
		return is_array($decoded) ? $decoded : [];
	}

	/** @return array<string, mixed>|null */
	public function getResultArray(): ?array {
		$raw = $this->getResult();
		if ($raw === null || $raw === '') {
			return null;
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : null;
	}

	/** @return array{id: int, fileId: int, type: string, status: string, progress: float, step: string, result: ?array<string, mixed>, error: ?string, createdAt: int, updatedAt: int} */
	public function toApi(): array {
		return [
			'id' => (int)$this->getId(),
			'fileId' => $this->getFileId(),
			'type' => $this->getType(),
			'status' => $this->getStatus(),
			'progress' => $this->getProgress(),
			'step' => $this->getStep(),
			'result' => $this->getResultArray(),
			'error' => $this->getError(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
