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
use OCA\EbookReader\Metadata\ComicArchive;
use OCA\EbookReader\Metadata\ComicInfoParser;
use OCA\EbookReader\Metadata\UnsafeArchiveException;
use OCP\Files\File;
use OCP\Files\NotPermittedException;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Converts comics between CBZ, CB7, CBT and fixed layout EPUB on the server. CBR can be read
 * (with a tool) but never written (RAR is proprietary). When the server cannot do a conversion
 * the browser can (libarchive.js + WebDAV), see `mode` in targets().
 */
class ConvertService {
	/**
	 * All format keys of the feature, in display order
	 * @var list<string>
	 */
	public const FORMATS = ['cbz', 'cbr', 'cb7', 'cbt', 'epub'];
	/**
	 * Formats that can be converted (comics)
	 * @var list<string>
	 */
	public const SOURCES = ['cbz', 'cbr', 'cb7', 'cbt'];
	public const MAX_SOURCE_BYTES = 2 * 1024 * 1024 * 1024;

	public const REASON_CURRENT = 'This is the current format';
	public const REASON_RAR = 'RAR can only be written with proprietary software';
	public const REASON_EXISTS = 'A file with this name already exists';
	public const REASON_FOLDER = 'You cannot create files in this folder';

	public function __construct(
		private LibraryService $library,
		private ArchiveTools $tools,
		private ProgressService $progress,
		private BookMapper $bookMapper,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
		private ?ComicWriter $writer = null,
	) {
		$this->writer ??= new ComicWriter($tools);
	}

	/**
	 * @return array{tools: array{sevenZip: bool, unrar: bool, bsdtar: bool}, server: array{read: list<string>, write: list<string>}}
	 */
	public function capabilities(): array {
		$read = [];
		$write = [];
		foreach (self::FORMATS as $f) {
			if (self::isSource($f) && $this->serverCanRead($f)) {
				$read[] = $f;
			}
			if ($this->serverCanWrite($f)) {
				$write[] = $f;
			}
		}
		return ['tools' => $this->tools->available(), 'server' => ['read' => $read, 'write' => $write]];
	}

	/**
	 * @return array{source: string, targets: list<array{format: string, mode: string, reason?: string}>}
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\Files\NotFoundException
	 */
	public function targets(string $userId, int $fileId): array {
		$book = $this->library->getBook($userId, $fileId);
		$file = $this->library->getFileForUser($userId, $fileId);
		$source = $book->getFormat();
		if (!self::isSource($source)) {
			return ['source' => $source, 'targets' => []];
		}
		$parent = $file->getParent();
		$base = pathinfo($file->getName(), PATHINFO_FILENAME);
		$targets = [];
		foreach (self::FORMATS as $candidate) {
			$target = self::normaliseKey($candidate);
			$entry = ['format' => $target, 'mode' => 'unavailable'];
			if ($target === $source) {
				$entry['reason'] = self::REASON_CURRENT;
			} elseif ($target === 'cbr') {
				$entry['reason'] = self::REASON_RAR;
			} elseif (!$parent->isCreatable()) {
				$entry['reason'] = self::REASON_FOLDER;
			} elseif ($parent->nodeExists($base . '.' . $target)) {
				$entry['reason'] = self::REASON_EXISTS;
			} else {
				// the browser can read every comic format and write all but CBR
				$entry['mode'] = $this->serverCanRead($source) && $this->serverCanWrite($target) ? 'server' : 'client';
			}
			$targets[] = $entry;
		}
		return ['source' => $source, 'targets' => $targets];
	}

