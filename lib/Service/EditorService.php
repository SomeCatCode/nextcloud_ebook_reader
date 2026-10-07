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
use OCA\EbookReader\Metadata\SidecarChangedException;
use OCA\EbookReader\Metadata\SidecarService;
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
	public const BULK_MAX_FILES = 500;
	private const BULK_MAX_AUTHORS = 50;
	/** How often a metadata save re-reads the sidecar and tries again when somebody else changed it in the meantime. */
	private const SIDECAR_RETRIES = 2;
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
		private SidecarService $sidecar,
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
		$book = null;
		if ($pending) {
			// edits that are only in the database so far must not get lost: the request patch goes on top of them
			$book = $this->findBook($userId, $fileId);
			if ($book !== null) {
				$req = new EditRequest(
					etag: $req->etag,
					saveAsCopy: $req->saveAsCopy,
					metadata: array_merge($this->fileMetadataOf($book), $patch),
					cover: $req->cover,
					order: $req->order,
					removed: $req->removed,
					toc: $req->toc,
				);
			}
		}
		// personal (app-only) tags stay in the library: they are never written into the book or its sidecar
		$appKeys = [];
		$requestedTags = [];
		$book ??= $this->findBook($userId, $fileId);
		if ($book !== null && $req->metadata !== null && array_key_exists('tags', $req->metadata)) {
			$appKeys = $this->appTagKeys($book);
			$requestedTags = is_array($req->metadata['tags']) ? $req->metadata['tags'] : [];
			$req = $this->withFileMetadata($req, $appKeys);
		}
		$result = $this->write($userId, $file, $format, $req, $progress);
		if ($appKeys !== [] && !$req->saveAsCopy) {
			$this->syncAppTags($result['book'], $requestedTags, $appKeys);
		}
		if ($pending && !$req->saveAsCopy) {
			$this->jobList->remove(WriteMetadataJob::class, WriteMetadataJob::argument($userId, $fileId));
		}
		return $result;
	}

	/**
	 * Saves a metadata patch. The user's "metadataTarget" decides where it goes: the sidecar file (default, written right
	 * away, no access to the book file), the book file (right away or later in one background job, see "metadataWriteMode"),
	 * both, or only the library. The database is always up to date when this returns.
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

		$config = $this->settings->get($userId);
		$target = $this->targetFor($userId, $fileId, $config);
		$mode = (string)($config['metadataWriteMode'] ?? SettingsService::DEFAULT_METADATA_WRITE_MODE);
		$warnings = [];

		// The sidecar (and the book file) can be shared with other users (native folder share with edit permission). The
		// library row this edit is based on must match the sidecar as it is now; otherwise the row is refreshed first, so
		// that the fields this edit does not touch keep the values somebody else wrote. If the sidecar changes while we
		// write, the write is refused (SidecarChangedException) and the loop starts again.
		$sidecarOk = false;
		$sidecarWritten = false;
		$attempt = 0;
		while (true) {
			$book = $this->refreshIfStale($userId, $file, $book, $target, $attempt > 0);
			$current = $this->metadataOf($book);
			$merged = array_merge($current, $patch);
			if ($this->sameMetadata($current, $merged, false)) {
				return ['book' => $book, 'warnings' => [], 'writeQueued' => false];
			}
			$appKeys = $this->appTagKeys($book);
			$fileMeta = $this->forFile($merged, $appKeys);
			try {
				if ($target === 'sidecar' || $target === 'both') {
					$sidecarOk = $this->sidecar->write($file, $fileMeta, true, $book->getSidecarEtag(), true);
					$sidecarWritten = $sidecarOk;
					if (!$sidecarOk) {
						$warnings[] = 'Die Begleitdatei konnte nicht geschrieben werden (Ordner schreibgeschützt); die Änderungen wurden nur in der Bibliothek gespeichert.';
					}
				} elseif ($target === 'file') {
					// an existing sidecar must not keep the old values alive
					$this->sidecar->write($file, $fileMeta, false, $book->getSidecarEtag(), true);
				}
				break;
			} catch (SidecarChangedException) {
				if (++$attempt > self::SIDECAR_RETRIES) {
					$warnings[] = 'Die Begleitdatei wurde gleichzeitig von jemand anderem geändert; die Änderungen wurden nur in der Bibliothek gespeichert. Bitte erneut speichern.';
					$target = 'library';
					break;
				}
			}
		}

		if (($target === 'file' || $target === 'both') && in_array($format, self::WRITABLE, true)) {
			if ($file->isUpdateable() && $this->sizeOk($file)) {
				if ($mode === 'immediate') {
					$req = new EditRequest(etag: (string)$file->getEtag(), saveAsCopy: false, metadata: $fileMeta);
					$result = $this->write($userId, $file, $format, $req);
					if (array_key_exists('description', $patch)) {
						// the file holds plain text; keep the sanitized HTML in the database
						$this->applyDescription($result['book'], $patch['description']);
					}
					if (array_key_exists('tags', $patch)) {
						$this->syncAppTags($result['book'], is_array($merged['tags']) ? $merged['tags'] : [], $appKeys);
					}
					return ['book' => $result['book'], 'warnings' => array_merge($warnings, $result['warnings']), 'writeQueued' => false];
				}
				// background: the library is updated now, the file follows in one job (identical jobs are merged)
				$book->setSidecarEtag($this->sidecar->etagOf($file));
				$book = $this->updateDatabase($book, $merged, Tag::SOURCE_FILE, [], $appKeys);
				if ($sidecarWritten) {
					$this->library->reindexFileForAllUsers($fileId, $userId);
				}
				$this->jobList->add(WriteMetadataJob::class, WriteMetadataJob::argument($userId, $fileId));
				return ['book' => $book, 'warnings' => $warnings, 'writeQueued' => true];
			}
			if (!$sidecarOk) {
				$warnings[] = 'Die Datei ist schreibgeschützt oder zu groß; die Änderungen wurden nur in der Bibliothek gespeichert.';
			}
		}

		if ($sidecarOk) {
			// the sidecar holds the values now: overrides are not needed, except for fields it can not express (empty values)
			$changed = $this->changedFields($current, $merged, false);
			$clear = [];
			$add = [];
			$comparable = $this->comparable($merged, false);
			foreach ($changed as $field) {
				if ($comparable[$field] === null || $comparable[$field] === []) {
					$add[] = $field;
				} else {
					$clear[] = $field;
				}
			}
			$book->setOverridesArray(array_values(array_diff($book->getOverridesArray(), $clear)));
			$book->setSidecarEtag($this->sidecar->etagOf($file));
			$book = $this->updateDatabase($book, $merged, Tag::SOURCE_FILE, $add, $appKeys);
			// everybody else who has this file (shared folder, app share) sees the new sidecar right away
			$this->library->reindexFileForAllUsers($fileId, $userId);
			return ['book' => $book, 'warnings' => $warnings, 'writeQueued' => false];
		}
		// nothing was written anywhere: remember the edited fields so a re-index does not overwrite them
		$changed = $this->changedFields($current, $merged, false);
		return ['book' => $this->updateDatabase($book, $merged, Tag::SOURCE_APP, $changed), 'warnings' => $warnings, 'writeQueued' => false];
	}

	/**
	 * The library row of a user is based on the sidecar/file as of its last indexing. If another user changed them since
	 * (shared folder), the row is indexed again first. App edits of the row (overrides) survive that.
	 */
	private function refreshIfStale(string $userId, File $file, Book $book, string $target, bool $force): Book {
		if ($target === 'library') {
			return $book;
		}
		$fileEtag = $book->getFileEtag();
		$stale = $force
			|| $book->getSidecarEtag() !== $this->sidecar->etagOf($file)
			|| (in_array($target, ['file', 'both'], true) && $fileEtag !== null && $fileEtag !== (string)$file->getEtag());
		if (!$stale) {
			return $book;
		}
		try {
			return $this->library->indexFile($userId, $file, true) ?? $book;
		} catch (\Throwable $e) {
			$this->logger->info('Book ' . $file->getId() . ' could not be refreshed before saving: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return $book;
		}
	}

	/**
	 * Where this user's metadata edits of a book go. A book another user shared with the user through the app is not
	 * theirs: its file and sidecar belong to the owner, so edits stay in the user's own library (overrides).
	 *
	 * @param array<string, mixed> $config user settings
	 */
	private function targetFor(string $userId, int $fileId, array $config): string {
		return $this->library->isSharedWithUser($userId, $fileId) ? 'library' : $this->targetOf($config);
	}

	/**
	 * Names (lower case) of the user's app-only tags of a book. They exist only in the library of this user: never
	 * written into the book file or the sidecar, never copied to other users.
	 *
	 * @return array<string, true>
	 */
	private function appTagKeys(Book $book): array {
		$keys = [];
		foreach ($this->library->getTags($book->getId()) as $t) {
			if ($t->getType() === Tag::TYPE_TAG && $t->getSource() === Tag::SOURCE_APP) {
				$keys[mb_strtolower($t->getName())] = true;
			}
		}
		return $keys;
	}

	/**
	 * Metadata as it goes into the sidecar or the book file: without the app-only tags.
	 *
	 * @param array<string, mixed> $meta
	 * @param array<string, true> $appKeys
	 * @return array<string, mixed>
	 */
	private function forFile(array $meta, array $appKeys): array {
		if ($appKeys === [] || !is_array($meta['tags'] ?? null)) {
			return $meta;
		}
		$meta['tags'] = array_values(array_filter($meta['tags'], static fn (mixed $t): bool => !isset($appKeys[mb_strtolower((string)$t)])));
		return $meta;
	}

	/**
	 * The library metadata of a book as it belongs into the book file / sidecar (no app-only tags).
	 *
	 * @return array<string, mixed>
	 */
	private function fileMetadataOf(Book $book): array {
		return $this->forFile($this->metadataOf($book), $this->appTagKeys($book));
	}

	/**
	 * @param array<string, true> $appKeys
	 */
	private function withFileMetadata(EditRequest $req, array $appKeys): EditRequest {
		return new EditRequest(
			etag: $req->etag,
			saveAsCopy: $req->saveAsCopy,
			metadata: $this->forFile($req->metadata ?? [], $appKeys),
			cover: $req->cover,
			order: $req->order,
			removed: $req->removed,
			toc: $req->toc,
		);
	}

	/**
	 * After the tags went into the file: the app-only tags the user kept stay (and the removed ones are gone); the
	 * re-index after the file write only knows the file's tags.
	 *
	 * @param array<array-key, mixed> $requestedTags the complete tag list of the user's edit
	 * @param array<string, true> $appKeys
	 */
	private function syncAppTags(Book $book, array $requestedTags, array $appKeys): void {
		if ($appKeys === []) {
			return;
		}
		$keep = array_values(array_filter(array_map('strval', $requestedTags), static fn (string $t): bool => isset($appKeys[mb_strtolower($t)])));
		$this->library->setTags($book->getId(), Tag::TYPE_TAG, $keep, Tag::SOURCE_APP);
	}

	/**
	 * Writes the metadata of the library into the book file ("Write metadata into the book file").
	 *
	 * @param ?callable(float, string): void $progress
	 * @return array{book: Book, warnings: list<string>, written: bool}
	 * @throws EditorException unsupported format (415), read-only (403), too large (413)
	 * @throws NotFoundException
	 * @throws DoesNotExistException
	 */
	public function embedMetadata(string $userId, int $fileId, ?callable $progress = null): array {
		$file = $this->checkEmbed($userId, $fileId);
		$format = $this->formatOf($file);
		$this->flushPendingWrite($userId, $fileId);
		$book = $this->library->getBook($userId, $fileId);
		$db = $this->fileMetadataOf($book);

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
			return ['book' => $book, 'warnings' => [], 'written' => false];
		}
		$req = new EditRequest(etag: (string)$file->getEtag(), saveAsCopy: false, metadata: $db);
		$result = $this->write($userId, $file, $format, $req, $progress);
		$this->applyDescription($result['book'], $db['description']);
		return ['book' => $result['book'], 'warnings' => $result['warnings'], 'written' => true];
	}

	/**
	 * Synchronous checks of embedMetadata (also used before the task is queued).
	 *
	 * @throws EditorException
	 * @throws NotFoundException
	 */
	public function checkEmbed(string $userId, int $fileId): File {
		$file = $this->library->getFileForUser($userId, $fileId);
		$format = $this->formatOf($file);
		if (!in_array($format, self::WRITABLE, true)) {
			throw new EditorException('Dieses Format kann nicht in der Datei bearbeitet werden.', 415);
		}
		if ($this->library->isSharedWithUser($userId, $fileId)) {
			throw new EditForbiddenException('Dieses Buch wurde mit dir geteilt; deine Änderungen bleiben in deiner Bibliothek und werden nicht in die Datei des Besitzers geschrieben.');
		}
		if (!$file->isUpdateable()) {
			throw new EditForbiddenException('Die Datei ist schreibgeschützt.');
		}
		$this->assertSize($file);
		return $file;
	}

	/** @param array<string, mixed> $config user settings */
	private function targetOf(array $config): string {
		$target = $config['metadataTarget'] ?? SettingsService::DEFAULT_METADATA_TARGET;
		return is_string($target) && in_array($target, SettingsService::METADATA_TARGETS, true) ? $target : SettingsService::DEFAULT_METADATA_TARGET;
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
		$db = $this->fileMetadataOf($book);
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
		$ids = $this->bulkFileIds($body['fileIds'] ?? null);
		[$addG, $remG, $addT, $remT] = [$list('addGenres'), $list('removeGenres'), $list('addTags'), $list('removeTags')];
		$lower = static fn (array $a): array => array_map('mb_strtolower', $a);

		$res = $this->runBulk($userId, $ids, function (array $cur) use ($addG, $remG, $addT, $remT, $lower): array {
			return [
				'genres' => $this->mergeSet($cur['genres'], $addG, $lower($remG)),
				'tags' => $this->mergeSet($cur['tags'], $addT, $lower($remT)),
			];
		}, null);
		return ['updated' => $res['updated'] + $res['unchanged'], 'failed' => $res['failed'], 'writeQueued' => $res['writeQueued']];
	}

	/**
	 * Validates and normalizes a bulk metadata request. The result is itself a valid request (idempotent), so it can be
	 * stored in a task and run later.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed> normalized request (fileIds, authors, series, publisher, language, genres, tags; only the provided sections)
	 * @throws InvalidEditRequestException
	 */
	public function normalizeBulkMetadata(array $body): array {
		$out = ['fileIds' => $this->bulkFileIds($body['fileIds'] ?? null)];

		$name = static function (mixed $v, string $what, int $max): string {
			if (!is_scalar($v)) {
				throw new InvalidEditRequestException('"' . $what . '" must be a string.');
			}
			$s = trim((string)$v);
			if (mb_strlen($s) > $max) {
				throw new InvalidEditRequestException('"' . $what . '" is too long (max ' . $max . ' characters).');
			}
			return $s;
		};
		$names = static function (mixed $v, string $what, int $maxCount) use ($name): array {
			if (!is_array($v)) {
				throw new InvalidEditRequestException('"' . $what . '" must be a list.');
			}
			$list = [];
			$seen = [];
			foreach ($v as $x) {
				$s = $name($x, $what, 512);
				if ($s !== '' && !isset($seen[mb_strtolower($s)])) {
					$seen[mb_strtolower($s)] = true;
					$list[] = $s;
				}
			}
			if (count($list) > $maxCount) {
				throw new InvalidEditRequestException('Too many entries in "' . $what . '" (max ' . $maxCount . ').');
			}
			return $list;
		};
		$section = static function (string $key) use ($body): ?array {
			if (!array_key_exists($key, $body) || $body[$key] === null) {
				return null;
			}
			if (!is_array($body[$key])) {
				throw new InvalidEditRequestException('"' . $key . '" must be an object.');
			}
			return $body[$key];
		};

		$authors = $section('authors');
		if ($authors !== null) {
			$mode = $authors['mode'] ?? null;
			if (!in_array($mode, ['replace', 'add', 'remove'], true)) {
				throw new InvalidEditRequestException('"authors.mode" must be replace, add or remove.');
			}
			$values = $names($authors['values'] ?? [], 'authors.values', self::BULK_MAX_AUTHORS);
			if ($mode !== 'replace' && $values === []) {
				throw new InvalidEditRequestException('"authors.values" must not be empty.');
			}
			$out['authors'] = ['mode' => $mode, 'values' => $values];
		}

		$series = $section('series');
		if ($series !== null) {
			$mode = $series['mode'] ?? null;
			if ($mode === 'clear') {
				$out['series'] = ['mode' => 'clear'];
			} elseif ($mode === 'set') {
				$seriesName = $name($series['name'] ?? '', 'series.name', 512);
				if ($seriesName === '') {
					throw new InvalidEditRequestException('"series.name" must not be empty.');
				}
				$index = ['mode' => 'keep'];
				$idx = $series['index'] ?? null;
				if ($idx !== null) {
					if (!is_array($idx) || !in_array($idx['mode'] ?? null, ['keep', 'sequence', 'sortTitle'], true)) {
						throw new InvalidEditRequestException('"series.index.mode" must be keep, sequence or sortTitle.');
					}
					$index = ['mode' => $idx['mode']];
					if ($idx['mode'] !== 'keep') {
						$start = $idx['start'] ?? 1;
						$step = $idx['step'] ?? 1;
						if (!is_numeric($start) || (float)$start < 0 || (float)$start > 1000000) {
							throw new InvalidEditRequestException('"series.index.start" must be a number >= 0.');
						}
						if (!is_numeric($step) || (float)$step <= 0 || (float)$step > 1000000) {
							throw new InvalidEditRequestException('"series.index.step" must be a number > 0.');
						}
						$index['start'] = (float)$start;
						$index['step'] = (float)$step;
					}
				}
				$out['series'] = ['mode' => 'set', 'name' => $seriesName, 'index' => $index];
			} else {
				throw new InvalidEditRequestException('"series.mode" must be set or clear.');
			}
		}

		foreach (['publisher' => 255, 'language' => 32] as $key => $max) {
			$sec = $section($key);
			if ($sec === null) {
				continue;
			}
			$mode = $sec['mode'] ?? null;
			if ($mode === 'clear') {
				$out[$key] = ['mode' => 'clear'];
			} elseif ($mode === 'set') {
				$value = $name($sec['value'] ?? '', $key . '.value', $max);
				if ($value === '') {
					throw new InvalidEditRequestException('"' . $key . '.value" must not be empty.');
				}
				$out[$key] = ['mode' => 'set', 'value' => $value];
			} else {
				throw new InvalidEditRequestException('"' . $key . '.mode" must be set or clear.');
			}
		}

		foreach (['genres', 'tags'] as $key) {
			$sec = $section($key);
			if ($sec === null) {
				continue;
			}
			$add = $names($sec['add'] ?? [], $key . '.add', 200);
			$remove = $names($sec['remove'] ?? [], $key . '.remove', 200);
			if ($add !== [] || $remove !== []) {
				$out[$key] = ['add' => $add, 'remove' => $remove];
			}
		}

		if (count($out) === 1) {
			throw new InvalidEditRequestException('Nothing to change.');
		}
		return $out;
	}

	/** Whether metadata edits of this user reach the book files (target "file" or "both"). */
	public function metadataTargetWritesFiles(string $userId): bool {
		return in_array($this->targetOf($this->settings->get($userId)), ['file', 'both'], true);
	}

	/**
	 * Applies the same metadata change to several books. Only the provided fields change; every book keeps the rest.
	 * Each book goes through saveMetadataOnly(), so the metadata target, overrides and the sidecar behave as in single edits.
	 *
	 * @param array<string, mixed> $body see normalizeBulkMetadata()
	 * @param ?callable(float, string): void $progress
	 * @return array{updated: int, unchanged: int, failed: list<array{fileId: int, error: string}>, writeQueued: bool}
	 * @throws InvalidEditRequestException
	 */
	public function bulkMetadata(string $userId, array $body, ?callable $progress = null): array {
		$plan = $this->normalizeBulkMetadata($body);
		/** @var list<int> $ids */
		$ids = $plan['fileIds'];

		$authors = is_array($plan['authors'] ?? null) ? $plan['authors'] : null;
		$series = is_array($plan['series'] ?? null) ? $plan['series'] : null;
		$publisher = is_array($plan['publisher'] ?? null) ? $plan['publisher'] : null;
		$language = is_array($plan['language'] ?? null) ? $plan['language'] : null;
		$genres = is_array($plan['genres'] ?? null) ? $plan['genres'] : null;
		$tags = is_array($plan['tags'] ?? null) ? $plan['tags'] : null;
		$lower = static fn (array $a): array => array_map('mb_strtolower', $a);

		/** @var array<int, int> $position fileId => position used for numbering */
		$position = array_flip($ids);
		$index = is_array($series['index'] ?? null) ? $series['index'] : [];
		$indexMode = (string)($index['mode'] ?? 'keep');
		$start = (float)($index['start'] ?? 1);
		$step = (float)($index['step'] ?? 1);
		if (($series['mode'] ?? null) === 'set' && $indexMode === 'sortTitle') {
			$position = $this->titleOrder($userId, $ids);
		}

		return $this->runBulk($userId, $ids, function (array $cur, int $fileId) use ($authors, $series, $publisher, $language, $genres, $tags, $lower, $position, $indexMode, $start, $step): array {
			$patch = [];
			if ($authors !== null) {
				$values = $authors['values'];
				$current = is_array($cur['authors']) ? $cur['authors'] : [];
				if ($authors['mode'] === 'replace') {
					$patch['authors'] = $values;
				} elseif ($authors['mode'] === 'add') {
					$known = array_map('mb_strtolower', $current);
					$patch['authors'] = $current;
					foreach ($values as $v) {
						if (!in_array(mb_strtolower($v), $known, true)) {
							$patch['authors'][] = $v;
							$known[] = mb_strtolower($v);
						}
					}
				} else {
					$patch['authors'] = $this->mergeSet($current, [], $lower($values));
				}
			}
			if ($series !== null) {
				if ($series['mode'] === 'clear') {
					$patch['series'] = null;
					$patch['seriesIndex'] = null;
				} else {
					$patch['series'] = $series['name'];
					if ($indexMode !== 'keep' && isset($position[$fileId])) {
						$patch['seriesIndex'] = round($start + (float)$position[$fileId] * $step, 4);
					}
				}
			}
			if ($publisher !== null) {
				$patch['publisher'] = $publisher['mode'] === 'set' ? $publisher['value'] : null;
			}
			if ($language !== null) {
				$patch['language'] = $language['mode'] === 'set' ? $language['value'] : null;
			}
			if ($genres !== null) {
				$patch['genres'] = $this->mergeSet($cur['genres'], $genres['add'], $lower($genres['remove']));
			}
			if ($tags !== null) {
				$patch['tags'] = $this->mergeSet($cur['tags'], $tags['add'], $lower($tags['remove']));
			}
			return $patch;
		}, $progress);
	}

	/**
	 * Position (0-based) of each file when sorted naturally by title (file name without extension if there is none).
	 * Books that can not be read go last in their original order.
	 *
	 * @param list<int> $ids
	 * @return array<int, int> fileId => position
	 */
	private function titleOrder(string $userId, array $ids): array {
		$keys = [];
		foreach ($ids as $fileId) {
			$key = null;
			try {
				$title = trim((string)$this->library->getBook($userId, $fileId)->getTitle());
				if ($title !== '') {
					$key = $title;
				} else {
					$name = $this->library->getFileForUser($userId, $fileId)->getName();
					$key = pathinfo($name, PATHINFO_FILENAME);
				}
			} catch (\Throwable) {
				// reported as failed by the main loop
			}
			$keys[$fileId] = $key;
		}
		$order = $ids;
		usort($order, static function (int $a, int $b) use ($keys): int {
			if ($keys[$a] === null || $keys[$b] === null) {
				return ($keys[$a] === null ? 1 : 0) <=> ($keys[$b] === null ? 1 : 0);
			}
			return strnatcasecmp($keys[$a], $keys[$b]);
		});
		return array_flip($order);
	}

	/**
	 * @return list<int> unique file ids in the given order
	 * @throws InvalidEditRequestException
	 */
	private function bulkFileIds(mixed $raw): array {
		$ids = [];
		foreach (is_array($raw) ? $raw : [] as $id) {
			if (is_int($id) || (is_string($id) && ctype_digit($id))) {
				$ids[(int)$id] = true;
			}
		}
		if ($ids === []) {
			throw new InvalidEditRequestException('fileIds must not be empty.');
		}
		if (count($ids) > self::BULK_MAX_FILES) {
			throw new InvalidEditRequestException('Too many files.');
		}
		return array_keys($ids);
	}

	/**
	 * The loop shared by bulkTags() and bulkMetadata(): one saveMetadataOnly() per book, failures are collected.
	 *
	 * @param list<int> $ids
	 * @param callable(array<string, mixed>, int, int): array<string, mixed> $patchFor current metadata, file id, position => patch
	 * @param ?callable(float, string): void $progress
	 * @return array{updated: int, unchanged: int, failed: list<array{fileId: int, error: string}>, writeQueued: bool}
	 */
	private function runBulk(string $userId, array $ids, callable $patchFor, ?callable $progress): array {
		$updated = 0;
		$unchanged = 0;
		$queued = false;
		$failed = [];
		$total = count($ids);
		foreach ($ids as $i => $fileId) {
			if ($progress !== null) {
				$progress($i / $total, 'Book ' . ($i + 1) . ' of ' . $total);
			}
			try {
				$book = $this->library->getBook($userId, $fileId);
				$cur = $this->metadataOf($book);
				$patch = $this->normalizePatch($patchFor($cur, $fileId, $i));
				if ($patch === [] || $this->sameMetadata($cur, array_merge($cur, $patch), false)) {
					$unchanged++;
					continue;
				}
				$res = $this->saveMetadataOnly($userId, $fileId, $patch);
				$queued = $queued || $res['writeQueued'];
				$updated++;
			} catch (\Throwable $e) {
				$this->logger->info('Bulk metadata edit failed for file ' . $fileId . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
				$failed[] = ['fileId' => $fileId, 'error' => $e instanceof EditorException || $e instanceof DoesNotExistException || $e instanceof NotFoundException ? $e->getMessage() : 'Fehler beim Speichern.'];
			}
		}
		if ($progress !== null) {
			$progress(1.0, 'Done');
		}
		return ['updated' => $updated, 'unchanged' => $unchanged, 'failed' => $failed, 'writeQueued' => $queued];
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
			// renaming a shared file would rename the owner's file
			if (!$file->isUpdateable() || !$file->isDeletable() || FileOwnership::isShared($file)) {
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
				// the rename event moves it as well; this is a no-op then
				$this->sidecar->moveAlong($parent, $current, $parent, $candidate);
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
			// the sidecar (precedence over the embedded values) must say the same before the file is indexed again
			$this->syncSidecarAfterWrite($userId, $file, $req);
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

	/**
	 * After a structure save: refreshes the sidecar with the saved metadata (target sidecar/both: create it; target
	 * file/library: only an existing one, so stale values do not win over the new file content).
	 */
	private function syncSidecarAfterWrite(string $userId, File $file, EditRequest $req): void {
		if ($req->metadata === null) {
			return;
		}
		try {
			$target = $this->targetFor($userId, $file->getId(), $this->settings->get($userId));
			$create = $target === 'sidecar' || $target === 'both';
			$book = $this->findBook($userId, $file->getId());
			$base = $book !== null ? $this->fileMetadataOf($book) : $this->emptyMetadata();
			$patch = $this->forFile($req->metadata, $book !== null ? $this->appTagKeys($book) : []);
			try {
				$this->sidecar->write($file, array_merge($base, $patch), $create, $book?->getSidecarEtag(), true);
			} catch (SidecarChangedException) {
				// somebody else changed the sidecar since this user's row was indexed: it is the base, not the row
				$data = $this->sidecar->read($file);
				$other = $data === null ? $base : array_merge($base, [
					'title' => $data->metadata->title, 'authors' => $data->metadata->authors, 'series' => $data->metadata->series,
					'seriesIndex' => $data->metadata->seriesIndex, 'description' => $data->metadata->description,
					'language' => $data->metadata->language, 'publisher' => $data->metadata->publisher, 'isbn' => $data->metadata->isbn,
					'publishedAt' => $data->metadata->publishedAt, 'genres' => $data->metadata->genres, 'tags' => $data->metadata->tags,
				]);
				$this->sidecar->write($file, array_merge($other, $patch), $create);
			}
		} catch (\Throwable $e) {
			$this->logger->info('Sidecar not refreshed for file ' . $file->getId() . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
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
	 * @param array<string, true> $appTagKeys tags (lower case) that stay app-only tags of this user although $source is "file"
	 */
	private function updateDatabase(Book $book, array $meta, string $source, array $addOverrides = [], array $appTagKeys = []): Book {
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
			if ($type === Tag::TYPE_TAG && $appTagKeys !== [] && $source === Tag::SOURCE_FILE) {
				// app-only tags stay app-only: they are not part of the file (or sidecar) and not visible to other users
				$this->library->setTags($book->getId(), $type, [], Tag::SOURCE_FILE);
				$this->library->setTags($book->getId(), $type, [], Tag::SOURCE_APP);
				$isApp = static fn (string $n): bool => isset($appTagKeys[mb_strtolower($n)]);
				$this->library->setTags($book->getId(), $type, array_values(array_filter($names, static fn (string $n): bool => !$isApp($n))), Tag::SOURCE_FILE);
				$this->library->setTags($book->getId(), $type, array_values(array_filter($names, $isApp)), Tag::SOURCE_APP);
				continue;
			}
			// the user's list is authoritative: clear entries of the other source and store everything under $source
			$other = $source === Tag::SOURCE_FILE ? Tag::SOURCE_APP : Tag::SOURCE_FILE;
			$this->library->setTags($book->getId(), $type, [], $other);
			$this->library->setTags($book->getId(), $type, $names, $source);
		}
		return $book;
	}
}
