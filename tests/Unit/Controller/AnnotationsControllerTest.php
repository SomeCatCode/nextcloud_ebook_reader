<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\AnnotationsController;
use OCA\EbookReader\Db\Annotation;
use OCA\EbookReader\Service\AnnotationService;
use OCA\EbookReader\Service\LibraryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AnnotationsControllerTest extends TestCase {
	private const UUID = '3f2b8c1e-5a4d-4e6f-9a7b-1c2d3e4f5a6b';

	private AnnotationService&MockObject $service;
	private LibraryService&MockObject $library;
	private AnnotationsController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(AnnotationService::class);
		$this->library = $this->createMock(LibraryService::class);
		$this->controller = new AnnotationsController($this->createMock(IRequest::class), 'u', $this->service, $this->library);
	}

	private function annotation(): Annotation {
		$a = new Annotation();
		$a->setUuid(self::UUID);
		$a->setFileId(5);
		$a->setLocator('{"href":"a"}');
		return $a;
	}

	private function readable(bool $canRead = true): void {
		$this->library->method('getFileForUser')->willReturn($this->createMock(File::class));
		$this->library->method('canReadContent')->willReturn($canRead);
	}

	public function testIndexListsOnlyForTheSessionUser(): void {
		$this->readable();
		$this->service->expects($this->once())->method('list')->with('u', 5)->willReturn([$this->annotation()]);
		$r = $this->controller->index(5);
		$this->assertSame(Http::STATUS_OK, $r->getStatus());
		$this->assertCount(1, $r->getData()['annotations']);
		$this->assertSame(self::UUID, $r->getData()['annotations'][0]['uuid']);
	}

	public function testIndexUnknownFileIsNotFound(): void {
		$this->library->method('getFileForUser')->willThrowException(new NotFoundException());
		$this->expectException(OCSNotFoundException::class);
		$this->controller->index(5);
	}

	public function testViewOnlyShareIsForbidden(): void {
		$this->readable(false);
		$this->service->expects($this->never())->method('list');
		$this->expectException(OCSForbiddenException::class);
		$this->controller->index(5);
	}

	public function testCreateStoresAndInvalidInputIsBadRequest(): void {
		$this->readable();
		$a = $this->annotation();
		$this->service->expects($this->once())->method('upsert')->with('u', 5, self::UUID, 'highlight', ['href' => 'a'], 'x', null, 'yellow', 10, null)->willReturn(['status' => 'ok', 'annotation' => $a]);
		$r = $this->controller->create(5, 'highlight', ['href' => 'a'], self::UUID, 'x', null, 'yellow', 10);
		$this->assertSame(Http::STATUS_OK, $r->getStatus());
	}

	public function testCreateInvalidInputIsBadRequest(): void {
		$this->readable();
		$this->service->method('upsert')->willThrowException(new \InvalidArgumentException('bad'));
		$this->expectException(OCSBadRequestException::class);
		$this->controller->create(5, 'nope', ['href' => 'a']);
	}

	public function testCreateConflictReturns409WithCurrent(): void {
		$this->readable();
		$this->service->method('upsert')->willReturn(['status' => 'conflict', 'annotation' => $this->annotation()]);
		$r = $this->controller->create(5, 'highlight', ['href' => 'a'], self::UUID);
		$this->assertSame(Http::STATUS_CONFLICT, $r->getStatus());
		$this->assertSame(self::UUID, $r->getData()['current']['uuid']);
	}

	public function testUpdateMissingIsNotFoundAndConflictIs409(): void {
		$this->service->method('patch')->willReturnOnConsecutiveCalls(null, ['status' => 'conflict', 'annotation' => $this->annotation()], ['status' => 'ok', 'annotation' => $this->annotation()]);
		try {
			$this->controller->update(self::UUID, null, null, 'n');
			$this->fail('expected not found');
		} catch (OCSNotFoundException) {
		}
		$this->assertSame(Http::STATUS_CONFLICT, $this->controller->update(self::UUID, null, null, 'n')->getStatus());
		$this->assertSame(Http::STATUS_OK, $this->controller->update(self::UUID, null, null, 'n')->getStatus());
	}

	public function testDestroyReturnsTombstone(): void {
		$a = $this->annotation();
		$a->setDeleted(1);
		$this->service->expects($this->once())->method('delete')->with('u', self::UUID, null)->willReturn(['status' => 'ok', 'annotation' => $a]);
		$r = $this->controller->destroy(self::UUID);
		$this->assertTrue($r->getData()['deleted']);
	}

	public function testDestroyMissingIsNotFound(): void {
		$this->service->method('delete')->willReturn(null);
		$this->expectException(OCSNotFoundException::class);
		$this->controller->destroy(self::UUID);
	}
}
