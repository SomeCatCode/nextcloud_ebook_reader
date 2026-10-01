<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\OrganizeController;
use OCA\EbookReader\Service\OrganizeException;
use OCA\EbookReader\Service\OrganizeService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrganizeControllerTest extends TestCase {
	private OrganizeService&MockObject $service;
	private OrganizeController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(OrganizeService::class);
		$this->controller = new OrganizeController($this->createMock(IRequest::class), 'u', $this->service);
	}

	public function testPreviewPassesSanitisedIds(): void {
		$this->service->expects($this->once())->method('preview')
			->with('u', [3, 4], '{title}', '/Books')
			->willReturn(['items' => []]);
		$res = $this->controller->preview([3, '4', 3, 0, -1, 'x', 2.5], '{title}', '/Books');
		$this->assertSame(['items' => []], $res->getData());
	}

	public function testApplyReturnsServiceResult(): void {
		$result = ['items' => [], 'moved' => 0, 'failed' => 0];
		$this->service->method('apply')->willReturn($result);
		$this->assertSame($result, $this->controller->apply([1], '{title}')->getData());
	}

	public function testEmptySelectionIsRejected(): void {
		$this->service->expects($this->never())->method('preview');
		$this->expectException(OCSBadRequestException::class);
		$this->controller->preview([], '{title}');
	}

	public function testTooManyFilesAreRejected(): void {
		$this->service->expects($this->never())->method('apply');
		$this->expectException(OCSBadRequestException::class);
		$this->controller->apply(range(1, 501), '{title}');
	}

	public function testEmptyOrHugePatternIsRejected(): void {
		foreach (['', '   ', str_repeat('x', 501)] as $pattern) {
			try {
				$this->controller->preview([1], $pattern);
				$this->fail('expected exception');
			} catch (OCSBadRequestException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testServiceValidationErrorBecomes400(): void {
		$this->service->method('apply')->willThrowException(new OrganizeException('bad folder'));
		$this->expectException(OCSBadRequestException::class);
		$this->controller->apply([1], '{title}', '/x/../y');
	}

	public function testNotLoggedIn(): void {
		$controller = new OrganizeController($this->createMock(IRequest::class), null, $this->service);
		$this->expectException(OCSForbiddenException::class);
		$controller->preview([1], '{title}');
	}
}
