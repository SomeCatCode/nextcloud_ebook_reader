<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\AppInfo\Application;
use OCA\EbookReader\BackgroundJob\WriteMetadataJob;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Tag;
use OCA\EbookReader\Editor\BookEditorInterface;
use OCA\EbookReader\Editor\CbzEditor;
use OCA\EbookReader\Editor\EditConflictException;
use OCA\EbookReader\Editor\EditForbiddenException;
use OCA\EbookReader\Editor\EditorException;
use OCA\EbookReader\Editor\EditorUtil;
use OCA\EbookReader\Editor\EditRequest;
use OCA\EbookReader\Editor\EpubEditor;
use OCA\EbookReader\Editor\Fb2Editor;
use OCA\EbookReader\Editor\InvalidEditRequestException;
use OCA\EbookReader\Metadata\HtmlSanitizer;
use OCA\EbookReader\Metadata\MetadataService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IAppConfig;
use OCP\ITempManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/** Owner: W3 */
class EditorService {
	public const CONFIG_MAX_EDIT_SIZE_MB = 'max_edit_size_mb';
	public const DEFAULT_MAX_EDIT_SIZE_MB = 500;

	/** Formats whose files can be rewritten by this app. */
	private const WRITABLE = ['epub', 'cbz', 'fb2', 'fbz'];
	/** Formats that only support metadata edits in the database. */
	private const DB_ONLY = ['mobi', 'azw3', 'cbr', 'cb7', 'cbt'];
	/** Capabilities of the formats this app can write (static, so the metadata part needs no file access). */
	private const WRITABLE_CAPABILITIES = ['metadata' => true, 'cover' => true, 'content' => true, 'toc' => true, 'writesFile' => true];
	private const DB_ONLY_CAPABILITIES = ['metadata' => true, 'cover' => false, 'content' => false, 'toc' => false, 'writesFile' => false];
	public const PARTS = ['all', 'metadata'];
	private const METADATA_KEYS = ['title', 'authors', 'series', 'seriesIndex', 'description', 'language', 'publisher', 'isbn', 'publishedAt', 'genres', 'tags'];

	/** @var list<BookEditorInterface> */
	private array $editors;

	public function __construct(
		private LibraryService $library,
		private ProgressService $progress,
		private BookMapper $bookMapper,
		private MetadataService $metadata,
		private SettingsService $settings,
		private RenameService $renamer,
		private IRootFolder $rootFolder,
		private ITempManager $tempManager,
		private IAppConfig $appConfig,
		private IFilenameValidator $filenameValidator,
		private LoggerInterface $logger,
		private IJobList $jobList,
		private ArchiveCache $archiveCache,
	) {
		$this->editors = [new EpubEditor(), new CbzEditor(), new Fb2Editor()];
	}

	/**
	 * @param string $parts "all" reads the file (items, toc); "metadata" is answered from the database and does not touch the file
	 * @return array<string, mixed> Structure
	 * @throws NotFoundException
	 * @throws EditorException
	 */
	public function getStructure(string $userId, int $fileId, string $parts = 'all'): array {
		if (!in_array($parts, self::PARTS, true)) {
			throw new InvalidEditRequestException('"parts" must be "all" or "metadata".');
		}
		if ($parts === 'all') {
			// the editor works on the file: make sure pending metadata edits are in it, so that the etag is current
			$this->flushPendingWrite($userId, $fileId);
		}
		$file = $this->library->getFileForUser($userId, $fileId);
		$format = $this->formatOf($file);
		$book = $this->findBook($userId, $fileId);
		$writable = in_array($format, self::WRITABLE, true);

		if ($parts === 'metadata') {
			return [
				'fileId' => $fileId,
				'format' => $format,
				'etag' => (string)$file->getEtag(),
				'editable' => $writable ? $file->isUpdateable() : true,
				'capabilities' => $writable ? self::WRITABLE_CAPABILITIES : self::DB_ONLY_CAPABILITIES,
				'metadata' => $book !== null ? $this->metadataOf($book) : $this->emptyMetadata(),
				'items' => [],
				'toc' => [],
				'warnings' => [],
				'partial' => true,
			];
		}

		if ($writable) {
			$this->assertSize($file);
			$editor = $this->editorFor($format);
			$path = $this->archiveCache->localPath($file);
			try {
				$structure = $editor->readStructure($path, $format);
			} finally {
				$this->archiveCache->release($path);
			}
			$editable = $file->isUpdateable();
		} else {
			$structure = [
				'capabilities' => self::DB_ONLY_CAPABILITIES,
				'metadata' => $this->emptyMetadata(),
				'items' => [],
				'toc' => [],
				'warnings' => [],
			];
			$editable = true;
		}
		if ($book !== null) {
			$structure['metadata'] = $this->metadataOf($book);
		}
		return [
			'fileId' => $fileId,
			'format' => $format,
			'etag' => (string)$file->getEtag(),
			'editable' => $editable,
			'capabilities' => $structure['capabilities'],
			'metadata' => $structure['metadata'],
			'items' => $structure['items'],
			'toc' => $structure['toc'],
			'warnings' => $structure['warnings'],
			'partial' => false,
		];
	}