	/**
	 * @return array{book: Book, fileId: int, path: string}
	 * @throws ConvertException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\Files\NotFoundException
	 */
	public function convert(string $userId, int $fileId, string $target, bool $deleteOriginal): array {
		$book = $this->library->getBook($userId, $fileId);
		$file = $this->library->getFileForUser($userId, $fileId);
		$source = $book->getFormat();
		$target = self::normaliseKey($target);
		if (!self::isSource($source)) {
			throw new ConvertException('Only comics can be converted', 415);
		}
		if (!in_array($target, self::FORMATS, true)) {
			throw new ConvertException('Unknown target format', 400);
		}
		if ($target === $source) {
			throw new ConvertException('The book already has this format', 400);
		}
		if ($target === 'cbr') {
			throw new ConvertException(self::REASON_RAR, 415);
		}
		if (!$this->serverCanRead($source) || !$this->serverCanWrite($target)) {
			throw new ConvertException('The server cannot do this conversion, use the browser', 415);
		}
		if ($file->getSize() > self::MAX_SOURCE_BYTES) {
			throw new ConvertException('The file is too large to convert on the server', 413);
		}
		$parent = $file->getParent();
		if (!$parent->isCreatable()) {
			throw new ConvertException(self::REASON_FOLDER, 403);
		}
		if ($deleteOriginal && !$file->isDeletable()) {
			throw new ConvertException('The original cannot be deleted', 403);
		}
		$targetName = pathinfo($file->getName(), PATHINFO_FILENAME) . '.' . $target;
		if ($parent->nodeExists($targetName)) {
			throw new ConvertException(self::REASON_EXISTS, 409);
		}

		@set_time_limit(0);
		$staging = $this->tempManager->getTemporaryFolder('-ebr-convert');
		$dst = $this->tempManager->getTemporaryFile('.' . $target);
		if ($staging === false || $dst === false) {
			throw new ConvertException('Cannot create temporary files', 500);
		}
		[$localSource, $tmpSource] = $this->localPath($file, $source);
		try {
			$prepared = $this->stage($localSource, $source, $staging, $book);
			$this->build($target, $dst, $staging, $prepared, $book);

			$stream = fopen($dst, 'rb');
			if ($stream === false) {
				throw new ConvertException('Cannot read the converted file', 500);
			}
			try {
				$newFile = $parent->newFile($targetName, $stream);
			} catch (NotPermittedException $e) {
				throw new ConvertException($parent->nodeExists($targetName) ? self::REASON_EXISTS : 'The converted file cannot be saved', $parent->nodeExists($targetName) ? 409 : 403, $e);
			} finally {
				fclose($stream);
			}
		} finally {
			if ($tmpSource !== null) {
				@unlink($tmpSource);
			}
			@unlink($dst);
			self::removeDir($staging);
		}

		$newBook = $this->library->indexFile($userId, $newFile);
		if ($newBook === null) {
			throw new ConvertException('The converted file could not be indexed', 500);
		}
		$newBook = $this->carryOver($userId, $book, $newBook, $prepared['hrefs'] ?? [], $prepared['pages']);

		if ($deleteOriginal) {
			try {
				$file->delete();
			} catch (\Throwable $e) {
				$this->logger->warning('Original comic could not be deleted after conversion: ' . $e->getMessage(), ['app' => 'ebookreader']);
			}
		}
		return ['book' => $newBook, 'fileId' => $newBook->getFileId(), 'path' => $newBook->getPath()];
	}

	// ---- steps ----------------------------------------------------------------------------------

	/**
	 * Extracts the pages into the staging directory under their final names.
	 *
	 * @return array{pages: list<string>, names: list<string>, comicInfo: ?string, coverIndex: int, hrefs?: list<string>}
	 */
	private function stage(string $localSource, string $format, string $staging, Book $book): array {
		try {
			$archive = ComicArchive::open($localSource, $format, $this->tools);
		} catch (UnsafeArchiveException|\RuntimeException $e) {
			throw new ConvertException('The comic cannot be read: ' . $e->getMessage(), 422, $e);
		}
		try {
			$pages = $archive->pages();
			if ($pages === []) {
				throw new ConvertException('The comic contains no pages', 422);
			}
			$comicInfo = $archive->comicInfo();
			$width = max(4, strlen((string)count($pages)));
			$names = [];
			foreach ($pages as $i => $page) {
				$ext = strtolower(pathinfo($page, PATHINFO_EXTENSION));
				$ext = $ext === 'jpeg' ? 'jpg' : $ext;
				$name = sprintf('%0' . $width . 'd.%s', $i + 1, $ext);
				try {
					$data = $archive->read($page);
				} catch (UnsafeArchiveException|\RuntimeException $e) {
					throw new ConvertException('Page ' . ($i + 1) . ' cannot be read', 422, $e);
				}
				if ($data === null || file_put_contents($staging . '/' . $name, $data) === false) {
					throw new ConvertException('Page ' . ($i + 1) . ' cannot be read', 422);
				}
				$names[] = $name;
			}
		} finally {
			$archive->close();
		}
		if ($comicInfo === null) {
			$generated = $this->generatedComicInfo($book);
		} else {
			$generated = null;
		}
		return [
			'pages' => $pages,
			'names' => $names,
			'comicInfo' => $comicInfo ?? $generated,
			'coverIndex' => ComicInfoParser::parse($comicInfo)['coverIndex'],
		];
	}

