<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\ShareController;
use OCA\EbookReader\Controller\ShelfController;
use OCA\EbookReader\Service\ShareException;
use OCA\EbookReader\Service\ShareService;
use OCA\EbookReader\Service\ShelfException;
use OCA\EbookReader\Service\ShelfService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ShareControllerTest extends TestCase {
	private ShareService&MockObject $service;
	private IRequest&MockObject $request;
	private ShareController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(ShareService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->controller = new ShareController($this->request, 'alice', $this->service);
	}

	/** @return array{type: 'book', fileId: int, shelfId: null, name: string, owner: string, ownerDisplayName: string, recipient: string, recipientDisplayName: string, createdAt: int, bookCount: int} */
	private static function share(): array {
		return ['type' => 'book', 'fileId' => 1, 'shelfId' => null, 'name' => 'B', 'owner' => 'alice', 'ownerDisplayName' => 'Alice', 'recipient' => 'bob', 'recipientDisplayName' => 'Bob', 'createdAt' => 1, 'bookCount' => 1];
	}

	public function testNotLoggedInIsForbidden(): void {
		$controller = new ShareController($this->request, null, $this->service);
		$this->expectException(OCSForbiddenException::class);
		$controller->index();
	}

	public function testIndexIndexesIncomingBooksAndReturnsTheOverview(): void {
		$this->service->expects($this->once())->method('ensureIncomingIndexed')->with('alice');
		$this->service->method('overview')->with('alice')->willReturn(['outgoing' => [], 'incoming' => []]);
		$this->assertSame(['outgoing' => [], 'incoming' => []], $this->controller->index()->getData());
	}

	public function testIndexSurvivesIndexingErrors(): void {
		$this->service->method('ensureIncomingIndexed')->willThrowException(new \RuntimeException('x'));
		$this->service->method('overview')->willReturn(['outgoing' => [], 'incoming' => []]);
		$this->assertSame(['outgoing' => [], 'incoming' => []], $this->controller->index()->getData());
	}

	public function testShareBook(): void {
		$this->service->expects($this->once())->method('shareBook')->with('alice', 5, 'bob')->willReturn(self::share());
		$this->assertSame(['share' => self::share(), 'skipped' => 0], $this->controller->shareBook(5, 'bob')->getData());
	}

	public function testErrorsAreMapped(): void {
		$cases = [
			ShareException::NOT_FOUND => OCSNotFoundException::class,
			ShareException::INVALID => OCSBadRequestException::class,
			ShareException::FORBIDDEN => OCSForbiddenException::class,
		];
		foreach ($cases as $reason => $class) {
			$service = $this->createMock(ShareService::class);
			$service->method('shareBook')->willThrowException(new ShareException('message ' . $reason, $reason));
			$controller = new ShareController($this->request, 'alice', $service);
			try {
				$controller->shareBook(5, 'bob');
				$this->fail('exception expected');
			} catch (\Exception $e) {
				$this->assertInstanceOf($class, $e);
				$this->assertSame('message ' . $reason, $e->getMessage());
			}
		}
	}

	public function testDeleteBookShareAsOwnerOrRecipient(): void {
		$this->service->expects($this->once())->method('unshareBook')->with('alice', 5, 'bob');
		$this->assertSame(['removed' => 1], $this->controller->unshareBook(5, 'bob')->getData());

		$this->service->expects($this->once())->method('leaveBook')->with('alice', 6, 'carol')->willReturn(1);
		$this->assertSame(['removed' => 1], $this->controller->unshareBook(6, '', 'carol')->getData());
	}

	public function testShelfShareAndRemoval(): void {
		$this->service->expects($this->once())->method('shareShelf')->with('alice', 3, 'bob')->willReturn(['share' => self::share(), 'skipped' => 2]);
		$this->assertSame(2, $this->controller->shareShelf(3, 'bob')->getData()['skipped']);

		$this->service->expects($this->once())->method('unshareShelf')->with('alice', 3, 'bob');
		$this->controller->unshareShelf(3, 'bob');
		$this->service->expects($this->once())->method('leaveShelf')->with('alice', 4);
		$this->controller->unshareShelf(4);
	}

	public function testMissingShareIs404(): void {
		$this->service->method('leaveShelf')->willThrowException(new ShareException('Share not found', ShareException::NOT_FOUND));
		$this->expectException(OCSNotFoundException::class);
		$this->controller->unshareShelf(9);
	}

	public function testReadOnlySharedShelfIs403InTheShelfController(): void {
		$shelves = $this->createMock(ShelfService::class);
		$shelves->method('addBooks')->willThrowException(new ShelfException('Shared shelves are read-only', ShelfException::FORBIDDEN));
		$controller = new ShelfController($this->request, 'bob', $shelves, $this->service);
		$this->expectException(OCSForbiddenException::class);
		$controller->addBooks(3, [1]);
	}

	public function testShelfListIndexesIncomingBooksFirst(): void {
		$shelves = $this->createMock(ShelfService::class);
		$order = [];
		$this->service->method('ensureIncomingIndexed')->willReturnCallback(static function () use (&$order): int {
			$order[] = 'index';
			return 0;
		});
		$shelves->method('list')->willReturnCallback(static function () use (&$order): array {
			$order[] = 'list';
			return [];
		});
		$controller = new ShelfController($this->request, 'bob', $shelves, $this->service);
		$controller->index();
		$this->assertSame(['index', 'list'], $order);
	}
}
