<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Tag;
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
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . "/OcHooksEmitterStub.php";

class LibraryServiceTest extends TestCase {
	private BookMapper&MockObject $books;
	private TagMapper&MockObject $tags;
	private MetadataService&MockObject $metadata;
	private CoverService&MockObject $covers;
	private SettingsService&MockObject $settings;
	private IRootFolder&MockObject $root;
	private LibraryService $service;
	/** @var list<Tag> */
	private array $insertedTags = [];

	protected function setUp(): void {
		$this->books = $this->createMock(BookMapper::class);
		$this->tags = $this->createMock(TagMapper::class);
		$this->metadata = $this->createMock(MetadataService::class);
		$this->covers = $this->createMock(CoverService::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('get')->willReturn([
			'libraryFolders' => ['/Books', '/Comics/Marvel'],
			'reader' => [],
			'filenamePattern' => '',
			'genreList' => ['Fantasy', 'Krimi'],
		]);
		$this->root = $this->createMock(IRootFolder::class);
		$this->insertedTags = [];
		$this->tags->method('insert')->willReturnCallback(function (Tag $t): Tag {
			$this->insertedTags[] = $t;
			return $t;
		});
		$this->tags->method('findByBook')->willReturn([]);
		$this->tags->method('countByNameForUser')->willReturn([]);

		$this->service = new LibraryService(
			$this->books,
			$this->tags,
			$this->metadata,
			$this->covers,
			new GenreClassifier($this->settings, $this->tags),
			$this->settings,
			$this->root,
			$this->createMock(IJobList::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testIsPathInLibrary(): void {
		$this->assertTrue($this->service->isPathInLibrary('u', '/Books/x.epub'));
		$this->assertTrue($this->service->isPathInLibrary('u', '/Books/sub/dir/x.epub'));
		$this->assertTrue($this->service->isPathInLibrary('u', '/Comics/Marvel/a.cbz'));
		$this->assertFalse($this->service->isPathInLibrary('u', '/Comics/DC/a.cbz'));
		$this->assertFalse($this->service->isPathInLibrary('u', '/Books2/x.epub'), 'prefix must match on folder boundary');
		$this->assertFalse($this->service->isPathInLibrary('u', '/Documents/x.epub'));
	}

	private function file(int $id = 5, int $mtime = 100, string $etag = 'e1'): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('x.epub');
		$file->method('getMimeType')->willReturn('application/epub+zip');
		$file->method('getId')->willReturn($id);
		$file->method('getMTime')->willReturn($mtime);
		$file->method('getEtag')->willReturn($etag);
		$file->method('getSize')->willReturn(1234);
		$file->method('getPath')->willReturn('/u/files/Books/x.epub');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->willReturn('/Books/x.epub');
		$this->root->method('getUserFolder')->willReturn($userFolder);
		return $file;
	}

	public function testIndexFileIgnoresNonBooks(): void {
		$this->metadata->method('detectFormat')->willReturn(null);
		$this->assertNull($this->service->indexFile('u', $this->file()));
	}

	public function testIndexFileSkipsUnchangedFile(): void {
		$this->metadata->method('detectFormat')->willReturn('epub');
		$existing = new Book();
		$existing->setFileMtime(100);
		$existing->setFileEtag('e1');
		$existing->setPath('/Books/x.epub');
		$existing->setFormat('epub');
		$this->books->method('findByUserAndFile')->willReturn($existing);
		$this->metadata->expects($this->never())->method('extract');
		$this->books->expects($this->never())->method('update');
		$this->assertSame($existing, $this->service->indexFile('u', $this->file()));
	}

	public function testIndexFileForceReindexesUnchangedFile(): void {
		$this->metadata->method('detectFormat')->willReturn('epub');
		$existing = new Book();
		$existing->setId(3);
		$existing->setFileMtime(100);
		$existing->setFileEtag('e1');
		$this->books->method('findByUserAndFile')->willReturn($existing);
		$this->metadata->expects($this->once())->method('extract')->willReturn(new BookMetadata(title: 'T'));
		$this->books->expects($this->once())->method('update')->willReturnArgument(0);
		$this->service->indexFile('u', $this->file(), true);
	}

	public function testIndexNewFileStoresMetadataCoverAndClassifiedTags(): void {
		$this->metadata->method('detectFormat')->willReturn('epub');
		$this->books->method('findByUserAndFile')->willThrowException(new DoesNotExistException(''));
		$this->metadata->method('extract')->willReturn(new BookMetadata(
			title: 'Titel',
			authors: ['A', 'B'],
			series: 'S',
			seriesIndex: 2.0,
			isbn: '123',
			genres: ['Krimi'],
			tags: ['Eigenes'],
			subjects: ['fantasy', 'Lieblingsbuch', 'krimi'],
			coverData: 'IMG',
		));
		$this->covers->expects($this->once())->method('storeCover')->with(5, 'IMG')->willReturn('etag123');
		$this->books->expects($this->once())->method('insert')->willReturnCallback(function (Book $b): Book {
			$b->setId(77);
			return $b;
		});
		// file tags of the book are replaced (source=file only)
		$this->tags->expects($this->once())->method('deleteByBook')->with(77, null, Tag::SOURCE_FILE);

		$book = $this->service->indexFile('u', $this->file());
		$this->assertNotNull($book);
		$this->assertSame('u', $book->getUserId());
		$this->assertSame(5, $book->getFileId());
		$this->assertSame('/Books/x.epub', $book->getPath());
		$this->assertSame('epub', $book->getFormat());
		$this->assertSame(['A', 'B'], $book->getAuthorsArray());
		$this->assertSame('Titel', $book->getTitle());
		$this->assertSame(2.0, $book->getSeriesIndex());
		$this->assertTrue($book->getHasCover());
		$this->assertSame('etag123', $book->getCoverEtag());
		$this->assertSame(100, $book->getFileMtime());
		$this->assertSame('e1', $book->getFileEtag());
		$this->assertNull($book->getDeletedAt());
		$this->assertGreaterThan(1_700_000_000_000, $book->getUpdatedAt(), 'updated_at is in milliseconds');

		$byType = ['genre' => [], 'tag' => []];
		foreach ($this->insertedTags as $t) {
			$this->assertSame(Tag::SOURCE_FILE, $t->getSource());
			$this->assertSame(77, $t->getBookId());
			$byType[$t->getType()][] = $t->getName();
		}
		$this->assertSame(['Krimi', 'Fantasy'], $byType['genre']);
		$this->assertSame(['Eigenes', 'Lieblingsbuch'], $byType['tag']);
	}

	public function testRemoveFileCreatesTombstoneAndDropsTags(): void {
		$existing = new Book();
		$existing->setId(9);
		$this->books->method('findByUserAndFile')->willReturn($existing);
		$this->books->method('countActiveByFileId')->willReturn(0);
		$this->tags->expects($this->once())->method('deleteByBook')->with(9);
		$this->covers->expects($this->once())->method('deleteCover')->with(5);
		$this->books->expects($this->once())->method('update')->willReturnArgument(0);
		$this->service->removeFile('u', 5);
		$this->assertNotNull($existing->getDeletedAt());
		$this->assertSame($existing->getDeletedAt(), $existing->getUpdatedAt());
	}

	public function testRemoveFileKeepsCoverWhileOtherUsersHaveTheBook(): void {
		$existing = new Book();
		$existing->setId(9);
		$this->books->method('findByUserAndFile')->willReturn($existing);
		$this->books->method('countActiveByFileId')->willReturn(1);
		$this->covers->expects($this->never())->method('deleteCover');
		$this->service->removeFile('u', 5);
	}

	public function testRemoveUnknownFileIsNoop(): void {
		$this->books->method('findByUserAndFile')->willThrowException(new DoesNotExistException(''));
		$this->books->expects($this->never())->method('update');
		$this->service->removeFile('u', 5);
	}
}