	/**
	 * @param array{pages: list<string>, names: list<string>, comicInfo: ?string, coverIndex: int, hrefs?: list<string>} $prepared
	 */
	private function build(string $target, string $dst, string $staging, array &$prepared, Book $book): void {
		$writer = $this->writer ?? new ComicWriter($this->tools);
		try {
			switch ($target) {
				case 'cbz':
					$writer->writeZip($dst, $staging, $prepared['names'], $prepared['comicInfo']);
					break;
				case 'cbt':
					$writer->writeTar($dst, $staging, $prepared['names'], $prepared['comicInfo']);
					break;
				case 'cb7':
					$writer->writeSevenZip($dst, $staging, $prepared['names'], $prepared['comicInfo']);
					break;
				case 'epub':
					$prepared['hrefs'] = $writer->writeEpub($dst, $staging, $prepared['names'], [
						'title' => $book->getTitle(),
						'authors' => $book->getAuthorsArray(),
						'series' => $book->getSeries(),
						'seriesIndex' => $book->getSeriesIndex(),
						'description' => $book->getDescription(),
						'language' => $book->getLanguage(),
						'publisher' => $book->getPublisher(),
						'publishedAt' => $book->getPublishedAt(),
						'genres' => $this->tagNames($book, Tag::TYPE_GENRE),
						'tags' => $this->tagNames($book, Tag::TYPE_TAG),
					], ComicInfoParser::isRightToLeft($prepared['comicInfo']), $prepared['coverIndex']);
					break;
				default:
					throw new ConvertException('Unknown target format', 400);
			}
		} catch (ConvertException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->warning('Comic conversion failed: ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
			throw new ConvertException('The conversion failed', 500, $e);
		}
	}

	/**
	 * Copies rating, read status, app tags and the reading position to the new book.
	 *
	 * @param list<string> $hrefs page hrefs of the new book (EPUB); empty = use the page file names
	 * @param list<string> $oldPages entry names of the source pages
	 */
	private function carryOver(string $userId, Book $old, Book $new, array $hrefs, array $oldPages): Book {
		try {
			$this->copyProgress($userId, $old->getFileId(), $new->getFileId(), $new->getFormat(), $oldPages, $hrefs);
		} catch (\Throwable $e) {
			$this->logger->info('Reading position was not carried over: ' . $e->getMessage(), ['app' => 'ebookreader']);
		}
		try {
			$new = $this->bookMapper->findByUserAndFile($userId, $new->getFileId());
			$new->setRating($old->getRating());
			$new->setReadStatus($old->getReadStatus());
			$new->setReadStatusManual($old->getReadStatusManual());
			$new = $this->bookMapper->update($new);
			foreach ([Tag::TYPE_GENRE, Tag::TYPE_TAG] as $type) {
				$names = [];
				foreach ($this->library->getTags($old->getId()) as $tag) {
					if ($tag->getType() === $type && $tag->getSource() === Tag::SOURCE_APP) {
						$names[] = $tag->getName();
					}
				}
				if ($names !== []) {
					$this->library->setTags($new->getId(), $type, $names, Tag::SOURCE_APP);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->info('Rating/tags were not carried over: ' . $e->getMessage(), ['app' => 'ebookreader']);
		}
		return $new;
	}

	/**
	 * @param list<string> $oldPages
	 * @param list<string> $hrefs
	 */
	private function copyProgress(string $userId, int $oldFileId, int $newFileId, string $newFormat, array $oldPages, array $hrefs): void {
		$p = $this->progress->get($userId, $oldFileId);
		if ($p === null) {
			return;
		}
		$locator = $p->getLocatorArray();
		$locations = isset($locator['locations']) && is_array($locator['locations']) ? $locator['locations'] : [];
		$href = is_string($locator['href'] ?? null) ? explode('#', $locator['href'], 2)[0] : '';
		$index = array_search($href, $oldPages, true);
		if ($index === false && isset($locations['position']) && is_int($locations['position'])) {
			$index = $locations['position'] - 1;
		}
		if (!is_int($index) || $index < 0 || $index >= count($oldPages)) {
			return;
		}
		$width = max(4, strlen((string)count($oldPages)));
		if ($newFormat === 'epub') {
			$newHref = $hrefs[$index] ?? null;
			$type = 'application/xhtml+xml';
		} else {
			$ext = strtolower(pathinfo($oldPages[$index], PATHINFO_EXTENSION));
			$newHref = sprintf('%0' . $width . 'd.%s', $index + 1, $ext === 'jpeg' ? 'jpg' : $ext);
			$type = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
		}
		if ($newHref === null) {
			return;
		}
		$newLocations = ['position' => $index + 1, 'progression' => 0];
		if (isset($locations['totalProgression']) && (is_float($locations['totalProgression']) || is_int($locations['totalProgression']))) {
			$newLocations['totalProgression'] = $locations['totalProgression'];
		}
		$this->progress->put($userId, $newFileId, ['href' => $newHref, 'type' => $type, 'locations' => $newLocations], $p->getPercentage(), $p->getDevice(), $p->getClientUpdatedAt());
	}

	private function generatedComicInfo(Book $book): ?string {
		if ($book->getTitle() === null && $book->getAuthorsArray() === [] && $book->getSeries() === null) {
			return null;
		}
		return ComicInfoParser::build(
			$book->getTitle(),
			$book->getAuthorsArray(),
			$book->getSeries(),
			$book->getSeriesIndex(),
			$book->getPublisher(),
			$book->getLanguage(),
			$book->getPublishedAt(),
			$this->tagNames($book, Tag::TYPE_GENRE),
			$this->tagNames($book, Tag::TYPE_TAG),
		);
	}

	/** @return list<string> */
	private function tagNames(Book $book, string $type): array {
		$names = [];
		foreach ($this->library->getTags($book->getId()) as $tag) {
			if ($tag->getType() === $type) {
				$names[] = $tag->getName();
			}
		}
		return $names;
	}

	private static function isSource(string $format): bool {
		return in_array($format, self::SOURCES, true);
	}

	private static function normaliseKey(string $key): string {
		return strtolower($key);
	}

	private function serverCanRead(string $format): bool {
		return $this->tools->canRead($format);
	}

	private function serverCanWrite(string $format): bool {
		if ($format === 'cbz' || $format === 'epub') {
			return class_exists(\ZipArchive::class);
		}
		return $this->tools->canWrite($format);
	}

	/**
	 * Local path of the file: direct for local storages, otherwise a temporary copy.
	 *
	 * @return array{0: string, 1: ?string} path and temp file to delete
	 */
	private function localPath(File $file, string $format): array {
		$storage = $file->getStorage();
		if ($storage->isLocal()) {
			$local = $storage->getLocalFile($file->getInternalPath());
			if (is_string($local) && is_file($local)) {
				return [$local, null];
			}
		}
		$tmp = $this->tempManager->getTemporaryFile('.' . $format);
		if ($tmp === false) {
			throw new ConvertException('Cannot create temporary files', 500);
		}
		$in = $file->fopen('r');
		$out = fopen($tmp, 'wb');
		if ($in === false || $out === false) {
			throw new ConvertException('Cannot read the file', 500);
		}
		stream_copy_to_stream($in, $out);
		fclose($in);
		fclose($out);
		return [$tmp, $tmp];
	}

	private static function removeDir(string $dir): void {
		$items = @scandir($dir);
		if ($items === false) {
			return;
		}
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			if (is_dir($path) && !is_link($path)) {
				self::removeDir($path);
			} else {
				@unlink($path);
			}
		}
		@rmdir($dir);
	}
}