	/**
	 * Everything that can be checked without touching the file content: format, etag, permissions, size, request
	 * shape. Used synchronously before an asynchronous save is queued, and at the start of save().
	 *
	 * @param array<string, mixed> $request EditRequest as array
	 * @return array{0: File, 1: string, 2: EditRequest, 3: array<string, mixed>} file, format, normalized request, metadata patch
	 * @throws EditorException
	 * @throws NotFoundException
	 */
	private function checkSave(string $userId, int $fileId, array $request): array {
		$file = $this->library->getFileForUser($userId, $fileId);
		$format = $this->formatOf($file);
		if (!in_array($format, self::WRITABLE, true)) {
			throw new EditorException('Dieses Format kann nicht in der Datei bearbeitet werden.', 422);
		}
		$req = EditRequest::fromArray($request);
		if ($req->etag === '') {
			throw new InvalidEditRequestException('etag is required.');
		}
		if ($req->etag !== (string)$file->getEtag()) {
			throw new EditConflictException('Die Datei wurde zwischenzeitlich geändert.');
		}
		$patch = [];
		if ($req->metadata !== null) {
			$req = $this->withNormalizedMetadata($req);
			$patch = $this->normalizePatch($req->metadata);
		}
		if (!$req->saveAsCopy && !$file->isUpdateable()) {
			throw new EditForbiddenException('Die Datei ist schreibgeschützt. Speichere stattdessen eine Kopie.');
		}
		$this->assertSize($file);
		return [$file, $format, $req, $patch];
	}

	/**
	 * Synchronous part of an asynchronous save: throws the same errors save() would throw before it starts writing.
	 *
	 * @param array<string, mixed> $request EditRequest as array
	 * @throws EditorException
	 * @throws NotFoundException
	 */
	public function validateSave(string $userId, int $fileId, array $request): void {
		$this->checkSave($userId, $fileId, $request);
	}

	/**
	 * @param array<string, mixed> $request EditRequest as array
	 * @param ?callable(float, string): void $progress optional progress callback
	 * @return array{book: Book, warnings: list<string>}
	 * @throws EditConflictException
	 */
	public function save(string $userId, int $fileId, array $request, ?callable $progress = null): array {
		[$file, $format, $req, $patch] = $this->checkSave($userId, $fileId, $request);
		$pending = $this->jobList->has(WriteMetadataJob::class, WriteMetadataJob::argument($userId, $fileId));
		if ($pending) {
			// edits that are only in the database so far must not get lost: the request patch goes on top of them
			$book = $this->findBook($userId, $fileId);
			if ($book !== null) {
				$req = new EditRequest(
					etag: $req->etag,
					saveAsCopy: $req->saveAsCopy,
					metadata: array_merge($this->metadataOf($book), $patch),
					cover: $req->cover,
					order: $req->order,
					removed: $req->removed,
					toc: $req->toc,
				);
			}
		}
		$result = $this->write($userId, $file, $format, $req, $progress);
		if ($pending && !$req->saveAsCopy) {
			$this->jobList->remove(WriteMetadataJob::class, WriteMetadataJob::argument($userId, $fileId));
		}
		return $result;
	}

