<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\SeriesController;
use OCA\EbookReader\Controller\ShelfController;
use OCA\EbookReader\Service\BookQuery;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\ShelfException;
use OCA\EbookReader\Service\ShelfService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ShelfControllerTest extends TestCase {
	private ShelfService&MockObject $service;
	private IRequest&MockObject $request;
	private ShelfController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(ShelfService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->controller = new ShelfController($this->request, 'u', $this->service);
	}

	public function testIndexReturnsShelvesOfTheCurrentUser(): void {
		$this->service->expects($this->once())->method('list')->with('u')->willReturn([]);
		$this->assertSame(['shelves' => []], $this->controller->index()->getData());
	}

	public function testNotLoggedInIsForbidden(): void {
		$controller = new ShelfController($this->request, null, $this->service);
		$this->expectException(OCSForbiddenException::class);
		$controller->index();
	}

	public function testCreatePassesNameTypeAndQuery(): void {
		$this->service->expects($this->once())->method('create')->with('u', 'Regal', 'smart', ['include' => ['tag:x']])->willReturn(['id' => 1]);
		$this->assertSame(['id' => 1], $this->controller->create('Regal', 'smart', ['include' => ['tag:x']])->getData());
	}

	public function testValidationErrorsBecomeBadRequest(): void {
		$this->service->method('create')->willThrowException(new ShelfException('bad name', ShelfException::INVALID));
		$this->expectException(OCSBadRequestException::class);
		$this->controller->create('');
	}

	public function testDuplicateNameAndLimitBecomeBadRequest(): void {
		foreach ([ShelfException::EXISTS, ShelfException::LIMIT] as $reason) {
			$service = $this->createMock(ShelfService::class);
			$service->method('create')->willThrowException(new ShelfException('x', $reason));
			$controller = new ShelfController($this->request, 'u', $service);
			try {
				$controller->create('A');
				$this->fail('exception expected');
			} catch (OCSBadRequestException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testUpdateDetectsExplicitQueryParam(): void {
		$this->request->method('getParams')->willReturn(['query' => ['include' => []]]);
		$this->service->expects($this->once())->method('update')->with('u', 3, null, true, ['include' => []], 4)->willReturn(['id' => 3]);
		$this->controller->update(3, null, ['include' => []], 4);
	}

	public function testUpdateWithoutQueryParam(): void {
		$this->request->method('getParams')->willReturn(['name' => 'N']);
		$this->service->expects($this->once())->method('update')->with('u', 3, 'N', false, null, null)->willReturn(['id' => 3]);
		$this->controller->update(3, 'N');
	}

	public function testUnknownShelfIsNotFound(): void {
		$this->service->method('delete')->willThrowException(new ShelfException('nope', ShelfException::NOT_FOUND));
		$this->expectException(OCSNotFoundException::class);
		$this->controller->destroy(9);
	}

	public function testDestroyReturnsId(): void {
		$this->service->expects($this->once())->method('delete')->with('u', 9);
		$this->assertSame(['deleted' => 9], $this->controller->destroy(9)->getData());
	}

	public function testBookEndpointsRejectEmptyAndTooLargeSelections(): void {
		$this->service->expects($this->never())->method('addBooks');
		$this->service->expects($this->never())->method('removeBooks');
		$this->service->expects($this->never())->method('reorder');
		foreach (['addBooks', 'removeBooks', 'reorder'] as $method) {
			foreach ([[], range(1, 501)] as $ids) {
				try {
					$this->controller->$method(1, $ids);
					$this->fail($method . ' should reject ' . count($ids) . ' ids');
				} catch (OCSBadRequestException) {
					$this->addToAssertionCount(1);
				}
			}
		}
	}

	public function testBookEndpointsDelegate(): void {
		$this->service->expects($this->once())->method('addBooks')->with('u', 2, [1, 2])->willReturn(['added' => 2, 'skipped' => 0]);
		$this->service->expects($this->once())->method('removeBooks')->with('u', 2, [1])->willReturn(['removed' => 1]);
		$this->service->expects($this->once())->method('reorder')->with('u', 2, [2, 1])->willReturn(['fileIds' => [2, 1]]);
		$this->assertSame(['added' => 2, 'skipped' => 0], $this->controller->addBooks(2, [1, 2])->getData());
		$this->assertSame(['removed' => 1], $this->controller->removeBooks(2, [1])->getData());
		$this->assertSame(['fileIds' => [2, 1]], $this->controller->reorder(2, [2, 1])->getData());
	}

	public function testBookEndpointsOnSmartShelfAreBadRequest(): void {
		$this->service->method('addBooks')->willThrowException(new ShelfException('smart', ShelfException::INVALID));
		$this->expectException(OCSBadRequestException::class);
		$this->controller->addBooks(2, [1]);
	}

	public function testSeriesControllerPassesFilters(): void {
		$library = $this->createMock(LibraryService::class);
		$captured = null;
		$library->expects($this->once())->method('listSeries')->willReturnCallback(function (string $u, BookQuery $q) use (&$captured): array {
			$captured = [$u, $q];
			return [];
		});
		$controller = new SeriesController($this->request, 'u', $library);
		$data = $controller->index(null, null, null, 'Fantasy/*', null, 'unread', 'added', 'desc', ['shelf:3'], ['author:Doe'], 'any')->getData();
		$this->assertSame(['series' => []], $data);
		$this->assertNotNull($captured);
		[$user, $q] = $captured;
		$this->assertSame('u', $user);
		$this->assertSame('added', $q->sort);
		$this->assertSame('desc', $q->order);
		$this->assertSame([['type' => 'shelf', 'name' => '3'], ['type' => 'tag', 'name' => 'Fantasy/*']], $q->effectiveIncludes());
		$this->assertSame('any', $q->match);
		$this->assertSame('unread', $q->status);
	}

	public function testSeriesNeedsLogin(): void {
		$controller = new SeriesController($this->request, null, $this->createMock(LibraryService::class));
		$this->expectException(OCSForbiddenException::class);
		$controller->index();
	}
}
