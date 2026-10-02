<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\Db\Annotation;
use OCA\EbookReader\Db\AnnotationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;

/**
 * Highlights, notes and bookmarks of a user. Rows are never removed directly: a deletion sets a tombstone (deleted = 1)
 * so that other clients learn about it through the sync feed; CleanupTombstonesJob purges old tombstones.
 */
class AnnotationService {
	public const MAX_TEXT_CHARS = 2000;
	public const MAX_NOTE_CHARS = 10000;
	public const MAX_PER_BOOK = 5000;
	/** Client clocks more than this far ahead are clamped to the server time (ms). */
	public const MAX_FUTURE_SKEW_MS = 300000;
	public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	public function __construct(
		private AnnotationMapper $mapper,
		private ProgressService $progress,
		private ITimeFactory $time,
	) {
	}

	/** @return list<Annotation> */
	public function list(string $userId, int $fileId): array {
		return $this->mapper->findByUserAndFile($userId, $fileId);
	}

	public function find(string $userId, string $uuid): ?Annotation {
		try {
			return $this->mapper->findByUuid($userId, strtolower($uuid));
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Creates the annotation or updates the one with the same uuid (upsert). Last write wins on clientUpdatedAt;
	 * an older client write returns status "conflict" together with the stored row.
	 *
	 * @param array<array-key, mixed> $locator
	 * @return array{status: string, annotation: Annotation} status = ok|conflict
	 * @throws \InvalidArgumentException on invalid input
	 */
	public function upsert(string $userId, int $fileId, ?string $uuid, string $type, array $locator, ?string $text, ?string $note, ?string $color, ?int $clientUpdatedAt, ?int $createdAt = null): array {
		$uuid = $uuid === null || $uuid === '' ? $this->newUuid() : $this->normalizeUuid($uuid);
		if (!in_array($type, Annotation::TYPES, true)) {
			throw new \InvalidArgumentException('type must be highlight, note or bookmark');
		}
		$this->progress->validateLocator($locator);
		$text = $this->cleanText($text, self::MAX_TEXT_CHARS, 'text');
		$note = $this->cleanText($note, self::MAX_NOTE_CHARS, 'note');
		$color = $this->cleanColor($color);
		$now = $this->nowMs();
		$clientTs = $this->clientTs($clientUpdatedAt, $now);
		$json = json_encode($locator, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		$existing = $this->find($userId, $uuid);
		if ($existing === null) {
			if ($this->mapper->countLiveByUserAndFile($userId, $fileId) >= self::MAX_PER_BOOK) {
				throw new \InvalidArgumentException('too many annotations for this book');
			}
			$row = new Annotation();
			$row->setUserId($userId);
			$row->setFileId($fileId);
			$row->setUuid($uuid);
			$row->setCreatedAt($createdAt !== null && $createdAt > 0 ? min($createdAt, $now) : $now);
			$this->fill($row, $type, $json, $text, $note, $color, $clientTs, $now);
			try {
				$this->mapper->insert($row);
				return ['status' => 'ok', 'annotation' => $row];
			} catch (DbException $e) {
				// concurrent insert with the same uuid: fall through to the update path
				$existing = $this->find($userId, $uuid);
				if ($existing === null) {
					throw $e;
				}
			}
		}

		if ($existing->getFileId() !== $fileId) {
			throw new \InvalidArgumentException('uuid is already used for another book');
		}
		if ($existing->getClientUpdatedAt() > $clientTs) {
			return ['status' => 'conflict', 'annotation' => $existing];
		}
		$this->fill($existing, $type, $json, $text, $note, $color, $clientTs, $now);
		$this->mapper->update($existing);
		return ['status' => 'ok', 'annotation' => $existing];
	}

	/**
	 * Partial update. Null = leave unchanged; an empty string clears note and color.
	 *
	 * @param array<array-key, mixed>|null $locator
	 * @return array{status: string, annotation: Annotation}|null null when the annotation does not exist (or is deleted)
	 * @throws \InvalidArgumentException on invalid input
	 */
	public function patch(string $userId, string $uuid, ?array $locator, ?string $text, ?string $note, ?string $color, ?int $clientUpdatedAt): ?array {
		$row = $this->find($userId, $uuid);
		if ($row === null || $row->isDeleted()) {
			return null;
		}
		if ($locator !== null) {
			$this->progress->validateLocator($locator);
		}
		$text = $this->cleanText($text, self::MAX_TEXT_CHARS, 'text');
		$note = $this->cleanText($note, self::MAX_NOTE_CHARS, 'note');
		$color = $this->cleanColor($color);
		$now = $this->nowMs();
		$clientTs = $this->clientTs($clientUpdatedAt, $now);
		if ($row->getClientUpdatedAt() > $clientTs) {
			return ['status' => 'conflict', 'annotation' => $row];
		}
		if ($locator !== null) {
			$row->setLocator(json_encode($locator, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		}
		if ($text !== null) {
			$row->setText($text === '' ? null : $text);
		}
		if ($note !== null) {
			$row->setNote($note === '' ? null : $note);
		}
		if ($color !== null) {
			$row->setColor($color === '' ? null : $color);
		}
		$row->setClientUpdatedAt($clientTs);
		$row->setUpdatedAt($now);
		$this->mapper->update($row);
		return ['status' => 'ok', 'annotation' => $row];
	}

	/**
	 * Sets the tombstone (idempotent). An older client timestamp loses against a newer edit.
	 *
	 * @return array{status: string, annotation: Annotation}|null null when the annotation does not exist
	 */
	public function delete(string $userId, string $uuid, ?int $clientUpdatedAt): ?array {
		$row = $this->find($userId, $uuid);
		if ($row === null) {
			return null;
		}
		if ($row->isDeleted()) {
			return ['status' => 'ok', 'annotation' => $row];
		}
		$now = $this->nowMs();
		$clientTs = $this->clientTs($clientUpdatedAt, $now);
		if ($row->getClientUpdatedAt() > $clientTs) {
			return ['status' => 'conflict', 'annotation' => $row];
		}
		$row->setDeleted(1);
		$row->setClientUpdatedAt($clientTs);
		$row->setUpdatedAt($now);
		$this->mapper->update($row);
		return ['status' => 'ok', 'annotation' => $row];
	}

	private function fill(Annotation $row, string $type, string $json, ?string $text, ?string $note, ?string $color, int $clientTs, int $now): void {
		$row->setType($type);
		$row->setLocator($json);
		$row->setText($text === '' ? null : $text);
		$row->setNote($note === '' ? null : $note);
		$row->setColor($color === '' ? null : $color);
		$row->setDeleted(0);
		$row->setClientUpdatedAt($clientTs);
		$row->setUpdatedAt($now);
	}

	private function newUuid(): string {
		$b = random_bytes(16);
		$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
		$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
		$h = bin2hex($b);
		return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
	}

	/** @throws \InvalidArgumentException */
	private function normalizeUuid(string $uuid): string {
		$uuid = strtolower($uuid);
		if (preg_match(self::UUID_PATTERN, $uuid) !== 1) {
			throw new \InvalidArgumentException('uuid must be a UUID');
		}
		return $uuid;
	}

	/** @throws \InvalidArgumentException */
	private function cleanText(?string $value, int $max, string $field): ?string {
		if ($value === null) {
			return null;
		}
		if (mb_strlen($value) > $max) {
			throw new \InvalidArgumentException("$field must not be longer than $max characters");
		}
		return $value;
	}

	/**
	 * @return string|null null = not given, '' = clear
	 * @throws \InvalidArgumentException
	 */
	private function cleanColor(?string $color): ?string {
		if ($color === null || $color === '') {
			return $color;
		}
		if (!in_array($color, Annotation::COLORS, true)) {
			throw new \InvalidArgumentException('color must be one of ' . implode(', ', Annotation::COLORS));
		}
		return $color;
	}

	/** @throws \InvalidArgumentException */
	private function clientTs(?int $clientUpdatedAt, int $now): int {
		if ($clientUpdatedAt === null) {
			return $now;
		}
		if ($clientUpdatedAt < 0) {
			throw new \InvalidArgumentException('clientUpdatedAt must not be negative');
		}
		return $clientUpdatedAt > $now + self::MAX_FUTURE_SKEW_MS ? $now : $clientUpdatedAt;
	}

	private function nowMs(): int {
		return (int)$this->time->now()->format('Uv');
	}
}
