<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Tag;
use OCA\EbookReader\Db\TagMapper;
use OCA\EbookReader\Metadata\SidecarService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Renames and sorts books into folders by a pattern such as "{author}/{series}/{series_index:2} - {title}".
 *
 * @psalm-import-type EbookReaderOrganizeItem from \OCA\EbookReader\ResponseDefinitions
 */
class OrganizeService {
	public const MAX_FILES = 500;
	public const MAX_PATTERN_LENGTH = 500;
	private const MAX_SEGMENT_BYTES = 255;
	private const EMPTY = "\x01";

	public function __construct(
		private BookMapper $bookMapper,
		private TagMapper $tagMapper,
		private LibraryService $library,
		private RenameService $renamer,
		private SettingsService $settings,
		private IRootFolder $rootFolder,
		private IFilenameValidator $filenameValidator,
		private LoggerInterface $logger,
		private SidecarService $sidecar,
	) {
	}

	// ------------------------------------------------------------------ pattern rendering (pure)

	/**
	 * Renders a pattern to a user-relative path without leading slash, e.g. "Autor/Reihe/01 - Titel.epub".
	 * Slashes in the pattern create folders, slashes inside placeholder values never do.
	 *
	 * @param array<string, string> $vars placeholder values by name (author, authors, title, series, series_index, year, publisher, language, genre, format)
	 * @param string $extension extension including the dot (lower-cased here), may be empty
	 * @param string $fallbackName base name used when the file name part renders empty
	 */
	public function renderPath(string $pattern, array $vars, string $extension, string $fallbackName = ''): string {
		$extension = mb_strtolower($extension);
		$segments = explode('/', str_replace('\\', '/', $pattern));
		$fileSegment = (string)array_pop($segments);

		$parts = [];
		foreach ($segments as $segment) {
			$name = $this->sanitizeSegment($this->renderSegment($segment, $vars), self::MAX_SEGMENT_BYTES);
			if ($name !== '') {
				$parts[] = $name;
			}
		}

		$maxFile = self::MAX_SEGMENT_BYTES - strlen($extension);
		$file = $this->sanitizeSegment($this->renderSegment($fileSegment, $vars), $maxFile);
		if ($file === '') {
			$file = $this->sanitizeSegment($vars['title'] ?? '', $maxFile);
		}
		if ($file === '') {
			$file = $this->sanitizeSegment($fallbackName, $maxFile);
		}
		if ($file === '') {
			$file = 'Book';
		}
		$parts[] = $file . $extension;
		return implode('/', $parts);
	}

