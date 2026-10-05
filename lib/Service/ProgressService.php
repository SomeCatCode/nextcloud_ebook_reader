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
	 * An accepted write also derives the book's read status (see updateReadStatus).
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
	 * Couples a read status set by hand with the reading progress: finished = 100 %, unread = 0 %
	 * (start of the book), reading leaves the progress alone.
	 *
	 * The written row gets clientUpdatedAt = now (at least one ms after the stored value) so it wins the
	 * "last writer wins" merge of all clients, and updatedAt = now so /sync delivers it. The locator only
	 * keeps the overall position (href '' like after an edit removed the item); readers fall back to
	 * totalProgression. Unread without a stored row needs no row: no row means 0 %.
	 *
	 * @return Progress|null the stored progress after the change (null = none)
	 */
	public function applyReadStatus(string $userId, int $fileId, string $status): ?Progress {
		$existing = $this->get($userId, $fileId);
		if ($status === Book::STATUS_FINISHED) {
			$percentage = 1.0;
			$locator = ['href' => '', 'locations' => ['totalProgression' => 1]];
		} elseif ($status === Book::STATUS_UNREAD) {
			if ($existing === null) {
				return null;
			}
			$percentage = 0.0;
			$locator = ['href' => '', 'locations' => ['position' => 1, 'totalProgression' => 0]];
		} else {
			return $existing;
		}
		if ($existing !== null && $existing->getPercentage() === $percentage) {
			return $existing;
		}

		$now = $this->nowMs();
		$json = json_encode($locator, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		if ($existing === null) {
			$row = new Progress();
			$row->setUserId($userId);
			$row->setFileId($fileId);
			$this->fill($row, $json, $percentage, null, $now, $now);
			try {
				return $this->progressMapper->insert($row);
			} catch (DbException $e) {
				// concurrent insert (unique user/file): update that row instead
				$existing = $this->get($userId, $fileId);
				if ($existing === null) {
					throw $e;
				}
			}
		}
		$this->fill($existing, $json, $percentage, null, max($now, $existing->getClientUpdatedAt() + 1), $now);
		return $this->progressMapper->update($existing);
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
		if (!is_string($href)) {
			throw new \InvalidArgumentException('locator.href must be a string');
		}
		// href '' = only the overall position is known (written by applyReadStatus and remapAfterEdit)
		$hasTotal = isset($locator['locations']) && is_array($locator['locations']) && isset($locator['locations']['totalProgression']);
		if ($href === '' && !$hasTotal) {
			throw new \InvalidArgumentException('locator.href must be a non-empty string unless locations.totalProgression is set');
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

	/**
	 * Derives the read status from an accepted progress write: >= FINISHED_THRESHOLD finished,
	 * 0 unread, anything else reading. The write also ends a status set by hand (readStatusManual),
	 * with one exception: a hand-set "reading" survives a 0 % write (e.g. opening the book at its
	 * first page), so it is not reset to unread right away.
	 */
	private function updateReadStatus(string $userId, int $fileId, float $percentage, int $now): void {
		try {
			$book = $this->bookMapper->findByUserAndFile($userId, $fileId);
		} catch (DoesNotExistException) {
			return;
		}
		$manual = $book->getReadStatusManual();
		if ($percentage >= self::FINISHED_THRESHOLD) {
			$status = Book::STATUS_FINISHED;
		} elseif ($percentage > 0.0) {
			$status = Book::STATUS_READING;
		} elseif ($manual && $book->getReadStatus() === Book::STATUS_READING) {
			return;
		} else {
			$status = Book::STATUS_UNREAD;
		}
		if ($status === $book->getReadStatus() && !$manual) {
			return;
		}
		if ($status !== $book->getReadStatus()) {
			$book->setReadStatus($status);
			// only the status is part of the API: clearing the flag alone does not need a sync
			$book->setUpdatedAt($now);
		}
		$book->setReadStatusManual(false);
		$this->bookMapper->update($book);
	}

	private function nowMs(): int {
		return (int)$this->time->now()->format('Uv');
	}
}
