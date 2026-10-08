<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\SeriesController;
use OCA\EbookReader\Db\SeriesShare;
use OCA\EbookReader\Service\BookQuery;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\ShareService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SeriesControllerTest extends TestCase {
	private LibraryService&MockObject $library;
	private ShareService&MockObject $sharing;
	private SeriesController $controller;

	protected function setUp(): void {
		$this->library = $this->createMock(LibraryService::class);
		$this->sharing = $this->createMock(ShareService::class);
		$this->controller = new SeriesController($this->createMock(IRequest::class), 'alice', $this->library, $this->sharing);
	}

	/** @return array{name: string, count: int, readCount: int, coverFileIds: list<int>, firstFileId: int, lastAddedAt: int, shared: bool} */
	private static function entry(string $name, bool $shared = false): array {
		return ['name' => $name, 'count' => 2, 'readCount' => 0, 'coverFileIds' => [1], 'firstFileId' => 1, 'lastAddedAt' => 5, 'shared' => $shared];
	}

	public function testEntriesCarrySharedWithAndSharedFlags(): void {
		$this->library->method('listSeries')->willReturn([self::entry('Saga'), self::entry('Other', true), self::entry('Private')]);
		$this->sharing->method('seriesShareCounts')->with('alice')->willReturn([SeriesShare::keyOf('saga') => 3]);
		$series = $this->controller->index()->getData()['series'];
		$this->assertSame(3, $series[0]['sharedWith']);
		$this->assertFalse($series[0]['shared']);
		$this->assertSame(0, $series[1]['sharedWith']);
		$this->assertTrue($series[1]['shared']);
		$this->assertSame(0, $series[2]['sharedWith']);
	}

	public function testWorksWithoutTheShareService(): void {
		$controller = new SeriesController($this->createMock(IRequest::class), 'alice', $this->library);
		$this->library->method('listSeries')->willReturn([self::entry('Saga')]);
		$this->assertSame(0, $controller->index()->getData()['series'][0]['sharedWith']);
	}

	public function testSharedFilterIsForwarded(): void {
		$seen = [];
		$this->library->method('listSeries')->willReturnCallback(static function (string $u, BookQuery $q) use (&$seen): array {
			$seen[] = $q->shared;
			return [];
		});
		$this->controller->index(shared: 'incoming');
		$this->controller->index();
		$this->assertSame(['incoming', null], $seen);
	}

	public function testNotLoggedInIsForbidden(): void {
		$controller = new SeriesController($this->createMock(IRequest::class), null, $this->library);
		$this->expectException(OCSForbiddenException::class);
		$controller->index();
	}
}
