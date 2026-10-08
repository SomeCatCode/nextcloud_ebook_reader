<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\Db\BookMapper;
use OCP\Files\Config\IUserMountCache;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * The folder tree of the user's library: every folder that holds at least one of the user's books, plus its ancestors
 * as long as they still lie inside a library folder or an incoming share. Computed from the books table (paths only).
 */
class FolderService {
	public function __construct(
		private BookMapper $books,
		private LibraryService $library,
		private IUserMountCache $mounts,
		private IUserManager $users,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Flat list sorted by path (parents before their children, siblings in natural order).
	 *
	 * @return list<array{path: string, name: string, parent: ?string, bookCount: int, totalCount: int, sharedWith: int, shared: bool}>
	 */
	public function listFolders(string $userId): array {
		$shareRoots = $this->incomingShareRoots($userId);
		$direct = [];
		foreach ($this->books->findPathsByUser($userId) as $path) {
			$dir = self::parentOf($path);
			if ($dir === '/' || self::isMetaPath($dir)) {
				continue; // books in the home root itself have no folder entry; .meta never holds books
			}
			$direct[$dir] = ($direct[$dir] ?? 0) + 1;
		}

		/** @var array<string, array{direct: int, total: int}> $tree */
		$tree = [];
		$inLibrary = [];
		foreach ($direct as $dir => $count) {
			$chain = $dir;
			$first = true;
			while ($chain !== '/' && $chain !== '') {
				// the folder with the books is always listed; ancestors only inside a library folder / incoming share
				if (!$first && !($inLibrary[$chain] ??= $this->library->isPathInLibrary($userId, $chain)) && !self::inside($chain, $shareRoots)) {
					break;
				}
				$tree[$chain] ??= ['direct' => 0, 'total' => 0];
				$tree[$chain]['total'] += $count;
				if ($first) {
					$tree[$chain]['direct'] += $count;
				}
				$first = false;
				$chain = self::parentOf($chain);
			}
		}

		$sharedWith = $this->folderShareCounts($userId);
		$out = [];
		foreach ($tree as $path => $counts) {
			$parent = self::parentOf($path);
			$out[] = [
				'path' => $path,
				'name' => basename($path),
				'parent' => isset($tree[$parent]) ? $parent : null,
				'bookCount' => $counts['direct'],
				'totalCount' => $counts['total'],
				'sharedWith' => $sharedWith[$path] ?? 0,
				'shared' => self::inside($path, $shareRoots),
			];
		}
		usort($out, static fn (array $a, array $b): int => self::compare($a['path'], $b['path']));
		return $out;
	}

	/**
	 * Number of users each own folder is shared with through the app (folder shares).
	 *
	 * HOOK for the folder sharing feature: ShareService::folderShareCounts($owner) will return this map
	 * (array<string path, int>). Until it is wired in, no folder counts as shared.
	 *
	 * @return array<string, int>
	 */
	protected function folderShareCounts(string $userId): array {
		return [];
	}

	/**
	 * User-relative mount points of the folders other users shared with this user (e.g. "/Shared/Comics").
	 *
	 * @return list<string>
	 */
	private function incomingShareRoots(string $userId): array {
		$user = $this->users->get($userId);
		if ($user === null) {
			return [];
		}
		$prefix = '/' . $userId . '/files';
		$roots = [];
		try {
			foreach ($this->mounts->getMountsForUser($user) as $mount) {
				if (!str_contains($mount->getMountProvider(), 'Files_Sharing')) {
					continue;
				}
				$point = rtrim($mount->getMountPoint(), '/');
				if (str_starts_with($point, $prefix . '/')) {
					$roots[] = substr($point, strlen($prefix));
				}
			}
		} catch (\Throwable $e) {
			$this->logger->info('Share mounts could not be read: ' . $e->getMessage(), ['app' => 'ebookreader']);
		}
		return $roots;
	}

	/** Whether a folder is a share mount or lies inside one. @param list<string> $roots */
	private static function inside(string $path, array $roots): bool {
		foreach ($roots as $root) {
			if ($path === $root || str_starts_with($path, $root . '/')) {
				return true;
			}
		}
		return false;
	}

	private static function parentOf(string $path): string {
		$pos = strrpos($path, '/');
		return $pos === false || $pos === 0 ? '/' : substr($path, 0, $pos);
	}

	/** Whether the path is a ".meta" folder (the sidecar folder) or lies in one. */
	private static function isMetaPath(string $path): bool {
		return in_array('.meta', explode('/', $path), true);
	}

	private static function compare(string $a, string $b): int {
		$sa = explode('/', $a);
		$sb = explode('/', $b);
		$n = min(count($sa), count($sb));
		for ($i = 0; $i < $n; $i++) {
			$c = strnatcasecmp($sa[$i], $sb[$i]);
			if ($c !== 0) {
				return $c;
			}
		}
		return count($sa) <=> count($sb);
	}
}
