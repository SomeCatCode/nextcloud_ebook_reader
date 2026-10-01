<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\AppInfo\Application;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Local copies of archives (EPUB, CBZ, ...) that live on storages PHP cannot open directly (WebDAV, SMB, S3,
 * encryption): copied at most once per file version instead of once per request.
 *
 * - Local storage without encryption: the original path is returned, nothing is copied.
 * - Otherwise `<tempBaseDir>/ebookreader-cache/<fileId>-<etag>.<ext>` is created atomically (temp + rename) under an
 *   exclusive flock on a lock file per entry. A returned path stays protected by a shared flock until release(),
 *   so cleanup never deletes a file that is in use.
 * - Entries of other etags of the same file id are removed right away.
 * - LRU limit `archive_cache_mb` (default 2048): the oldest entries (by mtime, touched on every use) go first.
 */
class ArchiveCache {
	public const CONFIG_CACHE_MB = 'archive_cache_mb';
	public const DEFAULT_CACHE_MB = 2048;
	public const DIR = 'ebookreader-cache';
	/** Encrypted storage must always be read through the file API (the local file holds ciphertext). */
	private const ENCRYPTION_WRAPPER = '\\OC\\Files\\Storage\\Wrapper\\' . 'Encryption';
	/** Orphaned *.part files (crashed copies) older than this are removed */
	private const PART_MAX_AGE = 3600;

	/**
	 * path => [shared lock handle, use count]. Static: every instance in the process must see the paths that are in use.
	 * @var array<string, array{0: resource, 1: int}>
	 */
	private static array $held = [];
	private ?string $baseDirOverride = null;

