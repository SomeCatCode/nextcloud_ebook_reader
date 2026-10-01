<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\ConvertController;
use OCA\EbookReader\Controller\EditorController;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\ProgressMapper;
use OCA\EbookReader\Db\TagMapper;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Service\ConvertService;
use OCA\EbookReader\Service\CoverService;
use OCA\EbookReader\Service\EditorService;
use OCA\EbookReader\Service\GenreClassifier;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\SettingsService;
use OCA\EbookReader\Service\TaskService;
use OCP\AppFramework\Http;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\ISharedStorage;
use OCP\Files\Storage\IStorage;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Share\IAttributes;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../Service/OcHooksEmitterStub.php';

/** Regression tests A2: download-disabled ("view-only") shares never hand out content. */
class ViewOnlyShareTest extends TestCase {
	private function library(): LibraryService {
		$settings = $this->createMock(SettingsService::class);
		$tags = $this->createMock(TagMapper::class);
		return new LibraryService(
			$this->createMock(BookMapper::class),
			$tags,
			$this->createMock(\OCA\EbookReader\Metadata\MetadataService::class),
			$this->createMock(CoverService::class),
			new GenreClassifier($settings, $tags),
			$settings,
			$this->createMock(IRootFolder::class),
			$this->createMock(IJobList::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function fileOnShare(?bool $download, bool $canSeeContent = true): File&MockObject {
		$attributes = $this->createMock(IAttributes::class);
		$attributes->method('getAttribute')->willReturnCallback(static fn (string $scope, string $key): mixed => $scope === 'permissions' && $key === 'download' ? $download : null);
		$share = $this->createMock(IShare::class);
		$share->method('getAttributes')->willReturn($download === null ? null : $attributes);
		$share->method('canSeeContent')->willReturn($canSeeContent);
		$storage = $this->createMock(ISharedStorage::class);
		$storage->method('instanceOfStorage')->willReturnCallback(static fn (string $c): bool => $c === ISharedStorage::class);
		$storage->method('getShare')->willReturn($share);
		$file = $this->createMock(File::class);
		$file->method('getStorage')->willReturn($storage);
		return $file;
	}

	public function testPlainStorageIsReadable(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('instanceOfStorage')->willReturn(false);
		$file = $this->createMock(File::class);
		$file->method('getStorage')->willReturn($storage);
		$this->assertTrue($this->library()->canReadContent($file));
	}

	public function testShareWithDownloadEnabledIsReadable(): void {
		$this->assertTrue($this->library()->canReadContent($this->fileOnShare(true)));
		$this->assertTrue($this->library()->canReadContent($this->fileOnShare(null)));
	}

	public function testShareWithDownloadDisabledIsNotReadable(): void {
		$this->assertFalse($this->library()->canReadContent($this->fileOnShare(false)));
	}

	public function testShareWithoutContentAccessIsNotReadable(): void {
		$this->assertFalse($this->library()->canReadContent($this->fileOnShare(true, false)));
	}

	public function testEditorAndConvertEndpointsReturn403(): void {
		$library = $this->createMock(LibraryService::class);
		$library->method('getFileForUser')->willReturn($this->createMock(File::class));
		$library->method('canReadContent')->willReturn(false);
		$editorService = $this->createMock(EditorService::class);
		$editorService->expects($this->never())->method('getStructure');
		$editorService->expects($this->never())->method('save');
		$convertService = $this->createMock(ConvertService::class);
		$convertService->expects($this->never())->method('convert');
		$serializer = new BookSerializer($library, $this->createMock(TagMapper::class), $this->createMock(ProgressMapper::class));
		$logger = $this->createMock(LoggerInterface::class);
		$request = $this->createMock(IRequest::class);

		$editor = new EditorController($request, 'u', $editorService, $library, $serializer, $logger, $this->createMock(TaskService::class));
		$this->assertSame(Http::STATUS_FORBIDDEN, $editor->structure(5)->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $editor->save(5)->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $editor->save(5, 'e', true)->getStatus());

		$convert = new ConvertController($request, 'u', $convertService, $library, $serializer, $logger, $this->createMock(TaskService::class));
		$this->assertSame(Http::STATUS_FORBIDDEN, $convert->convertBook(5, 'cbz')->getStatus());
	}

	public function testSerializerMarksViewOnlyBooksAsNotDownloadable(): void {
		$library = $this->createMock(LibraryService::class);
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(false);
		$library->method('getFileForUser')->willReturn($file);
		$library->method('canReadContent')->willReturn(false);
		$serializer = new BookSerializer($library, $this->createMock(TagMapper::class), $this->createMock(ProgressMapper::class));
		$book = new \OCA\EbookReader\Db\Book();
		$book->setFileId(5);
		$data = $serializer->serialize('u', $book, []);
		$this->assertFalse($data['downloadable']);
		$this->assertFalse($data['editable']);
	}
}
