<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Progress;
use OCA\EbookReader\Db\ProgressMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;

/** Owner: W2 */
class ProgressService {
	public const MAX_LOCATOR_BYTES = 4096;
	/** Client clocks more than this far ahead are clamped to the server time (ms). */
	public const MAX_FUTURE_SKEW_MS = 300000;
	public const FINISHED_THRESHOLD = 0.98;

	public function __construct(
		private ProgressMapper $progressMapper,
		private BookMapper $bookMapper,
		private ITimeFactory $time,
	) {
	}

	public function get(string $userId, int $fileId): ?Progress {
		try {
			return $this->progressMapper->findByUserAndFile($userId, $fileId);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Last writer wins on clientUpdatedAt (ms). Tie: higher percentage wins.
	 *
	 * @param array<string, mixed> $locator
	 * @return array{status: string, progress: Progress} status = ok|conflict
	 * @throws \InvalidArgumentException on an invalid locator/percentage
	 */
	public function put(string $userId, int $fileId, array $locator, float $percentage, ?string $device, int $clientUpdatedAt): array {
		$this->validateLocator($locator);
		if (!is_finite($percentage) || $percentage < 0.0 || $percentage > 1.0) {
			throw new \InvalidArgumentException('percentage must be between 0 and 1');
		}
		if ($clientUpdatedAt < 0) {
			throw new \InvalidArgumentException('clientUpdatedAt must not be negative');
		}
		if ($device !== null) {
			$device = mb_substr($device, 0, 64);
		}

		$now = $this->nowMs();
		if ($clientUpdatedAt > $now + self::MAX_FUTURE_SKEW_MS) {
			$clientUpdatedAt = $now;
		}
		$json = json_encode($locator, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		$existing = $this->get($userId, $fileId);
		if ($existing === null) {
			$row = new Progress();
			$row->setUserId($userId);
			$row->setFileId($fileId);
			$this->fill($row, $json, $percentage, $device, $clientUpdatedAt, $now);
			try {
				$this->progressMapper->insert($row);
				$this->updateReadStatus($userId, $fileId, $percentage, $now);
				return ['status' => 'ok', 'progress' => $row];
			} catch (DbException $e) {
				// concurrent insert (unique user/file): fall through to update path
				$existing = $this->get($userId, $fileId);
				if ($existing === null) {
					throw $e;
				}
			}
		}

		if ($existing->getClientUpdatedAt() > $clientUpdatedAt
			|| ($existing->getClientUpdatedAt() === $clientUpdatedAt && $existing->getPercentage() > $percentage)) {
			return ['status' => 'conflict', 'progress' => $existing];
		}

		$this->fill($existing, $json, $percentage, $device, $clientUpdatedAt, $now);
		$this->progressMapper->update($existing);
		$this->updateReadStatus($userId, $fileId, $percentage, $now);
		return ['status' => 'ok', 'progress' => $existing];
	}

	/**
	 * Rewrites stored locators of ALL users after an edit changed item hrefs.
	 *
	 * @param array<string, ?string> $itemMap old href => new href|null (null = item removed)
	 */
	public function remapAfterEdit(int $fileId, array $itemMap): void {
		if ($itemMap === []) {
			return;
		}
		$now = $this->nowMs();
		foreach ($this->progressMapper->findByFileId($fileId) as $row) {
			$locator = $row->getLocatorArray();
			$href = $locator['href'] ?? null;
			if (!is_string($href)) {
				continue;
			}
			$path = explode('#', $href, 2)[0];
			if (!array_key_exists($path, $itemMap)) {
				continue;
			}
			$new = $itemMap[$path];
			if ($new === $path) {
				continue;
			}
			$locations = isset($locator['locations']) && is_array($locator['locations']) ? $locator['locations'] : [];
			unset($locations['cfi']);
			if ($new === null) {
				// item removed: keep only the overall position
				$locator['href'] = '';
				$locations = array_intersect_key($locations, ['totalProgression' => true]);
			} else {
				$locator['href'] = $new;
			}
			$locator['locations'] = $locations;
			$row->setLocator(json_encode($locator, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
			$row->setUpdatedAt($now);
			$this->progressMapper->update($row);
		}
	}

	/**
	 * @param array<array-key, mixed> $locator
	 * @throws \InvalidArgumentException
	 */
	public function validateLocator(array $locator): void {
		$href = $locator['href'] ?? null;
		if (!is_string($href) || $href === '') {
			throw new \InvalidArgumentException('locator.href must be a non-empty string');
		}
		foreach (['type', 'title'] as $key) {
			if (isset($locator[$key]) && !is_string($locator[$key])) {
				throw new \InvalidArgumentException("locator.$key must be a string");
			}
		}
		if (isset($locator['locations'])) {
			$loc = $locator['locations'];
			if (!is_array($loc) || ($loc !== [] && array_is_list($loc))) {
				throw new \InvalidArgumentException('locator.locations must be an object');
			}
			foreach (['progression', 'totalProgression'] as $key) {
				if (isset($loc[$key])) {
					if (!is_int($loc[$key]) && !is_float($loc[$key])) {
						throw new \InvalidArgumentException("locator.locations.$key must be a number");
					}
					if ($loc[$key] < 0 || $loc[$key] > 1) {
						throw new \InvalidArgumentException("locator.locations.$key must be between 0 and 1");
					}
				}
			}
			if (isset($loc['position']) && (!is_int($loc['position']) || $loc['position'] < 0)) {
				throw new \InvalidArgumentException('locator.locations.position must be a non-negative integer');
			}
			if (isset($loc['cfi']) && !is_string($loc['cfi'])) {
				throw new \InvalidArgumentException('locator.locations.cfi must be a string');
			}
		}
		try {
			$json = json_encode($locator, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new \InvalidArgumentException('locator is not valid JSON');
		}
		if (strlen($json) > self::MAX_LOCATOR_BYTES) {
			throw new \InvalidArgumentException('locator is too large');
		}
	}

	private function fill(Progress $row, string $json, float $percentage, ?string $device, int $clientUpdatedAt, int $now): void {
		$row->setLocator($json);
		$row->setPercentage($percentage);
		$row->setDevice($device);
		$row->setClientUpdatedAt($clientUpdatedAt);
		$row->setUpdatedAt($now);
	}

	private function updateReadStatus(string $userId, int $fileId, float $percentage, int $now): void {
		try {
			$book = $this->bookMapper->findByUserAndFile($userId, $fileId);
		} catch (DoesNotExistException) {
			return;
		}
		if ($book->getReadStatusManual()) {
			return;
		}
		$status = $percentage >= self::FINISHED_THRESHOLD
			? Book::STATUS_FINISHED
			: ($percentage > 0.0 ? Book::STATUS_READING : $book->getReadStatus());
		if ($status === $book->getReadStatus()) {
			return;
		}
		$book->setReadStatus($status);
		$book->setUpdatedAt($now);
		$this->bookMapper->update($book);
	}

	private function nowMs(): int {
		return (int)$this->time->now()->format('Uv');
	}
}
