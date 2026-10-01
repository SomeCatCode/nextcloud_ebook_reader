<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\ComicController;
use OCA\EbookReader\Service\ArchiveTools;
use OCA\EbookReader\Service\LibraryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Files\Storage\IStorage;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ComicControllerTest extends TestCase {
	private LibraryService&MockObject $library;
	private ISimpleFolder&MockObject $cacheFolder;
	/** @var list<string> */
	private array $tmpFiles = [];

	protected function setUp(): void {
		$this->library = $this->createMock(LibraryService::class);
		$this->cacheFolder = $this->createMock(ISimpleFolder::class);
		$this->cacheFolder->method('getFile')->willThrowException(new NotFoundException());
	}

	protected function tearDown(): void {
		foreach ($this->tmpFiles as $f) {
			@unlink($f);
		}
	}

	private function controller(): ComicController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($this->cacheFolder);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		return new ComicController($request, $session, $this->library, $this->createMock(ITempManager::class),
			$appData, $cacheFactory, $this->createMock(LoggerInterface::class), new ArchiveTools([]));
	}

	private function fileFor(string $localPath, string $name): File&MockObject {
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn($localPath);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(42);
		$file->method('getEtag')->willReturn('etag1');
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/' . $name);
		return $file;
	}

	/** CBZ with pages named so that plain string sorting would get the order wrong. */
	private function makeCbz(int $bigWidth = 0): string {
		$path = tempnam(sys_get_temp_dir(), 'cbz');
		$this->tmpFiles[] = $path;
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		foreach (['page10.png', 'page2.png', 'page1.png'] as $name) {
			$zip->addFromString($name, $this->png(10, 15));
		}
		if ($bigWidth > 0) {
			$zip->addFromString('page3.png', $this->png($bigWidth, (int)($bigWidth * 1.5)));
		}
		$zip->addFromString('ComicInfo.xml', '<ComicInfo/>');
		$zip->addFromString('__MACOSX/._page1.png', 'x');
		$zip->close();
		return $path;
	}

	private function png(int $w, int $h): string {
		$img = imagecreatetruecolor($w, $h);
		ob_start();
		imagepng($img);
		return (string)ob_get_clean();
	}

	public function testPagesAreImagesInNaturalOrder(): void {
		$this->library->method('getFileForUser')->willReturn($this->fileFor($this->makeCbz(), 'c.cbz'));
		$response = $this->controller()->pages(42);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['page1.png', 'page2.png', 'page10.png'], array_column($response->getData()['pages'], 'name'));
	}

	public function testPageReturnsSmallImageUnchangedAndCachesIt(): void {
		$this->library->method('getFileForUser')->willReturn($this->fileFor($this->makeCbz(), 'c.cbz'));
		$this->cacheFolder->expects($this->once())->method('newFile');
		$response = $this->controller()->page(42, 0, 1600);
		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$size = getimagesizefromstring($response->getData());
		$this->assertSame(['image/png', 10, 15], [$size['mime'], $size[0], $size[1]]);
	}

	public function testLargePageIsScaledToWidthBucket(): void {
		$this->library->method('getFileForUser')->willReturn($this->fileFor($this->makeCbz(1500), 'c.cbz'));
		// requested 700 px -> bucket 800
		$response = $this->controller()->page(42, 2, 700);
		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$size = getimagesizefromstring($response->getData());
		$this->assertSame(['image/jpeg', 800, 1200], [$size['mime'], $size[0], $size[1]]);
	}

	public function testUnknownPageAndNonComicAre404(): void {
		$this->library->method('getFileForUser')->willReturnOnConsecutiveCalls(
			$this->fileFor($this->makeCbz(), 'c.cbz'),
			$this->fileFor(__FILE__, 'book.epub'),
		);
		$controller = $this->controller();
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->page(42, 99, 800)->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->pages(42)->getStatus());
	}
}
