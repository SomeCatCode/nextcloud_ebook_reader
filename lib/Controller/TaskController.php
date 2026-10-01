<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\Http\AbstractOCSController;
use OCA\EbookReader\Service\TaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Status of asynchronous edit/convert tasks. Only the owner of a task can see it.
 *
 * @psalm-suppress InvalidReturnType
 * @psalm-suppress InvalidReturnStatement
 */
class TaskController extends AbstractOCSController {
	public function __construct(
		IRequest $request,
		?string $userId,
		private TaskService $tasks,
	) {
		parent::__construct($request, $userId);
	}

	/**
	 * State of one task
	 *
	 * @param int $taskId Task id returned by the save/convert call
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_NOT_FOUND, array<string, mixed>, array{}>
	 *
	 * 200: Task returned
	 * 404: No such task (or not yours)
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/tasks/{taskId}', requirements: ['taskId' => '\d+'])]
	public function show(int $taskId): DataResponse {
		$userId = $this->uid();
		try {
			return new DataResponse($this->tasks->get($userId, $taskId)->toApi());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Tasks of the user, e.g. the running and queued ones for a hint after a page reload
	 *
	 * @param bool $active Only queued and running tasks (always the case; finished tasks are only available by id)
	 * @return DataResponse<Http::STATUS_OK, array{tasks: list<array<string, mixed>>}, array{}>
	 *
	 * 200: Tasks returned
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/tasks')]
	public function index(bool $active = true): DataResponse {
		$userId = $this->uid();
		$tasks = array_map(static fn ($t): array => $t->toApi(), $this->tasks->active($userId));
		return new DataResponse(['tasks' => $tasks]);
	}
}
