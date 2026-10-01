<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\ArchiveController;
use OCA\EbookReader\Controller\ItemController;
use OCA\EbookReader\Service\ArchiveCache;
use OCA\EbookReader\Service\LibraryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\Storage\IStorage;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ArchiveControllerTest extends TestCase {
	private LibraryService&MockObject $library;
	private string $ifNoneMatch = '';
	/** @var list<string> */
	private array $tmp = [];

	protected function setUp(): void {
		$this->library = $this->createMock(LibraryService::class);
	}

	protected function tearDown(): void {
		foreach ($this->tmp as $f) {
			@unlink($f);
		}
	}

	private function controller(): ArchiveController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (): string => $this->ifNoneMatch);
		$cache = new ArchiveCache($this->createMock(ITempManager::class), $this->createMock(IAppConfig::class), $this->createMock(LoggerInterface::class));
		return new ArchiveController($request, $session, $this->library, $cache, $this->createMock(LoggerInterface::class));
	}

	/** @param array<string, string> $entries */
	private function archive(string $name, array $entries): File&MockObject {
		$path = tempnam(sys_get_temp_dir(), 'arc');
		$this->tmp[] = $path;
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		foreach ($entries as $n => $c) {
			$zip->addFromString($n, $c);
		}
		$zip->close();
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('instanceOfStorage')->willReturn(false);
		$storage->method('getLocalFile')->willReturn($path);
		$file = $this->createMock(File::class);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/' . $name);
		$file->method('getName')->willReturn($name);
		$file->method('getEtag')->willReturn('etag-1');
		return $file;
	}

	public function testListsEntriesWithSizesAndCachingHeaders(): void {
		$this->library->method('getFileForUser')->willReturn($this->archive('b.epub', ['mimetype' => 'application/epub+zip', 'OEBPS/ch1.xhtml' => '<p>hello</p>', 'dir/' => '']));
		$this->library->method('canReadContent')->willReturn(true);
		$res = $this->controller()->entries(5);
		$this->assertInstanceOf(JSONResponse::class, $res);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame('etag-1', $res->getData()['etag']);
		$this->assertSame([['name' => 'mimetype', 'size' => 20], ['name' => 'OEBPS/ch1.xhtml', 'size' => 12]], $res->getData()['entries']);
		$this->assertNotNull($res->getETag());
	}

	public function testSecondRequestWithEtagIs304(): void {
		$this->library->method('getFileForUser')->willReturn($this->archive('b.cbz', ['1.png' => 'x']));
		$this->library->method('canReadContent')->willReturn(true);
		$first = $this->controller()->entries(5);
		$this->ifNoneMatch = '"' . $first->getETag() . '"';
		$second = $this->controller()->entries(5);
		$this->assertSame(Http::STATUS_NOT_MODIFIED, $second->getStatus());
	}

	public function testFbzIsListedAndOtherFormatsAre404(): void {
		$this->library->method('getFileForUser')->willReturnOnConsecutiveCalls(
			$this->archive('b.fb2.zip', ['b.fb2' => '<x/>']),
			$this->archive('b.mobi', ['a' => 'b']),
		);
		$this->library->method('canReadContent')->willReturn(true);
		$this->assertSame(Http::STATUS_OK, $this->controller()->entries(5)->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->entries(5)->getStatus());
	}

	public function testViewOnlyShareIs403(): void {
		$this->library->method('getFileForUser')->willReturn($this->archive('b.epub', ['a' => 'b']));
		$this->library->method('canReadContent')->willReturn(false);
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->entries(5)->getStatus());
	}

	public function testItemControllerAcceptsFbz(): void {
		$this->assertSame('fbz', ItemController::archiveType('Book.fbz'));
		$this->assertSame('fbz', ItemController::archiveType('Book.FB2.ZIP'));
		$this->assertSame('epub', ItemController::archiveType('a.epub'));
		$this->assertSame('cbz', ItemController::archiveType('a.cbz'));
		$this->assertNull(ItemController::archiveType('a.mobi'));
		$this->assertNull(ItemController::archiveType('a.zip'));
	}

	public function testItemEndpointRateLimitIsAtLeast1200PerMinute(): void {
		$method = new \ReflectionMethod(ItemController::class, 'item');
		$attrs = $method->getAttributes(\OCP\AppFramework\Http\Attribute\UserRateLimit::class);
		$this->assertCount(1, $attrs);
		$limit = $attrs[0]->newInstance();
		$this->assertGreaterThanOrEqual(1200, $limit->getLimit());
		$this->assertSame(60, $limit->getPeriod());
	}
}
