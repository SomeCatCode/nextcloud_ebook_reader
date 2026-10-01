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
use OCA\EbookReader\Metadata\BookMetadata;
use OCA\EbookReader\Metadata\MetadataService;
use OCA\EbookReader\Metadata\SidecarService;
use OCA\EbookReader\Service\ArchiveCache;
use OCA\EbookReader\Service\EditorService;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\ProgressService;
use OCA\EbookReader\Service\RenameService;
use OCA\EbookReader\Service\SettingsService;
use OCA\EbookReader\Tests\Unit\Editor\Fixtures;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IStorage;
use OCP\IAppConfig;
use OCP\ITempManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EditorServiceTest extends TestCase {
	private LibraryService&MockObject $library;
	private BookMapper&MockObject $books;
	private MetadataService&MockObject $metadata;
	private SettingsService&MockObject $settings;
	private ITempManager&MockObject $tempManager;
	private IJobList&MockObject $jobList;
	private ProgressService&MockObject $progress;
	private SidecarService&MockObject $sidecar;
	private RenameService&MockObject $renamer;
	private IFilenameValidator&MockObject $validator;
	private string $target = 'file';
	private EditorService $service;
	private string $mode = 'background';
	/** @var list<string> */
	private array $tmp = [];

	protected function setUp(): void {
		$this->library = $this->createMock(LibraryService::class);
		$this->books = $this->createMock(BookMapper::class);
		$this->metadata = $this->createMock(MetadataService::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('get')->willReturnCallback(fn (): array => [
			'libraryFolders' => ['/Books'], 'reader' => [], 'filenamePattern' => '', 'genreList' => [], 'metadataWriteMode' => $this->mode, 'metadataTarget' => $this->target,
		]);
		$this->tempManager = $this->createMock(ITempManager::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->progress = $this->createMock(ProgressService::class);
		$this->sidecar = $this->createMock(SidecarService::class);
		$this->renamer = $this->createMock(RenameService::class);
		$this->validator = $this->createMock(IFilenameValidator::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(500);
		$this->service = new EditorService(
			$this->library,
			$this->progress,
			$this->books,
			$this->metadata,
			$this->settings,
			$this->renamer,
			$this->createMock(IRootFolder::class),
			$this->tempManager,
			$appConfig,
			$this->validator,
			$this->createMock(LoggerInterface::class),
			$this->jobList,
			new ArchiveCache($this->tempManager, $appConfig, $this->createMock(LoggerInterface::class)),
			$this->sidecar,
		);
	}

	protected function tearDown(): void {
		foreach ($this->tmp as $f) {
			@unlink($f);
		}
	}

	private function book(string $title = 'Old'): Book {
		$b = new Book();
		$b->setId(9);
		$b->setUserId('u');
		$b->setFileId(5);
		$b->setTitle($title);
		$b->setAuthorsArray(['A']);
		return $b;
	}

	/** @param list<array{string, string}> $tags [type, name] */
	private function withBook(Book $book, array $tags = []): void {
		$this->library->method('getBook')->willReturn($book);
		$objs = [];
		foreach ($tags as [$type, $name]) {
			$t = new Tag();
			$t->setType($type);
			$t->setName($name);
			$objs[] = $t;
		}
		$this->library->method('getTags')->willReturn($objs);
	}

	/** A file node on which no content access is allowed at all. */
	private function untouchableFile(string $name = 'x.cbz'): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(5);
		$file->method('getEtag')->willReturn('etag1');
		$file->method('getSize')->willReturn(100 * 1024 * 1024);
		$file->method('isUpdateable')->willReturn(true);
		$file->expects($this->never())->method('fopen');
		$file->expects($this->never())->method('getContent');
		$file->expects($this->never())->method('putContent');
		$file->expects($this->never())->method('getStorage');
		$file->expects($this->never())->method('lock');
		$this->library->method('getFileForUser')->willReturn($file);
		return $file;
	}

	/** A file on local storage backed by a real file. */
	private function localFile(string $path): File&MockObject {
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('instanceOfStorage')->willReturn(false);
		$storage->method('getLocalFile')->willReturn($path);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('x.cbz');
		$file->method('getId')->willReturn(5);
		$file->method('getEtag')->willReturn('etag1');
		$file->method('getSize')->willReturn(1000);
		$file->method('isUpdateable')->willReturn(true);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/x.cbz');
		$file->expects($this->never())->method('fopen');
		$this->library->method('getFileForUser')->willReturn($file);
		return $file;
	}

	public function testSaveMetadataOnlyIsNoopWhenNothingChanges(): void {
		$this->untouchableFile();
		$this->withBook($this->book('Same'), [[Tag::TYPE_TAG, 'b'], [Tag::TYPE_TAG, 'a']]);
		$this->books->expects($this->never())->method('update');
		$this->jobList->expects($this->never())->method('add');
		$this->library->expects($this->never())->method('setTags');

		// same title (padded), same tags in other order
		$res = $this->service->saveMetadataOnly('u', 5, ['title' => ' Same ', 'tags' => ['a', 'b']]);
		$this->assertFalse($res['writeQueued']);
		$this->assertSame([], $res['warnings']);
	}

	public function testBackgroundModeUpdatesDatabaseAndQueuesJobWithoutFileAccess(): void {
		$this->untouchableFile();
		$this->withBook($this->book(), [[Tag::TYPE_TAG, 'a']]);
		$this->books->expects($this->once())->method('update')->willReturnArgument(0);
		$sources = [];
		$this->library->method('setTags')->willReturnCallback(function (int $id, string $type, array $names, string $source) use (&$sources): void {
			if ($names !== []) {
				$sources[$type . ':' . $source] = $names;
			}
		});
		$this->jobList->expects($this->once())->method('add')->with(WriteMetadataJob::class, ['userId' => 'u', 'fileId' => 5]);

		$res = $this->service->saveMetadataOnly('u', 5, ['tags' => ['a', 'new']]);
		$this->assertTrue($res['writeQueued']);
		$this->assertSame(['a', 'new'], $sources['tag:' . Tag::SOURCE_FILE]);
		$this->assertArrayNotHasKey('tag:' . Tag::SOURCE_APP, $sources);
	}

	public function testNeverModeOnlyStoresInDatabaseAndMarksOverride(): void {
		$this->target = 'library';
		$this->untouchableFile();
		$book = $this->book();
		$this->withBook($book);
		$this->books->expects($this->once())->method('update')->willReturnArgument(0);
		$this->jobList->expects($this->never())->method('add');
		$res = $this->service->saveMetadataOnly('u', 5, ['title' => 'New', 'tags' => ['x']]);
		$this->assertFalse($res['writeQueued']);
		$this->assertSame([], $res['warnings']);
		$this->assertSame(['title'], $book->getOverridesArray(), 'only changed overridable fields are flagged');
	}

	public function testNonWritableFormatOnlyStoresInDatabase(): void {
		$this->untouchableFile('x.mobi');
		$this->withBook($this->book());
		$this->books->expects($this->once())->method('update')->willReturnArgument(0);
		$this->jobList->expects($this->never())->method('add');
		$res = $this->service->saveMetadataOnly('u', 5, ['title' => 'New', 'series' => 'S']);
		$this->assertFalse($res['writeQueued']);
		$this->assertSame(['title', 'series'], $res['book']->getOverridesArray());
	}

	public function testStructureMetadataPartDoesNoFileIo(): void {
		$this->untouchableFile();
		$this->withBook($this->book('T'), [[Tag::TYPE_GENRE, 'Krimi']]);
		$this->jobList->expects($this->never())->method('has');

		$s = $this->service->getStructure('u', 5, 'metadata');
		$this->assertTrue($s['partial']);
		$this->assertSame([], $s['items']);
		$this->assertSame([], $s['toc']);
		$this->assertSame('etag1', $s['etag']);
		$this->assertTrue($s['editable']);
		$this->assertSame('cbz', $s['format']);
		$this->assertTrue($s['capabilities']['content']);
		$this->assertSame('T', $s['metadata']['title']);
		$this->assertSame(['Krimi'], $s['metadata']['genres']);
	}

	public function testStructureMetadataPartOfDbOnlyFormat(): void {
		$this->untouchableFile('x.mobi');
		$this->withBook($this->book());
		$s = $this->service->getStructure('u', 5, 'metadata');
		$this->assertFalse($s['capabilities']['content']);
		$this->assertFalse($s['capabilities']['writesFile']);
	}

	public function testStructureAllReadsLocalFileWithoutCopyAndKeepsIt(): void {
		$src = $this->tmp[] = Fixtures::cbz(3);
		$this->localFile($src);
		$this->withBook($this->book());
		$this->tempManager->expects($this->never())->method('getTemporaryFile');
		$s = $this->service->getStructure('u', 5);
		$this->assertFalse($s['partial']);
		$this->assertCount(4, $s['items']);
		$this->assertFileExists($src, 'the original must never be deleted');
	}

	public function testStructureAllFlushesPendingWriteFirst(): void {
		$src = $this->tmp[] = Fixtures::cbz(2);
		$this->localFile($src);
		$this->withBook($this->book('Same'));
		$this->jobList->method('has')->willReturn(true);
		$this->jobList->expects($this->once())->method('remove')->with(WriteMetadataJob::class, ['userId' => 'u', 'fileId' => 5]);
		// file already equals the library -> nothing is written, the job is gone
		$this->metadata->expects($this->once())->method('extractLocal')->willReturn(new BookMetadata(title: 'Same', authors: ['A']));
		$s = $this->service->getStructure('u', 5);
		$this->assertFalse($s['partial']);
	}

	public function testWritePendingMetadataIsNoopWhenFileAlreadyMatches(): void {
		$src = $this->tmp[] = Fixtures::cbz(2);
		$file = $this->localFile($src);
		$file->expects($this->never())->method('putContent');
		$file->expects($this->never())->method('lock');
		$this->withBook($this->book('Same'), [[Tag::TYPE_TAG, 'b'], [Tag::TYPE_GENRE, 'Krimi']]);
		$this->metadata->method('extractLocal')->willReturn(new BookMetadata(title: 'Same', authors: ['A'], genres: ['krimi'], tags: ['B']));
		$this->assertFalse($this->service->writePendingMetadata('u', 5));
		$this->assertFileExists($src);
	}

	public function testWritePendingMetadataWritesFullDbMetadataAndKeepsLocalSource(): void {
		$src = $this->tmp[] = Fixtures::cbz(2, true);
		$dst = $this->tmp[] = Fixtures::tmp('.cbz');
		$before = (string)file_get_contents($src);
		$file = $this->localFile($src);
		$this->withBook($this->book('From library'), [[Tag::TYPE_TAG, 'mine']]);
		$this->tempManager->method('getTemporaryFile')->willReturn($dst);
		$this->metadata->method('extractLocal')->willReturnCallback(
			fn (string $path): BookMetadata => $path === $src ? new BookMetadata(title: 'Old in file') : new BookMetadata(title: 'From library'),
		);
		$written = null;
		$file->expects($this->once())->method('putContent')->willReturnCallback(function ($stream) use (&$written): void {
			$written = stream_get_contents($stream);
			// like View::file_put_contents(): the stream is closed by Nextcloud (regression: fclose TypeError)
			fclose($stream);
		});
		$this->library->method('reindexFileForAllUsers');
		$this->books->method('update')->willReturnArgument(0);

		$this->assertTrue($this->service->writePendingMetadata('u', 5));
		$this->assertFileExists($src, 'a local source must not be unlinked');
		$this->assertSame($before, (string)file_get_contents($src));
		$this->assertNotNull($written);
		$out = $this->tmp[] = Fixtures::tmp('.cbz');
		file_put_contents($out, (string)$written);
		$z = new \ZipArchive();
		$z->open($out);
		$xml = (string)$z->getFromName('ComicInfo.xml');
		$z->close();
		$this->assertStringContainsString('<Title>From library</Title>', $xml);
		$this->assertStringContainsString('<Tags>mine</Tags>', $xml);
	}

	public function testOverridesAreClearedAfterTheFileWasWritten(): void {
		$src = $this->tmp[] = Fixtures::cbz(2, true);
		$dst = $this->tmp[] = Fixtures::tmp('.cbz');
		$file = $this->localFile($src);
		$book = $this->book('From library');
		$book->setOverridesArray(['title', 'publisher']);
		$this->withBook($book);
		$this->tempManager->method('getTemporaryFile')->willReturn($dst);
		$this->metadata->method('extractLocal')->willReturnCallback(
			fn (string $path): BookMetadata => $path === $src ? new BookMetadata(title: 'Old in file') : new BookMetadata(title: 'From library'),
		);
		$file->expects($this->once())->method('putContent');
		$this->books->method('update')->willReturnArgument(0);

		$this->assertTrue($this->service->writePendingMetadata('u', 5));
		$this->assertSame([], $book->getOverridesArray());
	}

	public function testEditorSaveClearsOnlyTheWrittenFields(): void {
		$src = $this->tmp[] = Fixtures::cbz(2, true);
		$dst = $this->tmp[] = Fixtures::tmp('.cbz');
		$file = $this->localFile($src);
		$book = $this->book();
		$book->setOverridesArray(['title', 'publisher']);
		$this->withBook($book);
		$this->tempManager->method('getTemporaryFile')->willReturn($dst);
		$this->metadata->method('extractLocal')->willReturn(new BookMetadata(title: 'X'));
		$file->expects($this->once())->method('putContent');
		$this->books->method('update')->willReturnArgument(0);

		$this->service->save('u', 5, ['etag' => 'etag1', 'metadata' => ['title' => 'Written']]);
		$this->assertSame(['publisher'], $book->getOverridesArray());
	}

	public function testWritePendingMetadataKeepsEditsAsOverridesWhenFileIsReadOnly(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('x.cbz');
		$file->method('getSize')->willReturn(1000);
		$file->method('isUpdateable')->willReturn(false);
		$file->expects($this->never())->method('fopen');
		$file->expects($this->never())->method('putContent');
		$this->library->method('getFileForUser')->willReturn($file);
		$book = $this->book('Edited');
		$this->withBook($book);
		$this->books->method('update')->willReturnArgument(0);

		$this->assertFalse($this->service->writePendingMetadata('u', 5));
		$this->assertEqualsCanonicalizing(['title', 'authors'], $book->getOverridesArray());
	}

	public function testResetOverridesDelegatesAndValidates(): void {
		$book = $this->book();
		$this->library->expects($this->once())->method('resetOverrides')->with('u', 5, 'title')->willReturn($book);
		$this->assertSame($book, $this->service->resetOverrides('u', 5, 'title'));
		$this->expectException(\OCA\EbookReader\Editor\InvalidEditRequestException::class);
		$this->service->resetOverrides('u', 5, 'rating');
	}

	public function testWritePendingMetadataDropsWhenFileIsGone(): void {
		$this->library->method('getFileForUser')->willThrowException(new \OCP\Files\NotFoundException(''));
		$this->assertFalse($this->service->writePendingMetadata('u', 5));
	}

	public function testBulkTagsReportsQueuedWrites(): void {
		$this->untouchableFile();
		$this->withBook($this->book(), [[Tag::TYPE_TAG, 'a']]);
		$this->books->method('update')->willReturnArgument(0);
		$res = $this->service->bulkTags('u', ['fileIds' => [5], 'addTags' => ['b']]);
		$this->assertSame(1, $res['updated']);
		$this->assertTrue($res['writeQueued']);
	}

	// ------------------------------------------------------------------ metadata target (sidecar)

	public function testSidecarTargetWritesTheSidecarAndNeverTouchesTheBookFile(): void {
		$this->target = 'sidecar';
		$file = $this->untouchableFile();
		$book = $this->book();
		$book->setOverridesArray(['title', 'publisher']);
		$this->withBook($book, [[Tag::TYPE_TAG, 'a']]);
		$this->books->expects($this->once())->method('update')->willReturnArgument(0);
		$written = null;
		$this->sidecar->expects($this->once())->method('write')->willReturnCallback(function (File $f, array $meta) use ($file, &$written): bool {
			$this->assertSame($file, $f);
			$written = $meta;
			return true;
		});
		$this->sidecar->method('etagOf')->willReturn('sc:1');
		$this->jobList->expects($this->never())->method('add');
		$file->expects($this->never())->method('putContent');
		$sources = [];
		$this->library->method('setTags')->willReturnCallback(function (int $id, string $type, array $names, string $source) use (&$sources): void {
			if ($names !== []) {
				$sources[$type . ':' . $source] = $names;
			}
		});

		$res = $this->service->saveMetadataOnly('u', 5, ['title' => 'New', 'tags' => ['a', 'b']]);
		$this->assertFalse($res['writeQueued']);
		$this->assertSame([], $res['warnings']);
		$this->assertSame('New', $written['title']);
		$this->assertSame(['a', 'b'], $written['tags']);
		$this->assertSame(['publisher'], $res['book']->getOverridesArray(), 'the sidecar holds the title now, no override needed');
		$this->assertSame('sc:1', $res['book']->getSidecarEtag());
		$this->assertSame(['a', 'b'], $sources['tag:' . Tag::SOURCE_FILE], 'tags follow the sidecar (source file semantics)');
	}

	public function testSidecarTargetWorksForFormatsThatCanNotBeWritten(): void {
		$this->target = 'sidecar';
		$this->untouchableFile('x.mobi');
		$this->withBook($this->book());
		$this->books->method('update')->willReturnArgument(0);
		$this->sidecar->expects($this->once())->method('write')->willReturn(true);
		$this->jobList->expects($this->never())->method('add');
		$res = $this->service->saveMetadataOnly('u', 5, ['title' => 'New', 'series' => 'S']);
		$this->assertSame([], $res['book']->getOverridesArray());
		$this->assertSame([], $res['warnings']);
	}

	public function testSidecarCanNotExpressEmptyValuesSoTheyStayOverrides(): void {
		$this->target = 'sidecar';
		$this->untouchableFile();
		$book = $this->book();
		$book->setSeries('Old series');
		$this->withBook($book);
		$this->books->method('update')->willReturnArgument(0);
		$this->sidecar->method('write')->willReturn(true);
		$res = $this->service->saveMetadataOnly('u', 5, ['series' => null, 'title' => 'New']);
		$this->assertSame(['series'], $res['book']->getOverridesArray());
	}

	public function testUnwritableSidecarFallsBackToLibraryWithWarning(): void {
		$this->target = 'sidecar';
		$this->untouchableFile();
		$book = $this->book();
		$this->withBook($book);
		$this->books->method('update')->willReturnArgument(0);
		$this->sidecar->expects($this->once())->method('write')->willReturn(false);
		$sources = [];
		$this->library->method('setTags')->willReturnCallback(function (int $id, string $type, array $names, string $source) use (&$sources): void {
			if ($names !== []) {
				$sources[$type . ':' . $source] = $names;
			}
		});
		$res = $this->service->saveMetadataOnly('u', 5, ['title' => 'New', 'tags' => ['x']]);
		$this->assertCount(1, $res['warnings']);
		$this->assertSame(['title'], $res['book']->getOverridesArray());
		$this->assertSame(['x'], $sources['tag:' . Tag::SOURCE_APP]);
		$this->assertFalse($res['writeQueued']);
	}

	public function testBothTargetWritesTheSidecarNowAndTheFileInTheBackground(): void {
		$this->target = 'both';
		$this->untouchableFile();
		$this->withBook($this->book());
		$this->books->method('update')->willReturnArgument(0);
		$this->sidecar->expects($this->once())->method('write')->willReturn(true);
		$this->jobList->expects($this->once())->method('add')->with(WriteMetadataJob::class, ['userId' => 'u', 'fileId' => 5]);
		$res = $this->service->saveMetadataOnly('u', 5, ['title' => 'New']);
		$this->assertTrue($res['writeQueued']);
	}

	public function testFileTargetOnlyRefreshesAnExistingSidecar(): void {
		$this->target = 'file';
		$this->untouchableFile();
		$this->withBook($this->book());
		$this->books->method('update')->willReturnArgument(0);
		$this->sidecar->expects($this->once())->method('write')->with($this->anything(), $this->anything(), false)->willReturn(true);
		$this->jobList->expects($this->once())->method('add');
		$this->service->saveMetadataOnly('u', 5, ['title' => 'New']);
	}

	public function testLibraryTargetLeavesTheSidecarAlone(): void {
		$this->target = 'library';
		$this->untouchableFile();
		$this->withBook($this->book());
		$this->books->method('update')->willReturnArgument(0);
		$this->sidecar->expects($this->never())->method('write');
		$this->service->saveMetadataOnly('u', 5, ['title' => 'New']);
	}

	public function testBulkTagsUseTheSidecarTarget(): void {
		$this->target = 'sidecar';
		$this->untouchableFile();
		$this->withBook($this->book(), [[Tag::TYPE_TAG, 'a']]);
		$this->books->method('update')->willReturnArgument(0);
		$this->sidecar->expects($this->once())->method('write')->willReturn(true);
		$this->jobList->expects($this->never())->method('add');
		$res = $this->service->bulkTags('u', ['fileIds' => [5], 'addTags' => ['b']]);
		$this->assertSame(1, $res['updated']);
		$this->assertFalse($res['writeQueued']);
	}

	public function testEditorSaveRefreshesTheSidecarBeforeReindexing(): void {
		$this->target = 'sidecar';
		$src = $this->tmp[] = Fixtures::cbz(2, true);
		$dst = $this->tmp[] = Fixtures::tmp('.cbz');
		$file = $this->localFile($src);
		$this->withBook($this->book());
		$this->tempManager->method('getTemporaryFile')->willReturn($dst);
		$this->metadata->method('extractLocal')->willReturn(new BookMetadata(title: 'X'));
		$this->books->method('update')->willReturnArgument(0);
		$order = [];
		$file->method('putContent')->willReturnCallback(function () use (&$order): void {
			$order[] = 'putContent';
		});
		$this->sidecar->expects($this->once())->method('write')->willReturnCallback(function (File $f, array $meta, bool $create) use (&$order): bool {
			$order[] = 'sidecar';
			$this->assertSame('Written', $meta['title']);
			$this->assertTrue($create);
			return true;
		});
		$this->library->method('reindexFileForAllUsers')->willReturnCallback(function () use (&$order): void {
			$order[] = 'reindex';
		});

		$this->service->save('u', 5, ['etag' => 'etag1', 'metadata' => ['title' => 'Written']]);
		$this->assertSame(['putContent', 'sidecar', 'reindex'], $order);
	}

	public function testRenameMovesTheSidecarWithTheBook(): void {
		$parent = $this->createMock(\OCP\Files\Folder::class);
		$parent->method('getPath')->willReturn('/u/files/Books');
		$parent->method('nodeExists')->willReturn(false);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('old.cbz');
		$file->method('getParent')->willReturn($parent);
		$file->method('isUpdateable')->willReturn(true);
		$file->method('isDeletable')->willReturn(true);
		$file->expects($this->once())->method('move')->with('/u/files/Books/New Name.cbz');
		$this->library->method('getFileForUser')->willReturn($file);
		$this->withBook($this->book());
		$this->renamer->method('sanitize')->willReturnArgument(0);
		$this->validator->method('sanitizeFilename')->willReturnArgument(0);
		$this->sidecar->expects($this->once())->method('moveAlong')->with($parent, 'old.cbz', $parent, 'New Name.cbz');
		$this->library->expects($this->once())->method('reindexFileForAllUsers')->with(5);
		$this->service->rename('u', 5, 'New Name', false);
	}

	// ------------------------------------------------------------------ embed

	public function testEmbedMetadataRejectsFormatsThatCanNotBeWritten(): void {
		$this->untouchableFile('x.mobi');
		$this->expectException(\OCA\EbookReader\Editor\EditorException::class);
		$this->expectExceptionCode(415);
		$this->service->checkEmbed('u', 5);
	}

	public function testEmbedMetadataRejectsReadOnlyFiles(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('x.epub');
		$file->method('isUpdateable')->willReturn(false);
		$this->library->method('getFileForUser')->willReturn($file);
		$this->expectException(\OCA\EbookReader\Editor\EditForbiddenException::class);
		$this->service->checkEmbed('u', 5);
	}

	public function testEmbedMetadataWritesLibraryValuesIntoTheFile(): void {
		$src = $this->tmp[] = Fixtures::cbz(2, true);
		$dst = $this->tmp[] = Fixtures::tmp('.cbz');
		$file = $this->localFile($src);
		$this->withBook($this->book('From library'));
		$this->tempManager->method('getTemporaryFile')->willReturn($dst);
		$this->metadata->method('extractLocal')->willReturnCallback(
			fn (string $path): BookMetadata => $path === $src ? new BookMetadata(title: 'Old in file', authors: ['A']) : new BookMetadata(title: 'From library'),
		);
		$this->books->method('update')->willReturnArgument(0);
		$file->expects($this->once())->method('putContent');
		$res = $this->service->embedMetadata('u', 5);
		$this->assertTrue($res['written']);
	}

	public function testEmbedMetadataIsANoopWhenTheFileAlreadyHoldsTheValues(): void {
		$src = $this->tmp[] = Fixtures::cbz(2, true);
		$file = $this->localFile($src);
		$this->withBook($this->book('Same'));
		$this->metadata->method('extractLocal')->willReturn(new BookMetadata(title: 'Same', authors: ['A']));
		$file->expects($this->never())->method('putContent');
		$this->assertFalse($this->service->embedMetadata('u', 5)['written']);
	}
}
