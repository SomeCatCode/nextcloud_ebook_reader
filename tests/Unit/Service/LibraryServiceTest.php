<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\BackgroundJob\WriteMetadataJob;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Tag;
use OCA\EbookReader\Db\TagMapper;
use OCA\EbookReader\Metadata\BookMetadata;
use OCA\EbookReader\Metadata\MetadataService;
use OCA\EbookReader\Metadata\SidecarService;
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

require_once __DIR__ . '/OcHooksEmitterStub.php';

class LibraryServiceTest extends TestCase {
	private BookMapper&MockObject $books;
	private TagMapper&MockObject $tags;
	private MetadataService&MockObject $metadata;
	private CoverService&MockObject $covers;
	private SettingsService&MockObject $settings;
	private IJobList&MockObject $jobList;
	private IRootFolder&MockObject $root;
	private SidecarService&MockObject $sidecar;
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
		$this->jobList = $this->createMock(IJobList::class);
		$this->sidecar = $this->createMock(SidecarService::class);
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
			$this->jobList,
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class),
			$this->sidecar,
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

	public function testIndexFileKeepsDatabaseMetadataWhileWriteJobIsPending(): void {
		$this->metadata->method('detectFormat')->willReturn('epub');
		$existing = new Book();
		$existing->setId(3);
		$existing->setTitle('Edited in library');
		$existing->setAuthorsArray(['Me']);
		$existing->setFileMtime(1);
		$existing->setFileEtag('old');
		$this->books->method('findByUserAndFile')->willReturn($existing);
		$this->jobList->method('has')->with(WriteMetadataJob::class, ['userId' => 'u', 'fileId' => 5])->willReturn(true);
		$this->metadata->method('extract')->willReturn(new BookMetadata(title: 'Old file title', authors: ['Other'], genres: ['Krimi'], coverData: 'IMG'));
		$this->covers->method('storeCover')->willReturn('c2');
		$this->books->expects($this->once())->method('update')->willReturnArgument(0);
		// file tags must not be replaced either
		$this->tags->expects($this->never())->method('deleteByBook');

		$book = $this->service->indexFile('u', $this->file(5, 200, 'new'));
		$this->assertNotNull($book);
		$this->assertSame('Edited in library', $book->getTitle());
		$this->assertSame(['Me'], $book->getAuthorsArray());
		// but file facts are refreshed
		$this->assertSame(200, $book->getFileMtime());
		$this->assertSame('new', $book->getFileEtag());
		$this->assertSame(1234, $book->getSize());
		$this->assertSame('c2', $book->getCoverEtag());
		$this->assertSame([], $this->insertedTags);
	}

	public function testIndexFileKeepsOverriddenFieldsAfterFileChange(): void {
		$this->metadata->method('detectFormat')->willReturn('mobi');
		$existing = new Book();
		$existing->setId(3);
		$existing->setTitle('My title');
		$existing->setAuthorsArray(['Me']);
		$existing->setPublisher('Old publisher');
		$existing->setOverridesArray(['title', 'authors']);
		$existing->setFileMtime(1);
		$existing->setFileEtag('old');
		$this->books->method('findByUserAndFile')->willReturn($existing);
		$this->metadata->method('extract')->willReturn(new BookMetadata(title: 'File title', authors: ['File author'], publisher: 'New publisher'));
		$this->books->method('update')->willReturnArgument(0);

		$book = $this->service->indexFile('u', $this->file(5, 200, 'new'));
		$this->assertNotNull($book);
		$this->assertSame('My title', $book->getTitle());
		$this->assertSame(['Me'], $book->getAuthorsArray());
		// fields without override follow the file
		$this->assertSame('New publisher', $book->getPublisher());
		$this->assertSame(200, $book->getFileMtime());
		$this->assertSame(['title', 'authors'], $book->getOverridesArray());
	}

	public function testIndexFileTakesTheAgeRatingFromTheFileUnlessSetByHand(): void {
		$this->metadata->method('detectFormat')->willReturn('cbz');
		$existing = new Book();
		$existing->setId(3);
		$existing->setFileMtime(1);
		$existing->setFileEtag('old');
		$existing->setCompletion(Book::COMPLETION_ONGOING);
		$this->books->method('findByUserAndFile')->willReturn($existing);
		$this->metadata->method('extract')->willReturn(new BookMetadata(title: 'T', ageRating: 16));
		$this->books->method('update')->willReturnArgument(0);

		$book = $this->service->indexFile('u', $this->file(5, 200, 'new'));
		$this->assertNotNull($book);
		$this->assertSame(16, $book->getAgeRating());
		$this->assertSame(16, $book->getAgeRatingFile());
		$this->assertFalse($book->getAgeRatingManual());
		// completion is app data: untouched by indexing
		$this->assertSame(Book::COMPLETION_ONGOING, $book->getCompletion());

		// a manual value (here: explicitly none) survives the next re-index, the file value is still remembered
		$book->setManualAgeRating(null);
		$book = $this->service->indexFile('u', $this->file(5, 300, 'newer'));
		$this->assertNotNull($book);
		$this->assertNull($book->getAgeRating());
		$this->assertSame(16, $book->getAgeRatingFile());
		$book->resetAgeRating();
		$this->assertSame(16, $book->getAgeRating());
		$this->assertFalse($book->getAgeRatingManual());
	}

	public function testResetOverridesRereadsOnlyThatField(): void {
		$this->metadata->method('detectFormat')->willReturn('mobi');
		$existing = new Book();
		$existing->setId(3);
		$existing->setTitle('My title');
		$existing->setAuthorsArray(['Me']);
		$existing->setOverridesArray(['title', 'authors']);
		$existing->setFileMtime(100);
		$existing->setFileEtag('e1');
		$this->books->method('findByUserAndFile')->willReturn($existing);
		$this->books->method('update')->willReturnArgument(0);
		$this->metadata->method('extract')->willReturn(new BookMetadata(title: 'File title', authors: ['File author']));
		$file = $this->file();
		$userFolder = $this->root->getUserFolder('u');
		$userFolder->method('getFirstNodeById')->willReturn($file);
		$file->method('isReadable')->willReturn(true);

		$book = $this->service->resetOverrides('u', 5, 'title');
		$this->assertSame('File title', $book->getTitle());
		$this->assertSame(['Me'], $book->getAuthorsArray());
		$this->assertSame(['authors'], $book->getOverridesArray());

		$book = $this->service->resetOverrides('u', 5, null);
		$this->assertSame(['File author'], $book->getAuthorsArray());
		$this->assertSame([], $book->getOverridesArray());
	}

	public function testResetOverridesRejectsUnknownField(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->resetOverrides('u', 5, 'rating');
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

	// ------------------------------------------------------------------ sidecar files

	public function testPrecedenceOverrideThenSidecarThenEmbedded(): void {
		$this->metadata->method('detectFormat')->willReturn('epub');
		$existing = new Book();
		$existing->setId(3);
		$existing->setTitle('Override title');
		$existing->setOverridesArray(['title']);
		$existing->setFileMtime(1);
		$existing->setFileEtag('old');
		$this->books->method('findByUserAndFile')->willReturn($existing);
		$this->books->method('update')->willReturnArgument(0);
		$this->sidecar->method('etagOf')->willReturn('s1:5');
		$this->sidecar->method('read')->willReturn(new \OCA\EbookReader\Metadata\SidecarData(
			new BookMetadata(title: 'Sidecar title', authors: ['Side A'], publisher: 'Side Pub', genres: ['Krimi'], tags: ['mine']),
			true,
		));
		$this->metadata->method('extract')->willReturn(new BookMetadata(
			title: 'Embedded title',
			authors: ['Emb'],
			publisher: 'Emb Pub',
			language: 'de',
			genres: ['Fantasy'],
			tags: ['embtag'],
		));

		$book = $this->service->indexFile('u', $this->file(5, 200, 'new'));
		$this->assertNotNull($book);
		$this->assertSame('Override title', $book->getTitle(), 'app override wins');
		$this->assertSame(['Side A'], $book->getAuthorsArray(), 'sidecar beats embedded');
		$this->assertSame('Side Pub', $book->getPublisher());
		$this->assertSame('de', $book->getLanguage(), 'fields missing in the sidecar come from the file');
		$this->assertSame('s1:5', $book->getSidecarEtag());
		$names = [];
		foreach ($this->insertedTags as $t) {
			$names[] = $t->getType() . ':' . $t->getName();
		}
		$this->assertSame(['genre:Krimi', 'tag:mine'], $names, 'genres/tags of the sidecar replace the embedded ones');
	}

	public function testSidecarWithoutLabelsKeepsEmbeddedGenresAndTags(): void {
		$this->metadata->method('detectFormat')->willReturn('epub');
		$this->books->method('findByUserAndFile')->willThrowException(new DoesNotExistException(''));
		$this->books->method('insert')->willReturnCallback(function (Book $b): Book {
			$b->setId(7);
			return $b;
		});
		$this->sidecar->method('etagOf')->willReturn('s1:5');
		$this->sidecar->method('read')->willReturn(new \OCA\EbookReader\Metadata\SidecarData(new BookMetadata(title: 'Only a title')));
		$this->metadata->method('extract')->willReturn(new BookMetadata(title: 'Embedded', genres: ['Fantasy'], tags: ['embtag']));

		$book = $this->service->indexFile('u', $this->file());
		$this->assertSame('Only a title', $book?->getTitle());
		$names = [];
		foreach ($this->insertedTags as $t) {
			$names[] = $t->getType() . ':' . $t->getName();
		}
		$this->assertSame(['genre:Fantasy', 'tag:embtag'], $names);
	}

	public function testChangedSidecarTriggersReindexOfUnchangedBook(): void {
		$this->metadata->method('detectFormat')->willReturn('epub');
		$existing = new Book();
		$existing->setId(3);
		$existing->setFileMtime(100);
		$existing->setFileEtag('e1');
		$existing->setPath('/Books/x.epub');
		$existing->setFormat('epub');
		$existing->setSidecarEtag('s1:5');
		$this->books->method('findByUserAndFile')->willReturn($existing);
		$this->books->method('update')->willReturnArgument(0);
		$this->sidecar->method('etagOf')->willReturn('s2:9');
		$this->sidecar->method('read')->willReturn(new \OCA\EbookReader\Metadata\SidecarData(new BookMetadata(title: 'Changed in sidecar')));
		$this->metadata->expects($this->once())->method('extract')->willReturn(new BookMetadata(title: 'Embedded'));

		$book = $this->service->indexFile('u', $this->file());
		$this->assertSame('Changed in sidecar', $book?->getTitle());
		$this->assertSame('s2:9', $book->getSidecarEtag());
	}

	public function testUnchangedSidecarAndBookAreNotReindexed(): void {
		$this->metadata->method('detectFormat')->willReturn('epub');
		$existing = new Book();
		$existing->setFileMtime(100);
		$existing->setFileEtag('e1');
		$existing->setPath('/Books/x.epub');
		$existing->setFormat('epub');
		$existing->setSidecarEtag('s1:5');
		$this->books->method('findByUserAndFile')->willReturn($existing);
		$this->sidecar->method('etagOf')->willReturn('s1:5');
		$this->metadata->expects($this->never())->method('extract');
		$this->assertSame($existing, $this->service->indexFile('u', $this->file()));
	}

	public function testRemovedSidecarTriggersReindex(): void {
		$this->metadata->method('detectFormat')->willReturn('epub');
		$existing = new Book();
		$existing->setId(3);
		$existing->setFileMtime(100);
		$existing->setFileEtag('e1');
		$existing->setSidecarEtag('s1:5');
		$this->books->method('findByUserAndFile')->willReturn($existing);
		$this->books->method('update')->willReturnArgument(0);
		$this->sidecar->method('etagOf')->willReturn(null);
		$this->metadata->expects($this->once())->method('extract')->willReturn(new BookMetadata(title: 'Embedded'));
		$book = $this->service->indexFile('u', $this->file());
		$this->assertNull($book?->getSidecarEtag());
		$this->assertSame('Embedded', $book->getTitle());
	}

	public function testSidecarFilesAreNeverIndexedAsBooks(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('.x.epub.opf');
		$file->method('getMimeType')->willReturn('application/epub+zip');
		$this->metadata->method('detectFormat')->willReturn('epub');
		$this->metadata->expects($this->never())->method('extract');
		$this->books->expects($this->never())->method('insert');
		$this->assertNull($this->service->indexFile('u', $file));
	}

	public function testDeleteFileForUserDeletesTheSidecarToo(): void {
		$parent = $this->createMock(Folder::class);
		$file = $this->file();
		$file->method('isReadable')->willReturn(true);
		$file->method('isDeletable')->willReturn(true);
		$file->method('getParent')->willReturn($parent);
		$this->root->getUserFolder('u')->method('getFirstNodeById')->willReturn($file);
		$this->books->method('findByUserAndFile')->willThrowException(new DoesNotExistException(''));
		$file->expects($this->once())->method('delete');
		$this->sidecar->expects($this->once())->method('deleteFor')->with($parent, 'x.epub');
		$this->service->deleteFileForUser('u', 5);
	}

	public function testWalkSkipsSidecarsAndReportsTheirChangeMarker(): void {
		$book = $this->createMock(File::class);
		$book->method('getName')->willReturn('a.epub');
		$book->method('getMimeType')->willReturn('application/epub+zip');
		$book->method('getId')->willReturn(1);
		$sidecar = $this->createMock(File::class);
		$sidecar->method('getName')->willReturn('.a.epub.opf');
		$sidecar->method('getMimeType')->willReturn('application/epub+zip');
		$sidecar->method('getId')->willReturn(2);
		$sidecar->method('getEtag')->willReturn('x');
		$sidecar->method('getMTime')->willReturn(7);
		$lonely = $this->createMock(File::class);
		$lonely->method('getName')->willReturn('b.epub');
		$lonely->method('getMimeType')->willReturn('application/epub+zip');
		$lonely->method('getId')->willReturn(3);
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$book, $sidecar, $lonely]);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturnCallback(static fn (string $p) => $p === 'Books' ? $folder : throw new \OCP\Files\NotFoundException());
		$this->root->method('getUserFolder')->willReturn($userFolder);
		$this->metadata->method('detectFormat')->willReturn('epub');

		$seen = [];
		$this->service->walkLibrary('u', function (File $f, string $format, ?string $marker) use (&$seen): void {
			$seen[$f->getId()] = $marker;
		});
		$this->assertSame([1 => 'x:7', 3 => null], $seen);
	}
}