	public function __construct(
		private ITempManager $tempManager,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/** Test hook: use another directory instead of `<tempBaseDir>/ebookreader-cache`. */
	public function useDirectory(string $dir): void {
		$this->baseDirOverride = $dir;
	}

	/**
	 * Local path with the content of the file. Callers must not modify or delete it and should call release() afterwards.
	 *
	 * @throws \RuntimeException if the file cannot be read or copied
	 */
	public function localPath(File $file): string {
		$local = $this->directPath($file);
		if ($local !== null) {
			return $local;
		}
		$dir = $this->directory();
		$fileId = (int)$file->getId();
		$etag = self::safeEtag((string)$file->getEtag());
		$ext = self::safeExt($file->getName());
		$final = $dir . '/' . $fileId . '-' . $etag . '.' . $ext;
		if (isset(self::$held[$final])) {
			self::$held[$final][1]++;
			@touch($final);
			return $final;
		}

		$lock = @fopen($final . '.lock', 'cb');
		if ($lock === false) {
			throw new \RuntimeException('Cannot create the archive cache lock');
		}
		try {
			$expected = (int)$file->getSize();
			$valid = static fn (): bool => is_file($final) && ($expected <= 0 || filesize($final) === $expected);
			// A shared lock only waits while another process is copying this entry
			if (!flock($lock, LOCK_SH)) {
				throw new \RuntimeException('Cannot lock the archive cache entry');
			}
			if (!$valid()) {
				if (!flock($lock, LOCK_EX)) {
					throw new \RuntimeException('Cannot lock the archive cache entry');
				}
				if (!$valid()) {
					$this->copy($file, $final);
				}
				// back to a shared lock for the time the caller uses the path (blocks cleanup, not other readers)
				flock($lock, LOCK_SH);
			}
			@touch($final);
		} catch (\Throwable $e) {
			fclose($lock);
			throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
		}
		self::$held[$final] = [$lock, 1];

		$this->removeOtherVersions($dir, $fileId, basename($final));
		try {
			$this->enforceLimit($dir, $final);
		} catch (\Throwable $e) {
			$this->logger->debug('Archive cache cleanup failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
		return $final;
	}

	/** Releases the use of one path returned by localPath() (or of all of them). Never deletes anything. */
	public function release(?string $path = null): void {
		if ($path !== null) {
			if (isset(self::$held[$path])) {
				if (--self::$held[$path][1] > 0) {
					return;
				}
				@flock(self::$held[$path][0], LOCK_UN);
				@fclose(self::$held[$path][0]);
				unset(self::$held[$path]);
			}
			return;
		}
		foreach (array_keys(self::$held) as $p) {
			self::$held[$p][1] = 1;
			$this->release($p);
		}
	}

	/** Whether a copy of this file would fit into the cache limit at all. */
	public function fits(File $file): bool {
		return (int)$file->getSize() <= $this->limitBytes();
	}

	/** True if the original path is used (no copy needed). */
	public function isDirect(File $file): bool {
		return $this->directPath($file) !== null;
	}

	/**
	 * Removes the oldest entries until the cache is below its limit, plus stale partial copies. Used by
	 * CleanupTombstonesJob.
	 *
	 * @return int bytes freed
	 */
	public function cleanup(): int {
		$dir = $this->directory(false);
		if ($dir === null) {
			return 0;
		}
		return $this->enforceLimit($dir, null);
	}

	// ---------------------------------------------------------------------------------------------

	private function directPath(File $file): ?string {
		$storage = $file->getStorage();
		if ($storage->isLocal() && !$storage->instanceOfStorage(self::ENCRYPTION_WRAPPER)) {
			$local = $storage->getLocalFile($file->getInternalPath());
			if (is_string($local) && $local !== '' && is_file($local)) {
				return $local;
			}
		}
		return null;
	}

	private function directory(bool $create = true): ?string {
		$dir = $this->baseDirOverride ?? rtrim((string)$this->tempManager->getTempBaseDir(), '/\\') . '/' . self::DIR;
		if (!is_dir($dir)) {
			if (!$create) {
				return null;
			}
			if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
				throw new \RuntimeException('Cannot create the archive cache directory');
			}
		}
		return $dir;
	}

	private function copy(File $file, string $final): void {
		$part = $final . '.part.' . bin2hex(random_bytes(6));
		$in = $file->fopen('r');
		$out = @fopen($part, 'wb');
		if ($in === false || $out === false) {
			if (is_resource($in)) {
				fclose($in);
			}
			if (is_resource($out)) {
				fclose($out);
			}
			@unlink($part);
			throw new \RuntimeException('Cannot read the file');
		}
		try {
			$ok = stream_copy_to_stream($in, $out) !== false;
		} finally {
			fclose($in);
			$flushed = fclose($out);
		}
		if (!$ok || !$flushed) {
			@unlink($part);
			throw new \RuntimeException('Cannot copy the file into the archive cache');
		}
		if (!@rename($part, $final)) {
			// Windows cannot rename over an existing file
			@unlink($final);
			if (!@rename($part, $final)) {
				@unlink($part);
				throw new \RuntimeException('Cannot store the file in the archive cache');
			}
		}
	}

	private function removeOtherVersions(string $dir, int $fileId, string $keepName): void {
		$prefix = $fileId . '-';
		$names = @scandir($dir);
		if ($names === false) {
			return;
		}
		foreach ($names as $name) {
			if ($name === $keepName || !str_starts_with($name, $prefix) || str_ends_with($name, '.lock')
				|| preg_match('/^' . $fileId . '-[A-Za-z0-9]+\.[a-z0-9]+$/', $name) !== 1) {
				continue;
			}
			$this->deleteEntry($dir . '/' . $name);
		}
	}

	/** Deletes an entry (and its lock file) unless somebody currently uses it. */
	private function deleteEntry(string $path): bool {
		if (isset(self::$held[$path])) {
			return false;
		}
		$lock = @fopen($path . '.lock', 'cb');
		if ($lock === false) {
			return false;
		}
		try {
			if (!flock($lock, LOCK_EX | LOCK_NB)) {
				return false; // in use
			}
			$ok = !is_file($path) || @unlink($path);
			if ($ok) {
				@unlink($path . '.lock');
			}
			return $ok;
		} finally {
			fclose($lock);
		}
	}

	private function limitBytes(): int {
		$mb = $this->appConfig->getValueInt(Application::APP_ID, self::CONFIG_CACHE_MB, self::DEFAULT_CACHE_MB);
		return max(1, $mb) * 1024 * 1024;
	}

	/**
	 * @param ?string $keep entry that is never evicted (the one being returned)
	 * @return int bytes freed
	 */
	private function enforceLimit(string $dir, ?string $keep): int {
		$names = @scandir($dir);
		if ($names === false) {
			return 0;
		}
		$freed = 0;
		$entries = [];
		$total = 0;
		$now = time();
		foreach ($names as $name) {
			if ($name === '.' || $name === '..') {
				continue;
			}
			if (str_ends_with($name, '.lock')) {
				$entry = $dir . '/' . substr($name, 0, -5);
				if (!is_file($entry) && $now - (int)@filemtime($dir . '/' . $name) > self::PART_MAX_AGE && !isset(self::$held[$entry])) {
					@unlink($dir . '/' . $name);
				}
				continue;
			}
			$path = $dir . '/' . $name;
			if (!is_file($path)) {
				continue;
			}
			$mtime = (int)@filemtime($path);
			$size = (int)@filesize($path);
			if (str_contains($name, '.part.')) {
				if ($now - $mtime > self::PART_MAX_AGE && @unlink($path)) {
					$freed += $size;
				}
				continue;
			}
			$total += $size;
			if ($path !== $keep) {
				$entries[] = ['path' => $path, 'mtime' => max($mtime, (int)@fileatime($path)), 'size' => $size];
			}
		}
		$limit = $this->limitBytes();
		if ($total <= $limit) {
			return $freed;
		}
		usort($entries, static fn (array $a, array $b): int => $a['mtime'] <=> $b['mtime']);
		foreach ($entries as $e) {
			if ($total <= $limit) {
				break;
			}
			if ($this->deleteEntry($e['path'])) {
				$total -= $e['size'];
				$freed += $e['size'];
			}
		}
		return $freed;
	}

	private static function safeEtag(string $etag): string {
		$clean = preg_replace('/[^A-Za-z0-9]/', '', $etag) ?? '';
		return $clean !== '' ? substr($clean, 0, 64) : '0';
	}

	private static function safeExt(string $name): string {
		$e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		return preg_match('/^[a-z0-9]{1,5}$/', $e) === 1 ? $e : 'bin';
	}
}