	/**
	 * Saves a metadata patch. Depending on the user's "metadataWriteMode" the file is written right away, later in one
	 * background job, or never; the database is always up to date when this returns.
	 *
	 * @param array<string, mixed> $metadataPatch
	 * @return array{book: Book, warnings: list<string>, writeQueued: bool}
	 * @throws DoesNotExistException
	 */
	public function saveMetadataOnly(string $userId, int $fileId, array $metadataPatch): array {
		$file = $this->library->getFileForUser($userId, $fileId);
		$format = $this->formatOf($file);
		$book = $this->library->getBook($userId, $fileId);
		$patch = $this->normalizePatch($metadataPatch);
		$current = $this->metadataOf($book);
		$merged = array_merge($current, $patch);
		if ($this->sameMetadata($current, $merged, false)) {
			return ['book' => $book, 'warnings' => [], 'writeQueued' => false];
		}

		$mode = (string)($this->settings->get($userId)['metadataWriteMode'] ?? SettingsService::DEFAULT_METADATA_WRITE_MODE);
		$warnings = [];
		if (in_array($format, self::WRITABLE, true) && $mode !== 'never') {
			if ($file->isUpdateable() && $this->sizeOk($file)) {
				if ($mode === 'immediate') {
					$req = new EditRequest(etag: (string)$file->getEtag(), saveAsCopy: false, metadata: $merged);
					$result = $this->write($userId, $file, $format, $req);
					if (array_key_exists('description', $patch)) {
						// the file holds plain text; keep the sanitized HTML in the database
						$this->applyDescription($result['book'], $patch['description']);
					}
					return ['book' => $result['book'], 'warnings' => $result['warnings'], 'writeQueued' => false];
				}
				// background: the library is updated now, the file follows in one job (identical jobs are merged)
				$book = $this->updateDatabase($book, $merged, Tag::SOURCE_FILE);
				$this->jobList->add(WriteMetadataJob::class, WriteMetadataJob::argument($userId, $fileId));
				return ['book' => $book, 'warnings' => [], 'writeQueued' => true];
			}
			$warnings[] = 'Die Datei ist schreibgeschützt oder zu groß; die Änderungen wurden nur in der Bibliothek gespeichert.';
		}
		// the file stays as it is: remember the edited fields so a re-index of the file does not overwrite them
		$changed = $this->changedFields($current, $merged, false);
		return ['book' => $this->updateDatabase($book, $merged, Tag::SOURCE_APP, $changed), 'warnings' => $warnings, 'writeQueued' => false];
	}

	/**
	 * Writes the metadata stored in the library into the file if the file differs from it. Used by WriteMetadataJob
	 * and (synchronously) before the editor reads the file.
	 *
	 * @return bool whether the file was written
	 */
	public function writePendingMetadata(string $userId, int $fileId): bool {
		try {
			$file = $this->library->getFileForUser($userId, $fileId);
		} catch (\Throwable) {
			return false; // user or file is gone
		}
		$format = $this->formatOf($file);
		if (!in_array($format, self::WRITABLE, true)) {
			return false;
		}
		$book = $this->findBook($userId, $fileId);
		if ($book === null) {
			return false;
		}
		$db = $this->metadataOf($book);
		if (!$file->isUpdateable() || !$this->sizeOk($file)) {
			// the file can not be written (any more): the edits stay in the library for good
			$comparable = $this->comparable($db, false);
			$this->addOverrides($book, array_values(array_filter(
				Book::OVERRIDABLE_FIELDS,
				static fn (string $f): bool => $comparable[$f] !== null && $comparable[$f] !== [],
			)));
			return false;
		}

		$path = $this->archiveCache->localPath($file);
		try {
			$fileMeta = $this->metadata->extractLocal($path, $format, $file->getName());
		} finally {
			$this->archiveCache->release($path);
		}
		$fromFile = [
			'title' => $fileMeta->title,
			'authors' => $fileMeta->authors,
			'series' => $fileMeta->series,
			'seriesIndex' => $fileMeta->seriesIndex,
			'description' => $fileMeta->description,
			'language' => $fileMeta->language,
			'publisher' => $fileMeta->publisher,
			'isbn' => $fileMeta->isbn,
			'publishedAt' => $fileMeta->publishedAt,
			'genres' => array_merge($fileMeta->genres, $fileMeta->subjects),
			'tags' => $fileMeta->tags,
		];
		if ($this->sameMetadata($db, $fromFile, true)) {
			return false;
		}

		$req = new EditRequest(etag: (string)$file->getEtag(), saveAsCopy: false, metadata: $db);
		$result = $this->write($userId, $file, $format, $req);
		// the file holds plain text; keep the sanitized HTML in the database
		$this->applyDescription($result['book'], $db['description']);
		return true;
	}

	/**
	 * Drops the "edited in the app" flag of one field (or all) and takes the value from the file again.
	 *
	 * @throws InvalidEditRequestException unknown field
	 * @throws DoesNotExistException
	 * @throws NotFoundException
	 */
	public function resetOverrides(string $userId, int $fileId, ?string $field): Book {
		if ($field !== null && !in_array($field, Book::OVERRIDABLE_FIELDS, true)) {
			throw new InvalidEditRequestException('Unknown field: ' . $field);
		}
		// pending edits are written first; the re-index would otherwise keep the library values
		$this->flushPendingWrite($userId, $fileId);
		return $this->library->resetOverrides($userId, $fileId, $field);
	}

