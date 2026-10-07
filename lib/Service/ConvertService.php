<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\Db\AnnotationMapper;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Tag;
use OCA\EbookReader\Metadata\ComicArchive;
use OCA\EbookReader\Metadata\ComicInfoParser;
use OCA\EbookReader\Metadata\SidecarService;
use OCA\EbookReader\Metadata\UnsafeArchiveException;
use OCP\Files\File;
use OCP\Files\NotFoundException;
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
	public const REASON_NO_READ = 'The server cannot read this format (bsdtar or unrar is required for CBR)';
	public const REASON_NO_OPTIMIZE = 'Image optimization is not available on this server';
	/** Suffix of the file name when a comic is optimized without changing its format */
	public const OPTIMIZED_SUFFIX = ' (optimized)';

	public function __construct(
		private LibraryService $library,
		private ArchiveTools $tools,
		private ProgressService $progress,
		private BookMapper $bookMapper,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
		private ArchiveCache $archiveCache,
		private SidecarService $sidecar,
		private ?ComicWriter $writer = null,
		private ?ImageOptimizer $optimizer = null,
		private ?AnnotationMapper $annotations = null,
	) {
		$this->writer ??= new ComicWriter($tools);
		$this->optimizer ??= new ImageOptimizer();
	}

	/**
	 * @return array{tools: array{sevenZip: bool, unrar: bool, bsdtar: bool}, server: array{read: list<string>, write: list<string>}, optimize: array{available: bool, maxHeights: list<int>}}
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
		return [
			'tools' => $this->tools->available(),
			'server' => ['read' => $read, 'write' => $write],
			'optimize' => ['available' => ImageOptimizer::available(), 'maxHeights' => ImageOptimizer::HEIGHTS],
		];
	}

	/**
	 * @return array{source: string, optimize: array{available: bool, reason?: string}, targets: list<array{format: string, mode: string, reason?: string, optimizeOnly?: bool}>}
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\Files\NotFoundException
	 */
	public function targets(string $userId, int $fileId): array {
		$book = $this->library->getBook($userId, $fileId);
		$file = $this->library->getFileForUser($userId, $fileId);
		$source = $book->getFormat();
		if (!self::isSource($source)) {
			return ['source' => $source, 'optimize' => ['available' => false], 'targets' => []];
		}
		$parent = $file->getParent();
		$base = pathinfo($file->getName(), PATHINFO_FILENAME);
		$targets = [];
		$optimize = ['available' => true];
		if (!ImageOptimizer::available()) {
			$optimize = ['available' => false, 'reason' => self::REASON_NO_OPTIMIZE];
		} elseif (!$this->serverCanRead($source)) {
			// only the server optimizes; CBR needs bsdtar or unrar to be read
			$optimize = ['available' => false, 'reason' => self::REASON_NO_READ];
		}
		foreach (self::FORMATS as $candidate) {
			$target = self::normaliseKey($candidate);
			$entry = ['format' => $target, 'mode' => 'unavailable'];
			if ($target === $source) {
				$entry['reason'] = self::REASON_CURRENT;
				// the same format is possible when the pages are optimized ("optimize only")
				if ($optimize['available'] && $this->serverCanWrite($target) && $parent->isCreatable() && !$parent->nodeExists($base . self::OPTIMIZED_SUFFIX . '.' . $target)) {
					$entry['mode'] = 'server';
					$entry['optimizeOnly'] = true;
				}
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
		return ['source' => $source, 'optimize' => $optimize, 'targets' => $targets];
	}

	/**
	 * Synchronous part of an asynchronous conversion: throws what convert() would throw before it starts working.
	 *
	 * @param ?array{maxHeight: int, jpegQuality: int, pngToJpeg: bool} $optimize validated options, null = plain conversion
	 * @throws ConvertException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\Files\NotFoundException
	 */
	public function validate(string $userId, int $fileId, string $target, bool $deleteOriginal, ?array $optimize = null): void {
		$this->check($userId, $fileId, $target, $deleteOriginal, $optimize);
	}

	/**
	 * Target format of "optimize this comic": the format itself, CBR (read only) becomes CBZ.
	 */
	public static function optimizeTarget(string $source): string {
		return $source === 'cbr' ? 'cbz' : $source;
	}

	/**
	 * Name of the converted comic: "Name (optimized).ext" when the format stays, otherwise "Name.ext".
	 */
	public static function targetName(string $fileName, string $source, string $target): string {
		$base = pathinfo($fileName, PATHINFO_FILENAME);
		return $base . ($target === $source ? self::OPTIMIZED_SUFFIX : '') . '.' . $target;
	}

	/**
	 * Estimates the result of an optimization (samples a few pages, see ImageOptimizer::estimate()).
	 *
	 * @param array{maxHeight: int, jpegQuality: int, pngToJpeg: bool} $options
	 * @return array{pages: int, oversizedPages: int, currentBytes: int, estimatedBytes: int, exact: bool}
	 * @throws ConvertException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\Files\NotFoundException
	 */
	public function estimateOptimize(string $userId, int $fileId, array $options): array {
		$book = $this->library->getBook($userId, $fileId);
		$file = $this->library->getFileForUser($userId, $fileId);
		$source = $book->getFormat();
		if (!self::isSource($source)) {
			throw new ConvertException('Only comics can be optimized', 415);
		}
		if (!ImageOptimizer::isActive($options)) {
			throw new ConvertException('Nothing to optimize', 400);
		}
		if (!ImageOptimizer::available()) {
			throw new ConvertException(self::REASON_NO_OPTIMIZE, 415);
		}
		if (!$this->serverCanRead($source)) {
			throw new ConvertException(self::REASON_NO_READ, 415);
		}
		if ($file->getSize() > self::MAX_SOURCE_BYTES) {
			throw new ConvertException('The file is too large to convert on the server', 413);
		}
		try {
			$localSource = $this->archiveCache->localPath($file);
		} catch (\RuntimeException $e) {
			throw new ConvertException('Cannot read the file', 500, $e);
		}
		try {
			try {
				$archive = ComicArchive::open($localSource, $source, $this->tools);
			} catch (UnsafeArchiveException|\RuntimeException $e) {
				throw new ConvertException('The comic cannot be read: ' . $e->getMessage(), 422, $e);
			}
			try {
				$pages = $archive->pages();
				if ($pages === []) {
					throw new ConvertException('The comic contains no pages', 422);
				}
				if (count($pages) > ArchiveTools::MAX_ENTRIES) {
					throw new ConvertException('The comic has too many pages to convert on the server', 413);
				}
				return ($this->optimizer ?? new ImageOptimizer())->estimate($archive, $pages, $options, (int)$file->getSize());
			} catch (UnsafeArchiveException|\RuntimeException $e) {
				throw new ConvertException('The comic cannot be read: ' . $e->getMessage(), 422, $e);
			} finally {
				$archive->close();
			}
		} finally {
			$this->archiveCache->release($localSource);
		}
	}

	/**
	 * @param ?array{maxHeight: int, jpegQuality: int, pngToJpeg: bool} $optimize
	 * @return array{0: Book, 1: File, 2: string, 3: string, 4: \OCP\Files\Folder, 5: string} book, file, source format, target format, parent folder, target file name
	 * @throws ConvertException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\Files\NotFoundException
	 */
	private function check(string $userId, int $fileId, string $target, bool $deleteOriginal, ?array $optimize = null): array {
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
		if ($optimize !== null && !ImageOptimizer::isActive($optimize)) {
			$optimize = null;
		}
		if ($optimize !== null && !ImageOptimizer::available()) {
			throw new ConvertException(self::REASON_NO_OPTIMIZE, 415);
		}
		// the same format is only allowed as "optimize only"
		if ($target === $source && $optimize === null) {
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
		if ($deleteOriginal && (!$file->isDeletable() || FileOwnership::isShared($file))) {
			throw new ConvertException('The original cannot be deleted', 403);
		}
		$targetName = self::targetName($file->getName(), $source, $target);
		if ($parent->nodeExists($targetName)) {
			throw new ConvertException(self::REASON_EXISTS, 409);
		}
		return [$book, $file, $source, $target, $parent, $targetName];
	}

	/**
	 * @param ?callable(float, string): void $progress optional progress callback (fraction 0..1, short English step text)
	 * @param ?array{maxHeight: int, jpegQuality: int, pngToJpeg: bool} $optimize validated image options (lossy downscale of the pages), null = none
	 * @return array{book: Book, fileId: int, path: string}
	 * @throws ConvertException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\Files\NotFoundException
	 */
	public function convert(string $userId, int $fileId, string $target, bool $deleteOriginal, ?callable $progress = null, ?array $optimize = null): array {
		[$book, $file, $source, $target, $parent, $targetName] = $this->check($userId, $fileId, $target, $deleteOriginal, $optimize);
		if ($optimize !== null && !ImageOptimizer::isActive($optimize)) {
			$optimize = null;
		}

		@set_time_limit(0);
		$staging = $this->tempManager->getTemporaryFolder('-ebr-convert');
		$dst = $this->tempManager->getTemporaryFile('.' . $target);
		if ($staging === false || $dst === false) {
			throw new ConvertException('Cannot create temporary files', 500);
		}
		if ($progress !== null) {
			$progress(0.0, 'Preparing');
		}
		try {
			$localSource = $this->archiveCache->localPath($file);
		} catch (\RuntimeException $e) {
			self::removeDir($staging);
			@unlink($dst);
			throw new ConvertException('Cannot read the file', 500, $e);
		}
		try {
			try {
				$prepared = $this->stage($localSource, $source, $staging, $book, $progress, $optimize);
			} finally {
				$this->archiveCache->release($localSource);
			}
			if ($progress !== null) {
				$progress(0.75, 'Packing the new file');
			}
			$this->build($target, $dst, $staging, $prepared, $book);
			if ($progress !== null) {
				$progress(0.92, 'Saving the new file');
			}

			$stream = fopen($dst, 'rb');
			if ($stream === false) {
				throw new ConvertException('Cannot read the converted file', 500);
			}
			try {
				$newFile = $parent->newFile($targetName, $stream);
			} catch (NotPermittedException $e) {
				throw new ConvertException($parent->nodeExists($targetName) ? self::REASON_EXISTS : 'The converted file cannot be saved', $parent->nodeExists($targetName) ? 409 : 403, $e);
			} finally {
				// newFile() closes the stream itself
				/** @psalm-suppress RedundantCondition psalm does not know the stream was closed by Nextcloud */
				if (is_resource($stream)) {
					fclose($stream);
				}
			}
		} finally {
			@unlink($dst);
			self::removeDir($staging);
		}

		// the metadata sidecar belongs to the new book too (before indexing, which reads it)
		$this->sidecar->copyAlong($parent, $file->getName(), $parent, $targetName);
		if ($progress !== null) {
			$progress(0.97, 'Indexing the new file');
		}
		$newBook = $this->library->indexFile($userId, $newFile);
		if ($newBook === null) {
			throw new ConvertException('The converted file could not be indexed', 500);
		}
		$newBook = $this->carryOver($userId, $book, $newBook, $prepared['hrefs'] ?? [], $prepared['pages']);
		$this->carryOverForOthers($userId, $book, $newFile, $prepared['hrefs'] ?? [], $prepared['pages']);

		if ($deleteOriginal) {
			try {
				$oldName = $file->getName();
				$file->delete();
				$this->sidecar->deleteFor($parent, $oldName);
			} catch (\Throwable $e) {
				$this->logger->warning('Original comic could not be deleted after conversion: ' . $e->getMessage(), ['app' => 'ebookreader']);
			}
		}
		return ['book' => $newBook, 'fileId' => $newBook->getFileId(), 'path' => $newBook->getPath()];
	}

	/**
	 * Finishes a conversion that ran in the browser: the client has uploaded the new file next to
	 * the original. Indexes it right away (regardless of its size, unlike the queued indexing of
	 * large uploads), copies the metadata sidecar, carries over rating, read status, app tags and
	 * the reading position, and only then deletes the original (with its sidecar) if requested.
	 *
	 * @param list<string> $oldPages page entry names of the original in reading order
	 * @param list<string> $newPages page entry names of the converted file in the same order
	 * @return array{book: Book, fileId: int, path: string, originalDeleted: bool}
	 */
	public function adoptClientResult(string $userId, int $fileId, string $newName, bool $deleteOriginal, array $oldPages = [], array $newPages = []): array {
		$book = $this->library->getBook($userId, $fileId);
		$file = $this->library->getFileForUser($userId, $fileId);
		if (!self::isSource($book->getFormat())) {
			throw new ConvertException('Only comics can be converted', 415);
		}
		if ($newName === '' || str_contains($newName, '/') || str_contains($newName, '\\') || $newName === $file->getName()) {
			throw new ConvertException('Invalid file name', 400);
		}
		$target = self::normaliseKey(strtolower(pathinfo($newName, PATHINFO_EXTENSION)));
		if (!in_array($target, self::FORMATS, true) || $target === $book->getFormat() || $target === 'cbr') {
			throw new ConvertException('Unknown target format', 400);
		}
		$parent = $file->getParent();
		try {
			$newFile = $parent->get($newName);
		} catch (NotFoundException $e) {
			throw new ConvertException('The converted file was not found next to the original', 404, $e);
		}
		if (!$newFile instanceof File || $newFile->getId() === $file->getId()) {
			throw new ConvertException('The converted file was not found next to the original', 404);
		}
		if ($deleteOriginal && (!$file->isDeletable() || FileOwnership::isShared($file))) {
			throw new ConvertException('The original cannot be deleted', 403);
		}
		$limit = static fn (array $names): array => array_values(array_slice(array_filter($names, static fn ($n): bool => is_string($n) && $n !== '' && strlen($n) <= 1024), 0, 5000));

		$this->sidecar->copyAlong($parent, $file->getName(), $parent, $newName);
		$newBook = $this->library->indexFile($userId, $newFile, true);
		if ($newBook === null) {
			throw new ConvertException('The converted file could not be indexed', 500);
		}
		$newBook = $this->carryOver($userId, $book, $newBook, $limit($newPages), $limit($oldPages));
		$this->carryOverForOthers($userId, $book, $newFile, $limit($newPages), $limit($oldPages));

		$originalDeleted = false;
		if ($deleteOriginal) {
			try {
				$oldName = $file->getName();
				$file->delete();
				$this->sidecar->deleteFor($parent, $oldName);
				$originalDeleted = true;
			} catch (\Throwable $e) {
				$this->logger->warning('Original comic could not be deleted after conversion: ' . $e->getMessage(), ['app' => 'ebookreader']);
			}
		}
		return ['book' => $newBook, 'fileId' => $newBook->getFileId(), 'path' => $newBook->getPath(), 'originalDeleted' => $originalDeleted];
	}

	// ---- steps ----------------------------------------------------------------------------------

	/**
	 * Extracts the pages into the staging directory under their final names.
	 *
	 * @param ?callable(float, string): void $progress
	 * @param ?array{maxHeight: int, jpegQuality: int, pngToJpeg: bool} $optimize
	 * @return array{pages: list<string>, names: list<string>, comicInfo: ?string, coverIndex: int, hrefs?: list<string>}
	 */
	private function stage(string $localSource, string $format, string $staging, Book $book, ?callable $progress = null, ?array $optimize = null): array {
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
			if (count($pages) > ArchiveTools::MAX_ENTRIES) {
				throw new ConvertException('The comic has too many pages to convert on the server', 413);
			}
			$comicInfo = $archive->comicInfo();
			$names = [];
			$writtenBytes = 0;
			$pageCount = count($pages);
			foreach ($pages as $i => $page) {
				if ($progress !== null) {
					$progress(0.05 + 0.7 * ((float)$i / (float)$pageCount), ($optimize !== null ? 'Optimizing page ' : 'Extracting page ') . ($i + 1) . ' of ' . $pageCount);
				}
				$ext = strtolower(pathinfo($page, PATHINFO_EXTENSION));
				try {
					$data = $archive->read($page);
				} catch (UnsafeArchiveException|\RuntimeException $e) {
					throw new ConvertException('Page ' . ($i + 1) . ' cannot be read', 422, $e);
				}
				$writtenBytes += $data === null ? 0 : strlen($data);
				if ($writtenBytes > ArchiveTools::MAX_TOTAL_BYTES) {
					throw new ConvertException('The comic is too large to convert on the server', 413);
				}
				if ($optimize !== null && $data !== null) {
					// one page at a time; unchanged pages keep their bytes and extension
					$res = ($this->optimizer ?? new ImageOptimizer())->optimizePage($data, $optimize);
					if ($res['changed']) {
						$data = $res['data'];
						$ext = $res['ext'] ?? $ext;
					}
					unset($res);
				}
				$name = ImageOptimizer::pageName($i, $pageCount, $ext);
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
		$prepared = [
			'pages' => $pages,
			'names' => $names,
			'comicInfo' => $comicInfo ?? $generated,
			'coverIndex' => ComicInfoParser::parse($comicInfo)['coverIndex'],
		];
		if ($optimize !== null) {
			// extensions may have changed (PNG to JPEG): the reading position has to point to the new names
			$prepared['hrefs'] = $names;
		}
		return $prepared;
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
	 * Copies rating, read status, completion status, a manual age rating, app tags, the reading position and the
	 * annotations of one user to the new book.
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
			$this->moveAnnotations($userId, $old->getFileId(), $new->getFileId(), $new->getFormat(), $oldPages, $hrefs);
		} catch (\Throwable $e) {
			$this->logger->info('Annotations were not carried over: ' . $e->getMessage(), ['app' => 'ebookreader']);
		}
		try {
			$new = $this->bookMapper->findByUserAndFile($userId, $new->getFileId());
			$new->setRating($old->getRating());
			$new->setReadStatus($old->getReadStatus());
			$new->setReadStatusManual($old->getReadStatusManual());
			$new->setCompletion($old->getCompletion());
			if ($old->getAgeRatingManual()) {
				$new->setManualAgeRating($old->getAgeRating());
			}
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
	 * The original may be shared with other users (shared folder): deleting it tombstones their book rows too, so every other
	 * user who has the original in their library gets the new file indexed right away and their data (status, rating, tags,
	 * reading position, annotations) carried over like the acting user's. Users who cannot see the new file or do not
	 * have it in their library keep their data on the old file id.
	 *
	 * @param list<string> $hrefs
	 * @param list<string> $oldPages
	 */
	private function carryOverForOthers(string $actingUserId, Book $oldOfActing, File $newFile, array $hrefs, array $oldPages): void {
		try {
			$rows = $this->bookMapper->findByFileId($oldOfActing->getFileId());
		} catch (\Throwable $e) {
			$this->logger->info('Other users of the original were not looked up: ' . $e->getMessage(), ['app' => 'ebookreader']);
			return;
		}
		foreach ($rows as $old) {
			$userId = $old->getUserId();
			if ($userId === $actingUserId) {
				continue;
			}
			try {
				$theirs = $this->library->getFileForUser($userId, $newFile->getId());
				if (!$this->library->isInLibrary($userId, $theirs)) {
					continue;
				}
				$new = $this->library->indexFile($userId, $theirs, true);
				if ($new === null) {
					continue;
				}
				$this->carryOver($userId, $old, $new, $hrefs, $oldPages);
			} catch (\Throwable $e) {
				$this->logger->info('Data of user ' . $userId . ' was not carried over to the converted book: ' . $e->getMessage(), ['app' => 'ebookreader']);
			}
		}
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
		$locator = $this->mapLocator($p->getLocatorArray(), $newFormat, $oldPages, $hrefs);
		if ($locator === null) {
			return;
		}
		// clientUpdatedAt stays: the carried position is the same reading state, not a newer one; put() gives it a new
		// updatedAt, so /sync delivers it
		$this->progress->put($userId, $newFileId, $locator, $p->getPercentage(), $p->getDevice(), $p->getClientUpdatedAt());
	}

	/**
	 * Moves the live annotations of the user to the new file (same uuid, so clients see the same annotation on the new book).
	 * An annotation whose page cannot be mapped keeps its text and note and only the overall position.
	 *
	 * @param list<string> $oldPages
	 * @param list<string> $hrefs
	 */
	private function moveAnnotations(string $userId, int $oldFileId, int $newFileId, string $newFormat, array $oldPages, array $hrefs): void {
		if ($this->annotations === null) {
			return;
		}
		$now = (int)(microtime(true) * 1000.0);
		foreach ($this->annotations->findByUserAndFile($userId, $oldFileId) as $annotation) {
			$old = $annotation->getLocatorArray();
			$locator = $this->mapLocator($old, $newFormat, $oldPages, $hrefs);
			if ($locator === null) {
				$locator = ['href' => ''];
				if (isset($old['locations']['totalProgression']) && is_numeric($old['locations']['totalProgression'])) {
					$locator['locations'] = ['totalProgression' => $old['locations']['totalProgression']];
				}
			}
			$annotation->setFileId($newFileId);
			$annotation->setLocator(json_encode($locator, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
			// delivered by /sync; clientUpdatedAt stays (nothing was edited)
			$annotation->setUpdatedAt($now);
			$this->annotations->update($annotation);
		}
	}

	/**
	 * Translates a locator of the old book to the page of the new book (by href, else by position).
	 *
	 * @param array<string, mixed> $locator
	 * @param list<string> $oldPages
	 * @param list<string> $hrefs
	 * @return array<string, mixed>|null null = the page cannot be found
	 */
	private function mapLocator(array $locator, string $newFormat, array $oldPages, array $hrefs): ?array {
		$locations = isset($locator['locations']) && is_array($locator['locations']) ? $locator['locations'] : [];
		$href = is_string($locator['href'] ?? null) ? explode('#', $locator['href'], 2)[0] : '';
		$index = array_search($href, $oldPages, true);
		if ($index === false && isset($locations['position']) && is_int($locations['position'])) {
			$index = $locations['position'] - 1;
		}
		if (!is_int($index) || $index < 0 || $index >= count($oldPages)) {
			return null;
		}
		$width = max(4, strlen((string)count($oldPages)));
		if ($newFormat === 'epub') {
			$newHref = $hrefs[$index] ?? null;
			$type = 'application/xhtml+xml';
		} else {
			$ext = strtolower(pathinfo($oldPages[$index], PATHINFO_EXTENSION));
			$newHref = sprintf('%0' . $width . 'd.%s', $index + 1, $ext === 'jpeg' ? 'jpg' : $ext);
			if (isset($hrefs[$index]) && $hrefs[$index] !== '') {
				// optimized comics: the page may have a new extension
				$newHref = $hrefs[$index];
				$ext = strtolower(pathinfo($newHref, PATHINFO_EXTENSION));
			}
			$type = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
		}
		if ($newHref === null) {
			return null;
		}
		$newLocations = ['position' => $index + 1, 'progression' => 0];
		if (isset($locations['totalProgression']) && (is_float($locations['totalProgression']) || is_int($locations['totalProgression']))) {
			$newLocations['totalProgression'] = $locations['totalProgression'];
		}
		return ['href' => $newHref, 'type' => $type, 'locations' => $newLocations];
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