	/** @param array<string, string> $vars */
	private function renderSegment(string $segment, array $vars): string {
		$segment = str_replace(self::EMPTY, '', $segment);
		$out = (string)preg_replace_callback('/\{(\w+)(?::(\d+))?\}/u', function (array $m) use ($vars): string {
			$value = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $vars[$m[1]] ?? ''));
			if ($value === '') {
				return self::EMPTY;
			}
			if ($m[1] === 'series_index' && isset($m[2]) && $m[2] !== '') {
				$value = $this->padNumber($value, (int)$m[2]);
			}
			return $value;
		}, $segment);

		if (!str_contains($out, self::EMPTY)) {
			return $out;
		}
		$sep = '(?: - | \x{2013} |, |_)';
		$out = (string)preg_replace('/' . $sep . '\x01/u', '', $out);
		$out = (string)preg_replace('/\x01' . $sep . '/u', '', $out);
		$out = str_replace(self::EMPTY, '', $out);
		// brackets that became empty
		do {
			$before = $out;
			$out = (string)preg_replace('/\(\s*\)|\[\s*\]/u', '', $out);
		} while ($out !== $before);
		$out = (string)preg_replace('/\s+/u', ' ', $out);
		return trim($out, " \t-\u{2013}\u{2014}_,");
	}

	/** Zero-pads the integer part: ("1.5", 2) gives "01.5". */
	private function padNumber(string $value, int $width): string {
		$width = max(0, min(10, $width));
		$parts = explode('.', $value, 2);
		$int = ltrim($parts[0], '0');
		$int = str_pad($int === '' ? '0' : $int, $width, '0', STR_PAD_LEFT);
		return isset($parts[1]) && $parts[1] !== '' ? $int . '.' . $parts[1] : $int;
	}

	private function sanitizeSegment(string $name, int $maxBytes): string {
		$name = $this->renamer->sanitize($name);
		if ($name === '' || trim($name, '. ') === '') {
			return '';
		}
		try {
			$name = $this->filenameValidator->sanitizeFilename($name);
		} catch (\InvalidArgumentException) {
			return '';
		}
		if (strlen($name) > $maxBytes) {
			$name = rtrim(mb_strcut($name, 0, max(1, $maxBytes), 'UTF-8'), ' .');
		}
		return trim($name, '. ') === '' ? '' : $name;
	}

	/** @return array<string, string> */
	public function variablesFor(Book $book): array {
		$authors = $book->getAuthorsArray();
		$index = $book->getSeriesIndex();
		$year = '';
		if (preg_match('/^(\d{4})/', (string)$book->getPublishedAt(), $m) === 1) {
			$year = $m[1];
		}
		$genre = '';
		foreach ($this->tagMapper->findByBook($book->getId(), Tag::TYPE_GENRE) as $tag) {
			$genre = (string)$tag->getName();
			break;
		}
		return [
			'author' => (string)($authors[0] ?? ''),
			'authors' => implode(', ', $authors),
			'title' => trim((string)$book->getTitle()),
			'series' => trim((string)$book->getSeries()),
			'series_index' => $index === null ? '' : rtrim(rtrim(sprintf('%.4F', $index), '0'), '.'),
			'year' => $year,
			'publisher' => trim((string)$book->getPublisher()),
			'language' => trim((string)$book->getLanguage()),
			'genre' => $genre,
			'format' => (string)$book->getFormat(),
		];
	}

	// ------------------------------------------------------------------ plan / apply

	/**
	 * @param list<int> $fileIds
	 * @return array{items: list<EbookReaderOrganizeItem>}
	 * @throws OrganizeException
	 */
	public function preview(string $userId, array $fileIds, string $pattern, ?string $targetFolder): array {
		return ['items' => $this->plan($userId, $fileIds, $pattern, $targetFolder, false)];
	}

	/**
	 * @param list<int> $fileIds
	 * @return array{items: list<EbookReaderOrganizeItem>, moved: int, failed: int}
	 * @throws OrganizeException
	 */
	public function apply(string $userId, array $fileIds, string $pattern, ?string $targetFolder): array {
		$items = $this->plan($userId, $fileIds, $pattern, $targetFolder, true);
		$moved = 0;
		$failed = 0;
		foreach ($items as $item) {
			if ($item['status'] === 'moved') {
				$moved++;
			} elseif ($item['status'] === 'failed') {
				$failed++;
			}
		}
		return ['items' => $items, 'moved' => $moved, 'failed' => $failed];
	}

	/**
	 * @param list<int> $fileIds
	 * @return list<EbookReaderOrganizeItem>
	 * @throws OrganizeException
	 */
	private function plan(string $userId, array $fileIds, string $pattern, ?string $targetFolder, bool $apply): array {
		$fileIds = array_values(array_unique($fileIds));
		if ($fileIds === []) {
			throw new OrganizeException('No files selected.');
		}
		if (count($fileIds) > self::MAX_FILES) {
			throw new OrganizeException('At most ' . self::MAX_FILES . ' files per request.');
		}
		$pattern = trim($pattern);
		if ($pattern === '' || strlen($pattern) > self::MAX_PATTERN_LENGTH) {
			throw new OrganizeException('The pattern must not be empty or longer than ' . self::MAX_PATTERN_LENGTH . ' characters.');
		}
		$base = $this->resolveTargetFolder($userId, $targetFolder);
		$userFolder = $this->rootFolder->getUserFolder($userId);

		/** @var array<string, true> $taken lower-cased target paths already planned in this request */
		$taken = [];
		$items = [];
		/** @var array<string, true> $sourceFolders user-relative paths of folders files were moved out of */
		$sourceFolders = [];

		foreach ($fileIds as $fileId) {
			try {
				$book = $this->bookMapper->findByUserAndFile($userId, $fileId);
				$node = $this->library->getFileForUser($userId, $fileId);
			} catch (DoesNotExistException|NotFoundException) {
				$items[] = ['fileId' => $fileId, 'from' => '', 'to' => '', 'status' => 'error', 'message' => 'Book not found.'];
				continue;
			}
			$rel = $userFolder->getRelativePath($node->getPath());
			$from = '/' . trim((string)($rel ?? $book->getPath()), '/');
			$ext = $this->extensionOf($node->getName());
			$fallback = $ext !== '' ? substr($node->getName(), 0, -strlen($ext)) : $node->getName();
			$rendered = $this->renderPath($pattern, $this->variablesFor($book), $ext, $fallback);
			$to = ($base === '/' ? '' : $base) . '/' . $rendered;

			if ($to === $from) {
				$taken[mb_strtolower($to)] = true;
				$items[] = ['fileId' => $fileId, 'from' => $from, 'to' => $to, 'status' => 'unchanged'];
				continue;
			}

			$conflict = isset($taken[mb_strtolower($to)]) || $this->occupiedByOther($userFolder, $to, $fileId);
			if ($conflict && !$apply) {
				$taken[mb_strtolower($to)] = true;
				$items[] = ['fileId' => $fileId, 'from' => $from, 'to' => $to, 'status' => 'conflict', 'message' => 'A file with this name exists or is planned already.'];
				continue;
			}
			try {
				if ($conflict) {
					$to = $this->uniqueTarget($userFolder, $to, $fileId, $taken);
				}
			} catch (OrganizeException $e) {
				$items[] = ['fileId' => $fileId, 'from' => $from, 'to' => $to, 'status' => 'failed', 'message' => $e->getMessage()];
				continue;
			}
			$taken[mb_strtolower($to)] = true;
			if (!$apply) {
				$items[] = ['fileId' => $fileId, 'from' => $from, 'to' => $to, 'status' => 'move'];
				continue;
			}

			try {
				if (!$node->isUpdateable() || !$node->isDeletable()) {
					throw new \RuntimeException('The file may not be moved.');
				}
				$this->ensureFolders($userFolder, dirname($to));
				$parent = $node->getParent();
				$oldName = $node->getName();
				$node->move($userFolder->getPath() . $to);
				// the rename event moves the sidecar as well; this is a no-op then
				$this->sidecar->moveAlong($parent, $oldName, $node->getParent(), basename($to));
				$book->setPath($to);
				$book->setUpdatedAt((int)(microtime(true) * 1000.0));
				$this->bookMapper->update($book);
				$sourceFolders[$this->relativeFolder($userFolder, $parent) ?? dirname($from)] = true;
				$items[] = ['fileId' => $fileId, 'from' => $from, 'to' => $to, 'status' => 'moved'];
			} catch (\Throwable $e) {
				$this->logger->warning('Organise failed for file ' . $fileId . ': ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
				$items[] = ['fileId' => $fileId, 'from' => $from, 'to' => $to, 'status' => 'failed', 'message' => 'The file could not be moved.'];
			}
		}

		if ($apply && $sourceFolders !== []) {
			$this->removeEmptyFolders($userId, $userFolder, array_map('strval', array_keys($sourceFolders)));
		}
		return $items;
	}

	private function extensionOf(string $filename): string {
		$pos = strrpos($filename, '.');
		if ($pos === false || $pos === 0) {
			return '';
		}
		return mb_strtolower(substr($filename, $pos));
	}

	private function occupiedByOther(Folder $userFolder, string $to, int $fileId): bool {
		$rel = ltrim($to, '/');
		try {
			if (!$userFolder->nodeExists($rel)) {
				return false;
			}
			return $userFolder->get($rel)->getId() !== $fileId;
		} catch (\Throwable) {
			return true;
		}
	}

	/**
	 * @param array<string, true> $taken
	 * @throws OrganizeException
	 */
	private function uniqueTarget(Folder $userFolder, string $to, int $fileId, array $taken): string {
		$ext = $this->extensionOf(basename($to));
		$stem = substr($to, 0, strlen($to) - strlen($ext));
		for ($n = 2; $n <= 1000; $n++) {
			$candidate = $stem . ' (' . $n . ')' . $ext;
			if (!isset($taken[mb_strtolower($candidate)]) && !$this->occupiedByOther($userFolder, $candidate, $fileId)) {
				return $candidate;
			}
		}
		throw new OrganizeException('No free file name found.');
	}

	/** Creates the folder chain below the user folder ($path user-relative, leading slash). */
	private function ensureFolders(Folder $userFolder, string $path): void {
		$current = '';
		foreach (explode('/', trim($path, '/')) as $segment) {
			if ($segment === '') {
				continue;
			}
			$current .= '/' . $segment;
			if (!$userFolder->nodeExists(ltrim($current, '/'))) {
				$userFolder->newFolder(ltrim($current, '/'));
			}
		}
	}

	private function relativeFolder(Folder $userFolder, Node $node): ?string {
		$rel = $userFolder->getRelativePath($node->getPath());
		return $rel === null ? null : '/' . trim($rel, '/');
	}

	/**
	 * Validates the target folder and returns it normalised ("/Books/Sorted", "/" for the user root).
	 * @throws OrganizeException
	 */
	public function resolveTargetFolder(string $userId, ?string $targetFolder): string {
		$folders = $this->settings->get($userId)['libraryFolders'];
		if ($targetFolder === null || trim($targetFolder) === '') {
			return '/' . trim($folders[0] ?? '/', '/');
		}
		if (preg_match('/[\x00-\x1F\x7F]/', $targetFolder) === 1) {
			throw new OrganizeException('Invalid target folder.');
		}
		$segments = [];
		foreach (explode('/', str_replace('\\', '/', $targetFolder)) as $segment) {
			$segment = trim($segment);
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				throw new OrganizeException('Invalid target folder.');
			}
			$segments[] = $segment;
		}
		$path = '/' . implode('/', $segments);
		if (!$this->library->isPathInLibrary($userId, $path)) {
			throw new OrganizeException('The target folder must be inside a library folder.');
		}
		if ($segments !== []) {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			// the deepest existing ancestor must be a folder, the rest is created on apply
			$probe = $segments;
			while ($probe !== []) {
				$rel = implode('/', $probe);
				if ($userFolder->nodeExists($rel)) {
					if (!$userFolder->get($rel) instanceof Folder) {
						throw new OrganizeException('The target folder is a file.');
					}
					break;
				}
				array_pop($probe);
			}
			foreach ($segments as $segment) {
				try {
					$this->filenameValidator->validateFilename($segment);
				} catch (\Throwable) {
					throw new OrganizeException('Invalid target folder name.');
				}
			}
		}
		return $path;
	}

	/**
	 * Removes folders that are now empty, walking up while the parent is empty too.
	 * Only folders strictly inside a library folder are touched, never a library root.
	 * @param list<string> $folders user-relative folder paths
	 */
	private function removeEmptyFolders(string $userId, Folder $userFolder, array $folders): void {
		$roots = array_map(static fn (string $f): string => '/' . trim($f, '/'), $this->settings->get($userId)['libraryFolders']);
		usort($folders, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
		$done = [];
		foreach ($folders as $path) {
			while ($path !== '/' && $path !== '' && $path !== '.' && !isset($done[$path])) {
				$done[$path] = true;
				if (in_array($path, $roots, true) || !$this->library->isPathInLibrary($userId, $path)) {
					break;
				}
				try {
					$node = $userFolder->get(ltrim($path, '/'));
					if (!$node instanceof Folder || $node->getDirectoryListing() !== [] || !$node->isDeletable()) {
						break;
					}
					$node->delete();
				} catch (\Throwable $e) {
					$this->logger->info('Folder cleanup skipped for ' . $path . ': ' . $e->getMessage(), ['app' => 'ebookreader']);
					break;
				}
				$path = dirname($path);
			}
		}
	}
}
