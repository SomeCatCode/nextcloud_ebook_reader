<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\AppInfo\Application;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;

/**
 * Scaled comic pages in app data (`comic-pages`). The folder is outside every user quota, so its size is bounded:
 * - limit `comic_cache_mb` (default 2048): the oldest entries (by mtime) go first;
 * - the limit is checked after every TRIM_EVERY bytes written (counted in the distributed memcache; without one, at
 *   random about every TRIM_CHANCE writes) and once a day in CleanupTombstonesJob;
 * - entries older than the tombstone retention are removed by CleanupTombstonesJob.
 */
class ComicPageCache {
	public const CONFIG_CACHE_MB = 'comic_cache_mb';
	public const DEFAULT_CACHE_MB = 2048;
	public const FOLDER = 'comic-pages';
	private const TRIM_EVERY = 64 * 1024 * 1024;
	private const TRIM_CHANCE = 200;
	/** a trim removes entries until the cache is at this share (percent) of the limit, so it does not run on every write */
	private const TRIM_TARGET_PERCENT = 80;

	public function __construct(
		private IAppData $appData,
		private ICacheFactory $cacheFactory,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}

	/** Stores an entry and trims the cache when enough was written since the last check. */
	public function put(ISimpleFolder $folder, string $name, string $data): void {
		try {
			$folder->newFile($name, $data);
		} catch (\Throwable $e) {
			$this->logger->debug('Cannot cache comic page: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return;
		}
		if ($this->due(strlen($data))) {
			try {
				$this->trim($folder);
			} catch (\Throwable $e) {
				$this->logger->info('Comic page cache trim failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
	}

	/**
	 * Removes the oldest entries while the cache is above the limit.
	 *
	 * @return int bytes freed
	 */
	public function trim(?ISimpleFolder $folder = null): int {
		if ($folder === null) {
			try {
				$folder = $this->appData->getFolder(self::FOLDER);
			} catch (NotFoundException) {
				return 0;
			}
		}
		$limit = $this->limitBytes();
		$entries = [];
		$total = 0;
		foreach ($folder->getDirectoryListing() as $file) {
			$size = (int)$file->getSize();
			$total += $size;
			$entries[] = [$file, $file->getMTime(), $size];
		}
		if ($total <= $limit) {
			return 0;
		}
		usort($entries, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
		$target = intdiv($limit * self::TRIM_TARGET_PERCENT, 100);
		$freed = 0;
		foreach ($entries as [$file, , $size]) {
			if ($total - $freed <= $target) {
				break;
			}
			try {
				$file->delete();
				$freed += $size;
			} catch (\Throwable $e) {
				$this->logger->debug('Cannot remove cached comic page: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
		return $freed;
	}

	/** Removes entries older than the timestamp (seconds). */
	public function removeOlderThan(int $cutoff): void {
		try {
			$folder = $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return;
		}
		foreach ($folder->getDirectoryListing() as $file) {
			try {
				if ($file->getMTime() < $cutoff) {
					$file->delete();
				}
			} catch (\Throwable $e) {
				$this->logger->debug('Cannot remove cached comic page: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
	}

	public function limitBytes(): int {
		$mb = $this->appConfig->getValueInt(Application::APP_ID, self::CONFIG_CACHE_MB, self::DEFAULT_CACHE_MB);
		return max(1, $mb) * 1024 * 1024;
	}

	/** True when the limit should be checked now. */
	private function due(int $bytes): bool {
		$cache = $this->cacheFactory->createDistributed('ebookreader-comic');
		if (!$cache instanceof IMemcache) {
			return random_int(1, self::TRIM_CHANCE) === 1;
		}
		$written = $cache->inc('written', $bytes);
		if (!is_int($written) || $written < self::TRIM_EVERY) {
			return is_int($written) ? false : random_int(1, self::TRIM_CHANCE) === 1;
		}
		// only the request that resets the counter trims; the others go on writing
		return $cache->cas('written', $written, 0);
	}
}
