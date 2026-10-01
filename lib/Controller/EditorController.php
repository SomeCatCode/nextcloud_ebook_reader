<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\Db\Task;
use OCA\EbookReader\Editor\EditorException;
use OCA\EbookReader\Http\AbstractOCSController;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Service\EditorService;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\TaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Editor endpoints: structure, save, metadata patch, bulk tagging, rename.
 *
 * @psalm-suppress InvalidReturnType
 * @psalm-suppress InvalidReturnStatement
 */
class EditorController extends AbstractOCSController {
	private const MAX_BULK_FILES = 100;

	public function __construct(
		IRequest $request,
		?string $userId,
		private EditorService $editor,
		private LibraryService $library,
		private BookSerializer $serializer,
		private LoggerInterface $logger,
		private TaskService $tasks,
	) {
		parent::__construct($request, $userId);
	}

	/**
	 * Structure (metadata, chapters/pages, table of contents) of a book for the editor
	 *
	 * @param int $fileId File id
	 * @param string $parts "all" (default) reads the file; "metadata" returns only the metadata from the library without touching the file (items and toc empty, partial = true)
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_REQUEST_ENTITY_TOO_LARGE|Http::STATUS_UNSUPPORTED_MEDIA_TYPE|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_LOCKED|Http::STATUS_INTERNAL_SERVER_ERROR, array<string, mixed>, array{}>
	 *
	 * 200: Structure returned
	 * 400: Invalid request
	 * 403: No permission to modify the file
	 * 404: File not found
	 * 409: File was modified in the meantime (etag mismatch)
	 * 413: File too large to edit
	 * 415: Format not supported for this operation
	 * 422: Result failed validation, original left unchanged
	 * 423: File is locked
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/books/{fileId}/structure', requirements: ['fileId' => '\d+'])]
	public function structure(int $fileId, string $parts = 'all'): DataResponse {
		$userId = $this->uid();
		return $this->guard(function () use ($userId, $fileId, $parts): DataResponse {
			$this->requireContentAccess($userId, $fileId);
			return new DataResponse($this->editor->getStructure($userId, $fileId, $parts));
		});
	}

	/**
	 * Saves the edited document (metadata, cover, order, removed items, table of contents)
	 *
	 * @param int $fileId File id
	 * @param string|null $etag Etag of the file the edit is based on
	 * @param bool $saveAsCopy Save as "<name> (bearbeitet)" instead of overwriting
	 * @param array<string, mixed>|null $metadata Metadata
	 * @param array<string, mixed>|null $cover Cover: {source: "upload", data} or {source: "item", itemId}
	 * @param list<string>|null $order New item order without removed items
	 * @param list<string>|null $removed Removed item ids
	 * @param list<array<string, mixed>>|null $toc Table of contents tree
	 * @param bool $async Validate synchronously, then save in the background and return a task id (poll GET /api/v1/tasks/{taskId})
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_ACCEPTED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_REQUEST_ENTITY_TOO_LARGE|Http::STATUS_UNSUPPORTED_MEDIA_TYPE|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_LOCKED|Http::STATUS_INTERNAL_SERVER_ERROR, array<string, mixed>, array{}>
	 *
	 * 200: Saved
	 * 202: Accepted, the save runs as a task (async = true)
	 * 400: Invalid request
	 * 403: No write permission
	 * 404: File not found
	 * 409: The file was changed in the meantime
	 * 413: File too large to edit
	 * 415: Format not supported for this operation
	 * 422: Result failed validation, original left unchanged
	 * 423: File is locked
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/books/{fileId}/structure', requirements: ['fileId' => '\d+'])]
	public function save(int $fileId, ?string $etag = null, bool $saveAsCopy = false, ?array $metadata = null, ?array $cover = null, ?array $order = null, ?array $removed = null, ?array $toc = null, bool $async = false): DataResponse {
		$userId = $this->uid();
		$request = ['etag' => $etag ?? '', 'saveAsCopy' => $saveAsCopy, 'metadata' => $metadata, 'cover' => $cover, 'order' => $order, 'removed' => $removed, 'toc' => $toc];
		return $this->guard(function () use ($userId, $fileId, $request, $async): DataResponse {
			$this->requireContentAccess($userId, $fileId);
			if ($async) {
				// permissions, etag, size and the request itself are checked now; the etag is checked again when the task writes
				$this->editor->validateSave($userId, $fileId, $request);
				$task = $this->tasks->create($userId, $fileId, Task::TYPE_EDIT, $request);
				$this->tasks->scheduleInline($task);
				return new DataResponse(['taskId' => $task->getId()], Http::STATUS_ACCEPTED);
			}
			$res = $this->editor->save($userId, $fileId, $request);
			return new DataResponse(['book' => $this->serializer->serializeWithProgress($userId, $res['book']), 'warnings' => $res['warnings']]);
		});
	}

	/**
	 * Patches the metadata of a book (also genres and tags). Depending on the user's write mode the file is written right
	 * away, later in the background (writeQueued = true) or not at all.
	 *
	 * @param int $fileId File id
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_REQUEST_ENTITY_TOO_LARGE|Http::STATUS_UNSUPPORTED_MEDIA_TYPE|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_LOCKED|Http::STATUS_INTERNAL_SERVER_ERROR, array<string, mixed>, array{}>
	 *
	 * 200: Saved
	 * 400: Invalid request
	 * 403: No permission to modify the file
	 * 404: File not found
	 * 409: File was modified in the meantime (etag mismatch)
	 * 413: File too large to edit
	 * 415: Format not supported for this operation
	 * 422: Result failed validation, original left unchanged
	 * 423: File is locked
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/books/{fileId}/metadata', requirements: ['fileId' => '\d+'])]
	public function metadata(int $fileId): DataResponse {
		$userId = $this->uid();
		$patch = [];
		foreach ($this->request->getParams() as $k => $v) {
			if (is_string($k)) {
				$patch[$k] = $v;
			}
		}
		return $this->guard(function () use ($userId, $fileId, $patch): DataResponse {
			$res = $this->editor->saveMetadataOnly($userId, $fileId, $patch);
			return new DataResponse(['book' => $this->serializer->serializeWithProgress($userId, $res['book']), 'warnings' => $res['warnings'], 'writeQueued' => $res['writeQueued']]);
		});
	}

	/**
	 * Writes the metadata stored in the library into the book file (EPUB, CBZ, FB2, FBZ). Useful when the metadata lives in the sidecar
	 * file and other readers (Kobo, KOReader ...) should see it, because they only read embedded metadata.
	 *
	 * @param int $fileId File id
	 * @param bool $async Validate synchronously, then write in the background and return a task id (poll GET /api/v1/tasks/{taskId})
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_ACCEPTED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_REQUEST_ENTITY_TOO_LARGE|Http::STATUS_UNSUPPORTED_MEDIA_TYPE|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_LOCKED|Http::STATUS_INTERNAL_SERVER_ERROR, array<string, mixed>, array{}>
	 *
	 * 200: Written (written = false if the file already held the metadata)
	 * 202: Accepted, the write runs as a task (async = true)
	 * 400: Invalid request
	 * 403: No write permission or download of the file is disabled
	 * 404: File not found
	 * 409: File was modified in the meantime
	 * 413: File too large to edit
	 * 415: Format can not be written
	 * 422: Result failed validation, original left unchanged
	 * 423: File is locked
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/books/{fileId}/metadata/embed', requirements: ['fileId' => '\d+'])]
	public function embedMetadata(int $fileId, bool $async = false): DataResponse {
		$userId = $this->uid();
		return $this->guard(function () use ($userId, $fileId, $async): DataResponse {
			$this->requireContentAccess($userId, $fileId);
			$this->editor->checkEmbed($userId, $fileId);
			if ($async) {
				$task = $this->tasks->create($userId, $fileId, Task::TYPE_EMBED, []);
				$this->tasks->scheduleInline($task);
				return new DataResponse(['taskId' => $task->getId()], Http::STATUS_ACCEPTED);
			}
			$res = $this->editor->embedMetadata($userId, $fileId);
			return new DataResponse(['book' => $this->serializer->serializeWithProgress($userId, $res['book']), 'warnings' => $res['warnings'], 'written' => $res['written']]);
		});
	}

	/**
	 * Adds/removes genres and tags of several books
	 *
	 * @param list<int> $fileIds File ids
	 * @param list<string> $addGenres Genres to add
	 * @param list<string> $removeGenres Genres to remove
	 * @param list<string> $addTags Tags to add
	 * @param list<string> $removeTags Tags to remove
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_REQUEST_ENTITY_TOO_LARGE|Http::STATUS_UNSUPPORTED_MEDIA_TYPE|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_LOCKED|Http::STATUS_INTERNAL_SERVER_ERROR, array<string, mixed>, array{}>
	 *
	 * 200: Processed
	 * 400: Invalid request
	 * 403: No permission to modify the file
	 * 404: File not found
	 * 409: File was modified in the meantime (etag mismatch)
	 * 413: File too large to edit
	 * 415: Format not supported for this operation
	 * 422: Result failed validation, original left unchanged
	 * 423: File is locked
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 5, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/books/bulk-tags')]
	public function bulkTags(array $fileIds = [], array $addGenres = [], array $removeGenres = [], array $addTags = [], array $removeTags = []): DataResponse {
		$userId = $this->uid();
		if (count($fileIds) > self::MAX_BULK_FILES) {
			return new DataResponse(['message' => 'Too many files (max ' . self::MAX_BULK_FILES . ')'], Http::STATUS_BAD_REQUEST);
		}
		$body = compact('fileIds', 'addGenres', 'removeGenres', 'addTags', 'removeTags');
		return $this->guard(fn (): DataResponse => new DataResponse($this->editor->bulkTags($userId, $body)));
	}

	/**
	 * Drops the "edited in the app" marker of one metadata field (or all) and takes the value from the file again
	 *
	 * @param int $fileId File id
	 * @param string|null $field Field name (title, authors, series, seriesIndex, description, language, publisher, isbn, publishedAt); all fields when omitted
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_REQUEST_ENTITY_TOO_LARGE|Http::STATUS_UNSUPPORTED_MEDIA_TYPE|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_LOCKED|Http::STATUS_INTERNAL_SERVER_ERROR, array<string, mixed>, array{}>
	 *
	 * 200: Override removed, the book is returned
	 * 400: Unknown field
	 * 403: No permission
	 * 404: File not found
	 * 409: File was modified in the meantime
	 * 413: File too large
	 * 415: Format not supported
	 * 422: Validation failed
	 * 423: File is locked
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/books/{fileId}/overrides', requirements: ['fileId' => '\d+'])]
	public function resetOverrides(int $fileId, ?string $field = null): DataResponse {
		$userId = $this->uid();
		return $this->guard(function () use ($userId, $fileId, $field): DataResponse {
			$book = $this->editor->resetOverrides($userId, $fileId, $field);
			return new DataResponse($this->serializer->serializeWithProgress($userId, $book));
		});
	}

	/**
	 * Renames the file (free name or from the filename pattern of the settings)
	 *
	 * @param int $fileId File id
	 * @param string|null $name New name (without extension)
	 * @param bool $usePattern Generate the name from the metadata
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_REQUEST_ENTITY_TOO_LARGE|Http::STATUS_UNSUPPORTED_MEDIA_TYPE|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_LOCKED|Http::STATUS_INTERNAL_SERVER_ERROR, array<string, mixed>, array{}>
	 *
	 * 200: Renamed, the book is returned
	 * 400: Invalid request
	 * 403: No permission to modify the file
	 * 404: File not found
	 * 409: File was modified in the meantime (etag mismatch)
	 * 413: File too large to edit
	 * 415: Format not supported for this operation
	 * 422: Result failed validation, original left unchanged
	 * 423: File is locked
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/books/{fileId}/rename', requirements: ['fileId' => '\d+'])]
	public function rename(int $fileId, ?string $name = null, bool $usePattern = false): DataResponse {
		$userId = $this->uid();
		return $this->guard(function () use ($userId, $fileId, $name, $usePattern): DataResponse {
			$book = $this->editor->rename($userId, $fileId, $name, $usePattern);
			return new DataResponse($this->serializer->serializeWithProgress($userId, $book));
		});
	}

	/**
	 * View-only shares (download disabled / hidden download) must not hand out the book content in any form.
	 * @throws EditorException
	 * @throws NotFoundException
	 */
	private function requireContentAccess(string $userId, int $fileId): void {
		if (!$this->library->canReadContent($this->library->getFileForUser($userId, $fileId))) {
			throw new EditorException('Download of this file is disabled', Http::STATUS_FORBIDDEN);
		}
	}

	private function guard(callable $fn): DataResponse {
		try {
			return $fn();
		} catch (EditorException $e) {
			return new DataResponse(['message' => $e->getMessage()], $e->getStatus());
		} catch (DoesNotExistException|NotFoundException) {
			return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			$this->logger->error('Editor request failed: ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
			return new DataResponse(['message' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
