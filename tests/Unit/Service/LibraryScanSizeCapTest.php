<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\TagMapper;
use OCA\EbookReader\Metadata\BookMetadata;
use OCA\EbookReader\Metadata\MetadataService;
use OCA\EbookReader\Service\CoverService;
use OCA\EbookReader\Service\GenreClassifier;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/OcHooksEmitterStub.php';

/** Interactive scans index only files up to 50 MB inline; larger ones are always queued. */
class LibraryScanSizeCapTest extends TestCase {
	private BookMapper&MockObject $books;
	private IRootFolder&MockObject $root;
	private IJobList&MockObject $jobList;
	private MetadataService&MockObject $metadata;
	private LibraryService $service;

	protected function setUp(): void {
		$this->books = $this->createMock(BookMapper::class);
		$tags = $this->createMock(TagMapper::class);
		$tags->method('findByBook')->willReturn([]);
		$tags->method('countByNameForUser')->willReturn([]);
		$this->metadata = $this->createMock(MetadataService::class);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('get')->willReturn(['libraryFolders' => ['/Books'], 'reader' => [], 'filenamePattern' => '', 'genreList' => []]);
		$this->root = $this->createMock(IRootFolder::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->service = new LibraryService(
			$this->books,
			$tags,
			$this->metadata,
			$this->createMock(CoverService::class),
			new GenreClassifier($settings, $tags),
			$settings,
			$this->root,
			$this->jobList,
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function sizedFile(int $id, int $size): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('f' . $id . '.epub');
		$file->method('getMimeType')->willReturn('application/epub+zip');
		$file->method('getId')->willReturn($id);
		$file->method('getMTime')->willReturn(100);
		$file->method('getEtag')->willReturn('e' . $id);
		$file->method('getSize')->willReturn($size);
		$file->method('getPath')->willReturn('/u/files/Books/f' . $id . '.epub');
		return $file;
	}

	public function testInteractiveScanQueuesFilesOver50MbEvenWithinTheTimeBudget(): void {
		$small = $this->sizedFile(5, 10 * 1024 * 1024);
		$exactly = $this->sizedFile(6, LibraryService::INTERACTIVE_MAX_BYTES);
		$big = $this->sizedFile(7, 400 * 1024 * 1024);
		$booksFolder = $this->createMock(Folder::class);
		$booksFolder->method('getDirectoryListing')->willReturn([$small, $exactly, $big]);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->willReturn('/Books/x.epub');
		$userFolder->method('get')->willReturnCallback(static function (string $path) use ($booksFolder): Folder {
			if ($path === 'Books') {
				return $booksFolder;
			}
			throw new NotFoundException();
		});
		$this->root->method('getUserFolder')->willReturn($userFolder);
		$this->books->method('findAllByUser')->willReturn([]);
		$this->books->method('findByUserAndFile')->willThrowException(new DoesNotExistException(''));
		$this->metadata->method('detectFormat')->willReturn('epub');
		$this->metadata->method('extract')->willReturn(new BookMetadata(title: 'T'));
		$this->books->method('insert')->willReturnCallback(function (Book $b): Book {
			$b->setId($b->getFileId());
			return $b;
		});
		$queued = [];
		$this->jobList->method('add')->willReturnCallback(function (string $class, $arg) use (&$queued): void {
			$queued[] = $arg['fileId'];
		});

		$stats = $this->service->scanUserInteractive('u', 600.0);

		$this->assertSame(['found' => 3, 'indexed' => 2, 'queued' => 1], $stats);
		$this->assertSame([7], $queued, 'only the 400 MB file is queued; exactly 50 MB is still indexed inline');
	}
}