	/** Runs a pending background write right now (and drops its job), so the editor sees the current file. */
	private function flushPendingWrite(string $userId, int $fileId): void {
		$arg = WriteMetadataJob::argument($userId, $fileId);
		if (!$this->jobList->has(WriteMetadataJob::class, $arg)) {
			return;
		}
		$this->jobList->remove(WriteMetadataJob::class, $arg);
		try {
			$this->writePendingMetadata($userId, $fileId);
		} catch (\Throwable $e) {
			$this->logger->info('Pending metadata could not be written for file ' . $fileId . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
			$this->jobList->add(WriteMetadataJob::class, $arg);
		}
	}

	/**
	 * Adds/removes genres and tags of several books.
	 * @param array<string, mixed> $body {fileIds[], addGenres[], removeGenres[], addTags[], removeTags[]}
	 * @return array{updated: int, failed: list<array{fileId: int, error: string}>, writeQueued: bool}
	 */
	public function bulkTags(string $userId, array $body): array {
		$list = static function (string $k) use ($body): array {
			$v = $body[$k] ?? [];
			$out = [];
			foreach (is_array($v) ? $v : [] as $x) {
				if (is_scalar($x) && trim((string)$x) !== '') {
					$out[] = trim((string)$x);
				}
			}
			return $out;
		};
		$ids = [];
		foreach (is_array($body['fileIds'] ?? null) ? $body['fileIds'] : [] as $id) {
			if (is_int($id) || (is_string($id) && ctype_digit($id))) {
				$ids[(int)$id] = true;
			}
		}
		if ($ids === []) {
			throw new InvalidEditRequestException('fileIds must not be empty.');
		}
		if (count($ids) > 500) {
			throw new InvalidEditRequestException('Too many files.');
		}
		[$addG, $remG, $addT, $remT] = [$list('addGenres'), $list('removeGenres'), $list('addTags'), $list('removeTags')];
		$lower = static fn (array $a): array => array_map('mb_strtolower', $a);

		$updated = 0;
		$queued = false;
		$failed = [];
		foreach (array_keys($ids) as $fileId) {
			try {
				$book = $this->library->getBook($userId, $fileId);
				$cur = $this->metadataOf($book);
				$genres = $this->mergeSet($cur['genres'], $addG, $lower($remG));
				$tags = $this->mergeSet($cur['tags'], $addT, $lower($remT));
				if ($genres === $cur['genres'] && $tags === $cur['tags']) {
					$updated++;
					continue;
				}
				$res = $this->saveMetadataOnly($userId, $fileId, ['genres' => $genres, 'tags' => $tags]);
				$queued = $queued || $res['writeQueued'];
				$updated++;
			} catch (\Throwable $e) {
				$this->logger->info('Bulk tagging failed for file ' . $fileId . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
				$failed[] = ['fileId' => $fileId, 'error' => $e instanceof EditorException || $e instanceof DoesNotExistException || $e instanceof NotFoundException ? $e->getMessage() : 'Fehler beim Speichern.'];
			}
		}
		return ['updated' => $updated, 'failed' => $failed, 'writeQueued' => $queued];
	}

	/**
	 * @throws EditorException
	 * @throws NotFoundException
	 */
	public function rename(string $userId, int $fileId, ?string $name, bool $usePattern): Book {
		$file = $this->library->getFileForUser($userId, $fileId);
		$book = $this->library->getBook($userId, $fileId);
		$ext = $this->extensionOf($file->getName());

		if ($usePattern) {
			$pattern = (string)($this->settings->get($userId)['filenamePattern'] ?? '{author} - {title}');
			$base = $this->renamer->buildFilename($book, $pattern === '' ? '{author} - {title}' : $pattern);
		} else {
			$base = trim((string)$name);
			if ($ext !== '' && str_ends_with(mb_strtolower($base), mb_strtolower($ext))) {
				$base = substr($base, 0, -strlen($ext));
			}
			$base = $this->renamer->sanitize($base);
		}
		if ($base === '') {
			throw new InvalidEditRequestException('The file name must not be empty.');
		}
		try {
			$base = $this->filenameValidator->sanitizeFilename($base);
		} catch (\InvalidArgumentException $e) {
			throw new InvalidEditRequestException('The file name is not valid.', $e);
		}
		if ($base === '' || trim($base, '. ') === '') {
			throw new InvalidEditRequestException('The file name is not valid.');
		}

		$parent = $file->getParent();
		$current = $file->getName();
		$candidate = $base . $ext;
		if ($candidate !== $current) {
			if (!$file->isUpdateable() || !$file->isDeletable()) {
				throw new EditForbiddenException('Die Datei darf nicht umbenannt werden.');
			}
			$n = 1;
			while ($parent->nodeExists($candidate)) {
				$n++;
				$candidate = $base . ' (' . $n . ')' . $ext;
				if ($n > 1000) {
					throw new EditorException('No free file name found.', 409);
				}
			}
			try {
				$this->filenameValidator->validateFilename($candidate);
				$file->move($parent->getPath() . '/' . $candidate);
			} catch (NotPermittedException $e) {
				throw new EditForbiddenException('Die Datei darf nicht umbenannt werden.', $e);
			} catch (\OCP\Files\InvalidPathException $e) {
				throw new InvalidEditRequestException('The file name is not valid: ' . $e->getMessage(), $e);
			}
			$this->library->reindexFileForAllUsers($fileId);
		}
		return $this->library->getBook($userId, $fileId);
	}

	// ------------------------------------------------------------------ internals

	/**
	 * @param ?callable(float, string): void $progress
	 * @return array{book: Book, warnings: list<string>}
	 */
	private function write(string $userId, File $file, string $format, EditRequest $req, ?callable $progress = null): array {
		if (!$req->saveAsCopy && !$file->isUpdateable()) {
			throw new EditForbiddenException('Die Datei ist schreibgeschützt. Speichere stattdessen eine Kopie.');
		}
		$this->assertSize($file);
		$editor = $this->editorFor($format);
		$fileId = $file->getId();

		$src = null;
		$dst = null;
		$lock = new \ArrayObject(['held' => 0]);
		try {
			try {
				$file->lock(ILockingProvider::LOCK_SHARED);
				$lock['held'] = 1;
			} catch (LockedException $e) {
				throw new EditorException('Die Datei wird gerade verwendet. Bitte später erneut versuchen.', 423, $e);
			}
			$this->assertEtag($userId, $fileId, $req->etag);

			if ($progress !== null) {
				$progress(0.0, 'Preparing');
			}
			$src = $this->archiveCache->localPath($file);
			$dst = $this->tempManager->getTemporaryFile('.' . $format);
			if ($dst === false) {
				throw new EditorException('Cannot create a temporary file.', 500);
			}
			// the editor reports 0..1; the last part of the bar is for verification and saving
			$editorProgress = $progress === null ? null : static function (float $fraction, string $step) use ($progress): void {
				$progress($fraction * 0.9, $step);
			};
			try {
				$result = $editor->write($src, $dst, $req, $editorProgress);
			} finally {
				$this->archiveCache->release($src);
				$src = null;
			}
			if ($progress !== null) {
				$progress(0.92, 'Verifying file');
			}

			// verify with the regular extractor
			try {
				$this->metadata->extractLocal($dst, $format);
			} catch (\Throwable $e) {
				throw new EditorException('Die bearbeitete Datei konnte nicht überprüft werden; das Original bleibt unverändert.', 500, $e);
			}

			if ($lock['held'] === 1) {
				$file->unlock(ILockingProvider::LOCK_SHARED);
				$lock['held'] = 0;
			}

			if ($req->saveAsCopy) {
				$new = $this->writeCopy($userId, $file, $dst);
				$book = $this->library->indexFile($userId, $new, true);
				if ($book === null) {
					throw new EditorException('Die Kopie konnte nicht indexiert werden.', 500);
				}
				return ['book' => $book, 'warnings' => $result['warnings']];
			}

			// last check right before writing; putContent takes the exclusive lock itself
			$this->assertEtag($userId, $fileId, $req->etag);
			if ($progress !== null) {
				$progress(0.96, 'Saving file');
			}
			$stream = fopen($dst, 'rb');
			if ($stream === false) {
				throw new EditorException('Cannot read the temporary file.', 500);
			}
			try {
				$file->putContent($stream);
			} finally {
				// putContent() closes the stream itself (View::file_put_contents)
				/** @psalm-suppress RedundantCondition psalm does not know the stream was closed by Nextcloud */
				if (is_resource($stream)) {
					fclose($stream);
				}
			}
			$this->library->reindexFileForAllUsers($fileId);
			$this->progress->remapAfterEdit($fileId, $result['itemMap']);
			// the file now holds these fields: they are no longer "edited in the app only"
			$this->clearOverrides($userId, $fileId, array_keys($req->metadata ?? []));
			return ['book' => $this->library->getBook($userId, $fileId), 'warnings' => $result['warnings']];
		} catch (LockedException $e) {
			throw new EditorException('Die Datei wird gerade verwendet. Bitte später erneut versuchen.', 423, $e);
		} catch (NotPermittedException $e) {
			throw new EditForbiddenException('Keine Berechtigung zum Schreiben.', $e);
		} finally {
			if ($lock['held'] === 1) {
				try {
					$file->unlock(ILockingProvider::LOCK_SHARED);
				} catch (\Throwable) {
				}
			}
			if ($src !== null) {
				$this->archiveCache->release($src);
			}
			if (is_string($dst) && $dst !== '') {
				@unlink($dst);
			}
		}
	}

	/** Creates "<name> (bearbeitet).<ext>" next to the original (or in the user's root if not writable). */
	private function writeCopy(string $userId, File $file, string $localPath): File {
		$folder = $file->getParent();
		if (!$folder->isCreatable()) {
			$folder = $this->rootFolder->getUserFolder($userId);
		}
		$ext = $this->extensionOf($file->getName());
		$base = $ext !== '' ? substr($file->getName(), 0, -strlen($ext)) : $file->getName();
		$name = $folder->getNonExistingName($base . ' (bearbeitet)' . $ext);
		$stream = fopen($localPath, 'rb');
		if ($stream === false) {
			throw new EditorException('Cannot read the temporary file.', 500);
		}
		try {
			$new = $folder->newFile($name, $stream);
		} finally {
			// newFile() closes the stream itself
			/** @psalm-suppress RedundantCondition psalm does not know the stream was closed by Nextcloud */
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
		return $new;
	}

	private function assertEtag(string $userId, int $fileId, string $etag): void {
		$fresh = $this->library->getFileForUser($userId, $fileId);
		if ((string)$fresh->getEtag() !== $etag) {
			throw new EditConflictException('Die Datei wurde zwischenzeitlich geändert.');
		}
	}

	private function maxBytes(): int {
		$mb = $this->appConfig->getValueInt(Application::APP_ID, self::CONFIG_MAX_EDIT_SIZE_MB, self::DEFAULT_MAX_EDIT_SIZE_MB);
		return max(1, $mb) * 1024 * 1024;
	}

	private function sizeOk(File $file): bool {
		return $file->getSize() <= $this->maxBytes();
	}

	private function assertSize(File $file): void {
		if (!$this->sizeOk($file)) {
			throw new EditorException('Die Datei ist zu groß zum Bearbeiten (Limit ' . intdiv($this->maxBytes(), 1024 * 1024) . ' MB).', 413);
		}
	}

	private function editorFor(string $format): BookEditorInterface {
		foreach ($this->editors as $e) {
			if ($e->supports($format)) {
				return $e;
			}
		}
		throw new EditorException('Format not supported: ' . $format, 415);
	}

	private function formatOf(File $file): string {
		$name = strtolower($file->getName());
		if (str_ends_with($name, '.fb2.zip') || str_ends_with($name, '.fbz')) {
			return 'fbz';
		}
		$ext = pathinfo($name, PATHINFO_EXTENSION);
		if (in_array($ext, ['epub', 'cbz', 'cbr', 'fb2', 'mobi', 'azw3'], true)) {
			return $ext;
		}
		if ($ext === 'azw') {
			return 'azw3';
		}
		$detected = $this->metadata->detectFormat($file->getName(), $file->getMimeType());
		if ($detected === null || !in_array($detected, array_merge(self::WRITABLE, self::DB_ONLY), true)) {
			throw new EditorException('Not an e-book.', 415);
		}
		return $detected;
	}

	/** Extension including the dot; ".fb2.zip" is kept as one extension. */
	private function extensionOf(string $name): string {
		if (str_ends_with(strtolower($name), '.fb2.zip')) {
			return substr($name, -8);
		}
		$ext = pathinfo($name, PATHINFO_EXTENSION);
		return $ext === '' ? '' : '.' . $ext;
	}

	private function findBook(string $userId, int $fileId): ?Book {
		try {
			return $this->library->getBook($userId, $fileId);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return array<string, mixed> */
	private function emptyMetadata(): array {
		return ['title' => null, 'authors' => [], 'series' => null, 'seriesIndex' => null, 'description' => null, 'language' => null, 'publisher' => null, 'isbn' => null, 'publishedAt' => null, 'genres' => [], 'tags' => []];
	}

	/** @return array<string, mixed> */
	private function metadataOf(Book $book): array {
		$genres = [];
		$tags = [];
		foreach ($this->library->getTags($book->getId()) as $t) {
			if ($t->getType() === Tag::TYPE_GENRE) {
				$genres[] = $t->getName();
			} else {
				$tags[] = $t->getName();
			}
		}
		return [
			'title' => $book->getTitle(),
			'authors' => $book->getAuthorsArray(),
			'series' => $book->getSeries(),
			'seriesIndex' => $book->getSeriesIndex(),
			'description' => $book->getDescription(),
			'language' => $book->getLanguage(),
			'publisher' => $book->getPublisher(),
			'isbn' => $book->getIsbn(),
			'publishedAt' => $book->getPublishedAt(),
			'genres' => $genres,
			'tags' => $tags,
		];
	}

	/**
	 * Validates and normalizes a (partial) metadata array. Unknown keys are dropped.
	 * @param array<string, mixed> $in
	 * @return array<string, mixed>
	 */
	private function normalizePatch(array $in): array {
		$out = [];
		foreach (self::METADATA_KEYS as $key) {
			if (!array_key_exists($key, $in)) {
				continue;
			}
			$v = $in[$key];
			switch ($key) {
				case 'authors':
				case 'genres':
				case 'tags':
					if (!is_array($v)) {
						throw new InvalidEditRequestException('"' . $key . '" must be a list.');
					}
					$list = [];
					foreach ($v as $x) {
						if (!is_scalar($x)) {
							throw new InvalidEditRequestException('"' . $key . '" must be a list of strings.');
						}
						$s = trim((string)$x);
						if ($s !== '' && !in_array($s, $list, true)) {
							$list[] = mb_substr($s, 0, $key === 'authors' ? 255 : 128);
						}
					}
					$out[$key] = $list;
					break;
				case 'seriesIndex':
					if ($v === null || $v === '') {
						$out[$key] = null;
					} elseif (is_numeric($v)) {
						$out[$key] = (float)$v;
					} else {
						throw new InvalidEditRequestException('"seriesIndex" must be a number.');
					}
					break;
				default:
					if ($v === null) {
						$out[$key] = null;
					} elseif (is_scalar($v)) {
						$s = trim((string)$v);
						$out[$key] = $s === '' ? null : $s;
					} else {
						throw new InvalidEditRequestException('"' . $key . '" must be a string.');
					}
			}
		}
		if (isset($out['description']) && is_string($out['description'])) {
			$out['description'] = HtmlSanitizer::sanitize($out['description']);
		}
		return $out;
	}

	private function withNormalizedMetadata(EditRequest $req): EditRequest {
		return new EditRequest(
			etag: $req->etag,
			saveAsCopy: $req->saveAsCopy,
			metadata: $this->normalizePatch($req->metadata ?? []),
			cover: $req->cover,
			order: $req->order,
			removed: $req->removed,
			toc: $req->toc,
		);
	}

	/**
	 * @param list<string> $current
	 * @param list<string> $add
	 * @param list<string> $removeLower lower-case names to remove
	 * @return list<string>
	 */
	private function mergeSet(array $current, array $add, array $removeLower): array {
		$out = [];
		foreach (array_merge($current, $add) as $name) {
			if (in_array(mb_strtolower($name), $removeLower, true) && !in_array($name, $add, true)) {
				continue;
			}
			if (!in_array($name, $out, true)) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Normalised comparison of two metadata arrays. genres/tags are compared order-insensitively (case-insensitive).
	 * $fileSide: $b comes from a file extractor; description is compared as plain text and genres+tags as one set,
	 * because the file does not distinguish them the way the library does.
	 *
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	private function sameMetadata(array $a, array $b, bool $fileSide): bool {
		return $this->comparable($a, $fileSide) === $this->comparable($b, $fileSide);
	}

	/**
	 * @param array<string, mixed> $m
	 * @return array<string, mixed>
	 */
	private function comparable(array $m, bool $asText): array {
		$str = static function (mixed $v): ?string {
			if (!is_scalar($v)) {
				return null;
			}
			$v = trim((string)$v);
			return $v === '' ? null : $v;
		};
		$set = static function (mixed ...$lists): array {
			$out = [];
			foreach ($lists as $list) {
				foreach (is_array($list) ? $list : [] as $x) {
					if (is_scalar($x) && trim((string)$x) !== '') {
						$out[mb_strtolower(trim((string)$x))] = true;
					}
				}
			}
			$keys = array_map('strval', array_keys($out));
			sort($keys);
			return $keys;
		};
		$authors = [];
		foreach (is_array($m['authors'] ?? null) ? $m['authors'] : [] as $x) {
			if (is_scalar($x) && trim((string)$x) !== '') {
				$authors[] = trim((string)$x);
			}
		}
		$description = $str($m['description'] ?? null);
		if ($asText) {
			$description = $str(EditorUtil::htmlToText($description));
		}
		$publishedAt = $str($m['publishedAt'] ?? null);
		$index = $m['seriesIndex'] ?? null;
		$out = [
			'title' => $str($m['title'] ?? null),
			'authors' => $authors,
			'series' => $str($m['series'] ?? null),
			'seriesIndex' => is_numeric($index) ? round((float)$index, 4) : null,
			'description' => $description,
			'language' => $str($m['language'] ?? null),
			'publisher' => $str($m['publisher'] ?? null),
			'isbn' => $str($m['isbn'] ?? null),
			'publishedAt' => $publishedAt === null ? null : substr($publishedAt, 0, 10),
		];
		if ($asText) {
			$out['labels'] = $set($m['genres'] ?? null, $m['tags'] ?? null);
		} else {
			$out['genres'] = $set($m['genres'] ?? null);
			$out['tags'] = $set($m['tags'] ?? null);
		}
		return $out;
	}

	/**
	 * Overridable fields whose normalised value differs between two metadata arrays.
	 *
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 * @return list<string>
	 */
	private function changedFields(array $a, array $b, bool $asText): array {
		$ca = $this->comparable($a, $asText);
		$cb = $this->comparable($b, $asText);
		return array_values(array_filter(Book::OVERRIDABLE_FIELDS, static fn (string $f): bool => $ca[$f] !== $cb[$f]));
	}

	/** @param list<string> $fields */
	private function addOverrides(Book $book, array $fields): void {
		$merged = array_values(array_unique(array_merge($book->getOverridesArray(), $fields)));
		if ($merged === $book->getOverridesArray()) {
			return;
		}
		$book->setOverridesArray($merged);
		$book->setUpdatedAt((int)(microtime(true) * 1000.0));
		$this->bookMapper->update($book);
	}

	/** @param list<string> $fields */
	private function clearOverrides(string $userId, int $fileId, array $fields): void {
		if ($fields === []) {
			return;
		}
		$book = $this->findBook($userId, $fileId);
		if ($book === null) {
			return;
		}
		$current = $book->getOverridesArray();
		$rest = array_values(array_diff($current, $fields));
		if ($rest === $current) {
			return;
		}
		$book->setOverridesArray($rest);
		$book->setUpdatedAt((int)(microtime(true) * 1000.0));
		$this->bookMapper->update($book);
	}

	private function applyDescription(Book $book, mixed $description): void {
		$book->setDescription(is_string($description) && $description !== '' ? $description : null);
		$book->setUpdatedAt((int)(microtime(true) * 1000.0));
		$this->bookMapper->update($book);
	}

	/**
	 * Stores metadata in the database. $source is the tag source: "app" when the file stays untouched, "file" when the
	 * values are (about to be) written into the file.
	 * @param array<string, mixed> $meta complete metadata
	 * @param list<string> $addOverrides fields to flag as "edited in the app only" (survive re-indexing)
	 */
	private function updateDatabase(Book $book, array $meta, string $source, array $addOverrides = []): Book {
		$authors = is_array($meta['authors'] ?? null) ? array_values(array_map('strval', $meta['authors'])) : [];
		$book->setTitle(isset($meta['title']) ? (string)$meta['title'] : null);
		$book->setAuthorsArray($authors);
		$book->setSeries(isset($meta['series']) ? (string)$meta['series'] : null);
		$book->setSeriesIndex(isset($meta['seriesIndex']) && is_numeric($meta['seriesIndex']) ? (float)$meta['seriesIndex'] : null);
		$book->setDescription(isset($meta['description']) ? (string)$meta['description'] : null);
		$book->setLanguage(isset($meta['language']) ? (string)$meta['language'] : null);
		$book->setPublisher(isset($meta['publisher']) ? (string)$meta['publisher'] : null);
		$book->setIsbn(isset($meta['isbn']) ? (string)$meta['isbn'] : null);
		$book->setPublishedAt(isset($meta['publishedAt']) ? substr((string)$meta['publishedAt'], 0, 10) : null);
		if ($addOverrides !== []) {
			$book->setOverridesArray(array_merge($book->getOverridesArray(), $addOverrides));
		}
		$book->setUpdatedAt((int)(microtime(true) * 1000.0));
		$this->bookMapper->update($book);

		foreach ([Tag::TYPE_GENRE => 'genres', Tag::TYPE_TAG => 'tags'] as $type => $key) {
			$names = is_array($meta[$key] ?? null) ? array_values(array_map('strval', $meta[$key])) : [];
			// the user's list is authoritative: clear entries of the other source and store everything under $source
			$other = $source === Tag::SOURCE_FILE ? Tag::SOURCE_APP : Tag::SOURCE_FILE;
			$this->library->setTags($book->getId(), $type, [], $other);
			$this->library->setTags($book->getId(), $type, $names, $source);
		}
		return $book;
	}
}
