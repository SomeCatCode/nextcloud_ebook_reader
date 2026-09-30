<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\Editor\EditorException;
use OCA\EbookReader\Http\AbstractOCSController;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Service\EditorService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
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
	public function __construct(
		IRequest $request,
		?string $userId,
		private EditorService $editor,
		private BookSerializer $serializer,
		private LoggerInterface $logger,
	) {
		parent::__construct($request, $userId);
	}

	/**
	 * Structure (metadata, chapters/pages, table of contents) of a book for the editor
	 *
	 * @param int $fileId File id
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
	#[ApiRoute(verb: 'GET', url: '/api/v1/books/{fileId}/structure', requirements: ['fileId' => '\d+'])]
	public function structure(int $fileId): DataResponse {
		$userId = $this->uid();
		return $this->guard(fn (): DataResponse => new DataResponse($this->editor->getStructure($userId, $fileId)));
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
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_REQUEST_ENTITY_TOO_LARGE|Http::STATUS_UNSUPPORTED_MEDIA_TYPE|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_LOCKED|Http::STATUS_INTERNAL_SERVER_ERROR, array<string, mixed>, array{}>
	 *
	 * 200: Saved
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
	#[ApiRoute(verb: 'PUT', url: '/api/v1/books/{fileId}/structure', requirements: ['fileId' => '\d+'])]
	public function save(int $fileId, ?string $etag = null, bool $saveAsCopy = false, ?array $metadata = null, ?array $cover = null, ?array $order = null, ?array $removed = null, ?array $toc = null): DataResponse {
		$userId = $this->uid();
		$request = ['etag' => $etag ?? '', 'saveAsCopy' => $saveAsCopy, 'metadata' => $metadata, 'cover' => $cover, 'order' => $order, 'removed' => $removed, 'toc' => $toc];
		return $this->guard(function () use ($userId, $fileId, $request): DataResponse {
			$res = $this->editor->save($userId, $fileId, $request);
			return new DataResponse(['book' => $this->serializer->serializeWithProgress($userId, $res['book']), 'warnings' => $res['warnings']]);
		});
	}

	/**
	 * Patches the metadata of a book (also genres and tags)
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
			return new DataResponse(['book' => $this->serializer->serializeWithProgress($userId, $res['book']), 'warnings' => $res['warnings']]);
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
	#[ApiRoute(verb: 'POST', url: '/api/v1/books/bulk-tags')]
	public function bulkTags(array $fileIds = [], array $addGenres = [], array $removeGenres = [], array $addTags = [], array $removeTags = []): DataResponse {
		$userId = $this->uid();
		$body = compact('fileIds', 'addGenres', 'removeGenres', 'addTags', 'removeTags');
		return $this->guard(fn (): DataResponse => new DataResponse($this->editor->bulkTags($userId, $body)));
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
	#[ApiRoute(verb: 'POST', url: '/api/v1/books/{fileId}/rename', requirements: ['fileId' => '\d+'])]
	public function rename(int $fileId, ?string $name = null, bool $usePattern = false): DataResponse {
		$userId = $this->uid();
		return $this->guard(function () use ($userId, $fileId, $name, $usePattern): DataResponse {
			$book = $this->editor->rename($userId, $fileId, $name, $usePattern);
			return new DataResponse($this->serializer->serializeWithProgress($userId, $book));
		});
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
