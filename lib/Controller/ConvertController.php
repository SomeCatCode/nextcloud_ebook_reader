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
 * Format conversion of comics (CBZ, CB7, CBT, CBR as source, EPUB as fixed layout target).
 *
 * @psalm-suppress InvalidReturnType
 * @psalm-suppress InvalidReturnStatement
 */
class ConvertController extends AbstractOCSController {
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
	 * @return DataResponse<Http::STATUS_OK, array{tools: array{sevenZip: bool, unrar: bool, bsdtar: bool}, server: array{read: list<string>, write: list<string>}}, array{}>
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
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_ACCEPTED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_REQUEST_ENTITY_TOO_LARGE|Http::STATUS_UNSUPPORTED_MEDIA_TYPE|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_INTERNAL_SERVER_ERROR, array<string, mixed>, array{}>
	 *
	 * 200: Converted, the new book is returned
	 * 202: Accepted, the conversion runs as a task (async = true)
	 * 400: Invalid target
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
	public function convertBook(int $fileId, string $target = '', bool $deleteOriginal = false, bool $async = false): DataResponse {
		$userId = $this->uid();
		return $this->guard(function () use ($userId, $fileId, $target, $deleteOriginal, $async): DataResponse {
			if (!$this->library->canReadContent($this->library->getFileForUser($userId, $fileId))) {
				return new DataResponse(['message' => 'Download of this file is disabled'], Http::STATUS_FORBIDDEN);
			}
			if ($async) {
				// everything that can fail early (format, tools, size, permissions, name clash) is answered now
				$this->convert->validate($userId, $fileId, strtolower($target), $deleteOriginal);
				$task = $this->tasks->create($userId, $fileId, Task::TYPE_CONVERT, ['target' => strtolower($target), 'deleteOriginal' => $deleteOriginal]);
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
