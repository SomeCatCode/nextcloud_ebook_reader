<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\Db\Task;
use OCA\EbookReader\Http\AbstractOCSController;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Service\ConvertException;
use OCA\EbookReader\Service\ConvertService;
use OCA\EbookReader\Service\ImageOptimizer;
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
 * @psalm-import-type EbookReaderOptimizeEstimate from \OCA\EbookReader\ResponseDefinitions
 * @psalm-import-type EbookReaderOptimizeResult from \OCA\EbookReader\ResponseDefinitions
 *
 * Format conversion of comics (CBZ, CB7, CBT, CBR as source, EPUB as fixed layout target).
 *
 * @psalm-suppress InvalidReturnType
 * @psalm-suppress InvalidReturnStatement
 */
class ConvertController extends AbstractOCSController {
	/** Books per bulk optimization */
	public const MAX_BULK = 100;

	public function __construct(
		IRequest $request,
		?string $userId,
		private ConvertService $convert,
		private LibraryService $library,
		private BookSerializer $serializer,
		private LoggerInterface $logger,
		private TaskService $tasks,
	) {
		parent::__construct($request, $userId);
	}

	/**
	 * Tools installed on the server and the comic formats the server can read and write
	 *
	 * @return DataResponse<Http::STATUS_OK, array{tools: array{sevenZip: bool, unrar: bool, bsdtar: bool}, server: array{read: list<string>, write: list<string>}, optimize: array{available: bool, maxHeights: list<int>}}, array{}>
	 *
	 * 200: Capabilities returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/convert/capabilities')]
	public function capabilities(): DataResponse {
		$this->uid();
		return new DataResponse($this->convert->capabilities());
	}

	/**
	 * Possible target formats of a book and where the conversion can run (server or browser)
	 *
	 * @param int $fileId File id
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array<string, mixed>, array{}>
	 *
	 * 200: Source format and targets returned
	 * 404: Book not found
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/books/{fileId}/convert', requirements: ['fileId' => '\d+'])]
	public function targets(int $fileId): DataResponse {
		$userId = $this->uid();
		return $this->guard(fn (): DataResponse => new DataResponse($this->convert->targets($userId, $fileId)));
	}

	/**
	 * Converts a comic on the server
	 *
	 * The new file is created next to the original.
	 *
	 * @param int $fileId File id
	 * @param string $target Target format: cbz, cb7, cbt or epub
	 * @param bool $deleteOriginal Move the original to the trash after a successful conversion
	 * @param bool $async Validate synchronously, then convert in the background and return a task id (poll GET /api/v1/tasks/{taskId})
	 * @param array<string, mixed> $optimize Optional lossy image optimization: maxHeight (0, 2560 or 1920), jpegQuality (70 to 95, default 85), pngToJpeg. The target may equal the source format then. Always runs as a task.
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_ACCEPTED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_REQUEST_ENTITY_TOO_LARGE|Http::STATUS_UNSUPPORTED_MEDIA_TYPE|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_INTERNAL_SERVER_ERROR, array<string, mixed>, array{}>
	 *
	 * 200: Converted, the new book is returned
	 * 202: Accepted, the conversion runs as a task (async = true)
	 * 400: Invalid target or optimize options
	 * 403: No permission to create the file or delete the original
	 * 404: Book not found
	 * 409: A file with the target name already exists
	 * 413: File too large to convert on the server
	 * 415: The server cannot do this conversion (use the browser)
	 * 422: The comic cannot be read
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 3600)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/books/{fileId}/convert', requirements: ['fileId' => '\d+'])]
	public function convertBook(int $fileId, string $target = '', bool $deleteOriginal = false, bool $async = false, array $optimize = []): DataResponse {
		$userId = $this->uid();
		return $this->guard(function () use ($userId, $fileId, $target, $deleteOriginal, $async, $optimize): DataResponse {
			if (!$this->library->canReadContent($this->library->getFileForUser($userId, $fileId))) {
				return new DataResponse(['message' => 'Download of this file is disabled'], Http::STATUS_FORBIDDEN);
			}
			$options = $optimize === [] ? null : ImageOptimizer::normalise($optimize);
			if ($options !== null && !ImageOptimizer::isActive($options)) {
				$options = null;
			}
			// optimizing is CPU heavy: always a task, whatever the size of the file
			if ($async || $options !== null) {
				// everything that can fail early (format, tools, size, permissions, name clash) is answered now
				$this->convert->validate($userId, $fileId, strtolower($target), $deleteOriginal, $options);
				$request = ['target' => strtolower($target), 'deleteOriginal' => $deleteOriginal];
				if ($options !== null) {
					$request['optimize'] = $options;
				}
				$task = $this->tasks->create($userId, $fileId, Task::TYPE_CONVERT, $request);
				$this->tasks->scheduleInline($task);
				return new DataResponse(['taskId' => $task->getId()], Http::STATUS_ACCEPTED);
			}
			$res = $this->convert->convert($userId, $fileId, strtolower($target), $deleteOriginal);
			return new DataResponse([
				'book' => $this->serializer->serializeWithProgress($userId, $res['book']),
				'fileId' => $res['fileId'],
				'path' => $res['path'],
			]);
		});
	}

	/**
	 * Estimates the result of an image optimization
	 *
	 * Reads page headers and re-encodes at most three pages, so it is fast even for large files.
	 *
	 * @param int $fileId File id
	 * @param int $maxHeight Maximum page height: 0 (off), 2560 or 1920
	 * @param bool $pngToJpeg Convert PNG pages without transparency to JPEG
	 * @return DataResponse<Http::STATUS_OK, EbookReaderOptimizeEstimate, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_REQUEST_ENTITY_TOO_LARGE|Http::STATUS_UNSUPPORTED_MEDIA_TYPE|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_INTERNAL_SERVER_ERROR, array<string, mixed>, array{}>
	 *
	 * 200: Estimate returned (pages, oversizedPages, currentBytes, estimatedBytes, exact)
	 * 400: Invalid options or nothing to optimize
	 * 403: Download of this file is disabled
	 * 404: Book not found
	 * 413: File too large for the server
	 * 415: Not a comic, or the server cannot optimize or read it
	 * 422: The comic cannot be read
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 600)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/books/{fileId}/convert/estimate', requirements: ['fileId' => '\d+'])]
	public function estimate(int $fileId, int $maxHeight = 0, bool $pngToJpeg = false): DataResponse {
		$userId = $this->uid();
		return $this->guard(function () use ($userId, $fileId, $maxHeight, $pngToJpeg): DataResponse {
			$options = ImageOptimizer::normalise(['maxHeight' => $maxHeight, 'pngToJpeg' => $pngToJpeg]);
			if (!$this->library->canReadContent($this->library->getFileForUser($userId, $fileId))) {
				return new DataResponse(['message' => 'Download of this file is disabled'], Http::STATUS_FORBIDDEN);
			}
			return new DataResponse($this->convert->estimateOptimize($userId, $fileId, $options));
		});
	}

	/**
	 * Optimizes the images of several comics
	 *
	 * Creates one background task per book; the tasks run one after another. The format stays the same
	 * (CBR becomes CBZ) and the new file is called "Name (optimized).ext" next to the original.
	 *
	 * @param list<int> $fileIds File ids (at most 100)
	 * @param int $maxHeight Maximum page height: 0 (off), 2560 or 1920
	 * @param bool $pngToJpeg Convert PNG pages without transparency to JPEG
	 * @param bool $deleteOriginal Move the originals to the trash afterwards
	 * @return DataResponse<Http::STATUS_ACCEPTED, EbookReaderOptimizeResult, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_INTERNAL_SERVER_ERROR, array<string, mixed>, array{}>
	 *
	 * 202: Tasks created: tasks lists {fileId, taskId}, skipped the books that were rejected with their reason
	 * 400: Invalid options or file ids
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 3600)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/convert/optimize')]
	public function optimizeBooks(array $fileIds = [], int $maxHeight = 0, bool $pngToJpeg = false, bool $deleteOriginal = false): DataResponse {
		$userId = $this->uid();
		return $this->guard(function () use ($userId, $fileIds, $maxHeight, $pngToJpeg, $deleteOriginal): DataResponse {
			$options = ImageOptimizer::normalise(['maxHeight' => $maxHeight, 'pngToJpeg' => $pngToJpeg]);
			if (!ImageOptimizer::isActive($options)) {
				throw new ConvertException('Nothing to optimize', 400);
			}
			$ids = [];
			foreach ($fileIds as $id) {
				if (is_int($id) && $id > 0) {
					$ids[$id] = $id;
				}
			}
			if ($ids === [] || count($ids) > self::MAX_BULK) {
				throw new ConvertException('Select between 1 and ' . self::MAX_BULK . ' books', 400);
			}
			$tasks = [];
			$skipped = [];
			foreach ($ids as $fileId) {
				try {
					$book = $this->library->getBook($userId, $fileId);
					if (!$this->library->canReadContent($this->library->getFileForUser($userId, $fileId))) {
						throw new ConvertException('Download of this file is disabled', 403);
					}
					$target = ConvertService::optimizeTarget($book->getFormat());
					$this->convert->validate($userId, $fileId, $target, $deleteOriginal, $options);
					$task = $this->tasks->create($userId, $fileId, Task::TYPE_CONVERT, ['target' => $target, 'deleteOriginal' => $deleteOriginal, 'optimize' => $options]);
					$this->tasks->scheduleInline($task);
					$tasks[] = ['fileId' => $fileId, 'taskId' => $task->getId()];
				} catch (ConvertException $e) {
					$skipped[] = ['fileId' => $fileId, 'error' => $e->getMessage(), 'status' => $e->getStatus()];
				} catch (DoesNotExistException|NotFoundException) {
					$skipped[] = ['fileId' => $fileId, 'error' => 'Not found', 'status' => Http::STATUS_NOT_FOUND];
				}
			}
			return new DataResponse(['tasks' => $tasks, 'skipped' => $skipped], Http::STATUS_ACCEPTED);
		});
	}

	/**
	 * Finish a conversion that ran in the browser
	 *
	 * The client uploaded the converted file next to the original. The server indexes it right away
	 * (also large files), copies the metadata sidecar, carries over rating, status, app tags and the
	 * reading position, and deletes the original afterwards if requested.
	 *
	 * @param int $fileId File id of the original
	 * @param string $name File name of the uploaded result (same folder as the original)
	 * @param bool $deleteOriginal Move the original to the trash once the new book is indexed
	 * @param list<string> $oldPages Page entry names of the original in reading order
	 * @param list<string> $newPages Page entry names of the result in the same order
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_UNSUPPORTED_MEDIA_TYPE|Http::STATUS_INTERNAL_SERVER_ERROR, array<string, mixed>, array{}>
	 *
	 * 200: New book indexed, originalDeleted tells whether the original was removed
	 * 400: Invalid file name or format
	 * 403: The original cannot be deleted
	 * 404: Book or uploaded file not found
	 * 415: The book is not a comic
	 * 500: Internal error
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/books/{fileId}/convert/adopt', requirements: ['fileId' => '\d+'])]
	public function adopt(int $fileId, string $name = '', bool $deleteOriginal = false, array $oldPages = [], array $newPages = []): DataResponse {
		$userId = $this->uid();
		return $this->guard(function () use ($userId, $fileId, $name, $deleteOriginal, $oldPages, $newPages): DataResponse {
			$res = $this->convert->adoptClientResult($userId, $fileId, $name, $deleteOriginal, $oldPages, $newPages);
			return new DataResponse([
				'book' => $this->serializer->serializeWithProgress($userId, $res['book']),
				'fileId' => $res['fileId'],
				'path' => $res['path'],
				'originalDeleted' => $res['originalDeleted'],
			]);
		});
	}

	private function guard(callable $fn): DataResponse {
		try {
			return $fn();
		} catch (ConvertException $e) {
			return new DataResponse(['message' => $e->getMessage()], $e->getStatus());
		} catch (DoesNotExistException|NotFoundException) {
			return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			$this->logger->error('Conversion request failed: ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
			return new DataResponse(['message' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
