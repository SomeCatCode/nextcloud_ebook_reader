<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\BackgroundJob\MoveSidecarsJob;
use OCA\EbookReader\BackgroundJob\ScanFileJob;
use OCA\EbookReader\Listener\FileEventListener;
use OCA\EbookReader\Metadata\MetadataService;
use OCA\EbookReader\Metadata\SidecarChangedException;
use OCA\EbookReader\Metadata\SidecarService;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Sidecars in the "beside" and the "meta" layout, followed through the operations that move, rename, copy or delete a
 * book (Files app / WebDAV events, app rename, organise, convert with delete original), on an in-memory file tree.
 */
class SidecarMetaLayoutTest extends TestCase {
	private const BOOKS = '/u/files/Books';

	private MemoryFiles $fs;
	private string $location = 'beside';
	private SidecarService $sidecar;
	private LibraryService&MockObject $library;
	private IJobList&MockObject $jobList;
	/** @var list<string> paths of the books that were indexed */
	private array $indexed = [];
	/** @var list<array{string, mixed}> jobs queued through the job list */
	private array $jobs = [];

	protected function setUp(): void {
		$this->location = 'beside';
		$this->indexed = [];
		$this->jobs = [];
		$this->fs = new MemoryFiles($this);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('sidecarLocation')->willReturnCallback(fn (): string => $this->location);
		$this->sidecar = new SidecarService($this->createMock(LoggerInterface::class), $settings);

		$this->library = $this->createMock(LibraryService::class);
		$this->library->method('isInLibrary')->willReturn(true);
		$this->library->method('indexFile')->willReturnCallback(function (string $u, File $f): null {
			$this->indexed[] = $f->getPath();
			return null;
		});
		$metadata = $this->createMock(MetadataService::class);
		$metadata->method('detectFormat')->willReturnCallback(static fn (string $name): ?string => preg_match('/\.(epub|cbz)$/i', $name) === 1 ? 'x' : null);
		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('add')->willReturnCallback(function (string $class, $arg = null): void {
			$this->jobs[] = [$class, $arg];
		});
		$this->fs->listener = new FileEventListener($this->library, $metadata, $this->jobList, $this->createMock(LoggerInterface::class), $this->sidecar);
	}

	private static function xml(string $title = 'T'): string {
		return SidecarService::build(['title' => $title], 'uuid-1');
	}

	/** Puts a book and its sidecar into a folder in the given layout. */
	private function book(string $name, string $layout, string $dir = self::BOOKS, string $title = 'T'): void {
		$this->fs->addFile($dir . '/' . $name, 'book');
		if ($layout === 'meta') {
			$this->fs->addFile($dir . '/.meta/' . SidecarService::metaNameFor($name), self::xml($title));
		} elseif ($layout === 'beside') {
			$this->fs->addFile($dir . '/' . SidecarService::nameFor($name), self::xml($title));
		}
	}

	/** @return list<string> sorted paths of everything below $dir that belongs to sidecars (files and .meta folders) */
	private function sidecarsUnder(string $dir = '/u/files'): array {
		return array_values(array_filter($this->fs->paths($dir), static fn (string $p): bool => str_ends_with($p, '.opf') || basename($p) === '.meta'));
	}

	/** @return array<string, array{string}> */
	public static function layouts(): array {
		return ['beside' => ['beside'], 'meta' => ['meta']];
	}

	// ------------------------------------------------------------------ names

	public function testMetaNames(): void {
		$this->assertSame('Saga v01.cbz.opf', SidecarService::metaNameFor('Saga v01.cbz'));
		$this->assertSame('Saga v01.cbz', SidecarService::bookNameOfMeta('Saga v01.cbz.opf'));
		$this->assertSame('x.fb2.zip', SidecarService::bookNameOfMeta('x.fb2.zip.opf'));
		$this->assertNull(SidecarService::bookNameOfMeta('notes.txt.opf'));
		$this->assertNull(SidecarService::bookNameOfMeta('Saga v01.cbz'));
	}

	public function testBookOfResolvesBothLayouts(): void {
		$this->book('a.epub', 'meta');
		$this->book('b.epub', 'beside');
		$meta = SidecarService::bookOf($this->fs->file(self::BOOKS . '/.meta/a.epub.opf'));
		$this->assertSame([self::BOOKS, 'a.epub'], [$meta[0]->getPath(), $meta[1]]);
		$beside = SidecarService::bookOf($this->fs->file(self::BOOKS . '/.b.epub.opf'));
		$this->assertSame([self::BOOKS, 'b.epub'], [$beside[0]->getPath(), $beside[1]]);
		$this->assertNull(SidecarService::bookOf($this->fs->file(self::BOOKS . '/b.epub')));
		$this->assertNull(SidecarService::bookOf($this->fs->file(self::BOOKS . '/.meta/readme.txt')));
	}

	// ------------------------------------------------------------------ read / write

	#[DataProvider('layouts')]
	public function testReadFindsTheSidecarInEitherLayoutWhicheverIsConfigured(string $configured): void {
		$this->location = $configured;
		$this->book('a.epub', 'beside', title: 'Beside');
		$this->book('b.epub', 'meta', title: 'Meta');
		$this->assertSame('Beside', $this->sidecar->read($this->fs->file(self::BOOKS . '/a.epub'))?->metadata->title);
		$this->assertSame('Meta', $this->sidecar->read($this->fs->file(self::BOOKS . '/b.epub'))?->metadata->title);
		$this->assertNotNull($this->sidecar->etagOf($this->fs->file(self::BOOKS . '/b.epub')));
		$this->assertTrue($this->sidecar->exists($this->fs->file(self::BOOKS . '/b.epub')));
	}

	public function testTheConfiguredLayoutWinsWhenBothExist(): void {
		$this->book('a.epub', 'beside', title: 'Beside');
		$this->fs->addFile(self::BOOKS . '/.meta/a.epub.opf', self::xml('Meta'));
		$book = $this->fs->file(self::BOOKS . '/a.epub');
		$this->location = 'beside';
		$this->assertSame('Beside', $this->sidecar->read($book)?->metadata->title);
		$this->location = 'meta';
		$this->assertSame('Meta', $this->sidecar->read($book)?->metadata->title);
	}

	public function testWriteCreatesTheSidecarInTheMetaFolder(): void {
		$this->location = 'meta';
		$this->book('a.epub', 'none');
		$this->assertTrue($this->sidecar->write($this->fs->file(self::BOOKS . '/a.epub'), ['title' => 'Saga']));
		$this->assertSame([self::BOOKS . '/.meta', self::BOOKS . '/.meta/a.epub.opf'], $this->sidecarsUnder());
		$this->assertSame('Saga', SidecarService::parse((string)$this->fs->nodes[self::BOOKS . '/.meta/a.epub.opf'])?->metadata->title);
		$this->assertSame([], $this->indexed, 'our own writes are not events to react to');
		$this->assertSame([], $this->jobsAdded(), 'creating the .meta folder queues no scan');
	}

	public function testWriteCreatesBesideByDefault(): void {
		$this->book('a.epub', 'none');
		$this->assertTrue($this->sidecar->write($this->fs->file(self::BOOKS . '/a.epub'), ['title' => 'Saga']));
		$this->assertSame([self::BOOKS . '/.a.epub.opf'], $this->sidecarsUnder());
	}

	public function testWriteWithoutCreateLeavesNoMetaFolderBehind(): void {
		$this->location = 'meta';
		$this->book('a.epub', 'none');
		$this->assertTrue($this->sidecar->write($this->fs->file(self::BOOKS . '/a.epub'), ['title' => 'X'], false));
		$this->assertSame([], $this->sidecarsUnder());
	}

	public function testWriteMovesASidecarFromTheOtherLayoutInsteadOfDuplicatingIt(): void {
		$this->location = 'meta';
		$this->book('a.epub', 'beside');
		$this->assertTrue($this->sidecar->write($this->fs->file(self::BOOKS . '/a.epub'), ['title' => 'New']));
		$this->assertSame([self::BOOKS . '/.meta', self::BOOKS . '/.meta/a.epub.opf'], $this->sidecarsUnder());
		$parsed = SidecarService::parse((string)$this->fs->nodes[self::BOOKS . '/.meta/a.epub.opf']);
		$this->assertSame('New', $parsed?->metadata->title);
		$this->assertSame('uuid-1', $parsed->uuid, 'it is the same file: the identifier survives the move');
		$this->assertSame([], $this->indexed);

		// and back: the emptied .meta folder disappears
		$this->location = 'beside';
		$this->assertTrue($this->sidecar->write($this->fs->file(self::BOOKS . '/a.epub'), ['title' => 'Newer']));
		$this->assertSame([self::BOOKS . '/.a.epub.opf'], $this->sidecarsUnder());
		$this->assertSame('Newer', SidecarService::parse((string)$this->fs->nodes[self::BOOKS . '/.a.epub.opf'])?->metadata->title);
	}

	public function testUnchangedContentIsNotTouchedEvenInTheOtherLayout(): void {
		$this->location = 'meta';
		$this->book('a.epub', 'beside');
		$this->assertTrue($this->sidecar->write($this->fs->file(self::BOOKS . '/a.epub'), ['title' => 'T']));
		$this->assertSame([], $this->fs->log, 'no write, no move: the setting migration job moves it');
	}

	public function testVerifiedWriteStillRefusesAChangedSidecarInTheMetaFolder(): void {
		$this->location = 'meta';
		$this->book('a.epub', 'meta');
		$book = $this->fs->file(self::BOOKS . '/a.epub');
		$state = $this->sidecar->etagOf($book);
		$this->fs->nodes[self::BOOKS . '/.meta/a.epub.opf'] = self::xml('Somebody else');
		$this->expectException(SidecarChangedException::class);
		$this->sidecar->write($book, ['title' => 'Mine'], true, $state, true);
	}

	public function testVerifiedWriteChecksTheStateBeforeAMoveBetweenLayouts(): void {
		$this->location = 'meta';
		$this->book('a.epub', 'beside');
		$book = $this->fs->file(self::BOOKS . '/a.epub');
		$state = $this->sidecar->etagOf($book);
		$this->assertTrue($this->sidecar->write($book, ['title' => 'Mine'], true, $state, true));
		$this->assertSame([self::BOOKS . '/.meta', self::BOOKS . '/.meta/a.epub.opf'], $this->sidecarsUnder());
	}

	public function testReadOnlyFolderCanNotGetAMetaFolder(): void {
		$this->location = 'meta';
		$this->book('a.epub', 'none');
		$this->fs->readOnly[] = self::BOOKS;
		$this->assertFalse($this->sidecar->write($this->fs->file(self::BOOKS . '/a.epub'), ['title' => 'X']));
		$this->assertSame([], $this->sidecarsUnder());
	}

	// ------------------------------------------------------------------ audit: Files app / WebDAV events

	#[DataProvider('layouts')]
	public function testRenameInTheSameFolderRenamesTheSidecar(string $layout): void {
		$this->location = $layout;
		$this->book('Old.epub', $layout);
		$this->fs->file(self::BOOKS . '/Old.epub')->move(self::BOOKS . '/New.epub');
		$expected = $layout === 'meta' ? [self::BOOKS . '/.meta', self::BOOKS . '/.meta/New.epub.opf'] : [self::BOOKS . '/.New.epub.opf'];
		$this->assertSame($expected, $this->sidecarsUnder());
		$this->assertSame([self::BOOKS . '/New.epub'], $this->indexed, 'the renamed book is indexed once, with its sidecar in place');
	}

	#[DataProvider('layouts')]
	public function testMoveToAnotherFolderMovesTheSidecarAndRemovesTheEmptyMetaFolder(string $layout): void {
		$this->location = $layout;
		$this->book('a.epub', $layout);
		$this->fs->addFolder('/u/files/Sorted');
		$this->fs->file(self::BOOKS . '/a.epub')->move('/u/files/Sorted/a.epub');
		$expected = $layout === 'meta' ? ['/u/files/Sorted/.meta', '/u/files/Sorted/.meta/a.epub.opf'] : ['/u/files/Sorted/.a.epub.opf'];
		$this->assertSame($expected, $this->sidecarsUnder());
		$this->assertContains('/u/files/Sorted/a.epub', $this->indexed);
	}

	public function testMoveConvertsToTheConfiguredLayoutOfTheTarget(): void {
		$this->location = 'meta';
		$this->book('a.epub', 'beside');
		$this->fs->addFolder('/u/files/Sorted');
		$this->fs->file(self::BOOKS . '/a.epub')->move('/u/files/Sorted/a.epub');
		$this->assertSame(['/u/files/Sorted/.meta', '/u/files/Sorted/.meta/a.epub.opf'], $this->sidecarsUnder());
	}

	public function testMovingOneBookKeepsTheMetaFolderOfTheSourceWhileOthersRemain(): void {
		$this->location = 'meta';
		$this->book('a.epub', 'meta');
		$this->book('b.epub', 'meta');
		$this->fs->addFolder('/u/files/Sorted');
		$this->fs->file(self::BOOKS . '/a.epub')->move('/u/files/Sorted/a.epub');
		$this->assertSame([
			self::BOOKS . '/.meta', self::BOOKS . '/.meta/b.epub.opf',
			'/u/files/Sorted/.meta', '/u/files/Sorted/.meta/a.epub.opf',
		], $this->sidecarsUnder());
	}

	#[DataProvider('layouts')]
	public function testRenameKeepsAnExistingSidecarOfTheTargetName(string $layout): void {
		$this->location = $layout;
		$this->book('Old.epub', $layout, title: 'Old');
		// a stray sidecar of the new name (book gone): it is not overwritten, the old one stays too
		$stray = $layout === 'meta' ? self::BOOKS . '/.meta/New.epub.opf' : self::BOOKS . '/.New.epub.opf';
		$this->fs->addFile($stray, self::xml('Stray'));
		$this->fs->file(self::BOOKS . '/Old.epub')->move(self::BOOKS . '/New.epub');
		$this->assertSame('Stray', SidecarService::parse((string)$this->fs->nodes[$stray])?->metadata->title);
	}

	#[DataProvider('layouts')]
	public function testDeletingABookDeletesItsSidecarAndTheEmptyMetaFolder(string $layout): void {
		$this->location = $layout;
		$this->book('a.epub', $layout);
		$this->fs->file(self::BOOKS . '/a.epub')->delete();
		$this->assertSame([], $this->sidecarsUnder());
	}

	public function testDeletingABookKeepsTheMetaFolderWhileItHoldsOtherSidecars(): void {
		$this->location = 'meta';
		$this->book('a.epub', 'meta');
		$this->book('b.epub', 'meta');
		$this->fs->file(self::BOOKS . '/a.epub')->delete();
		$this->assertSame([self::BOOKS . '/.meta', self::BOOKS . '/.meta/b.epub.opf'], $this->sidecarsUnder());
	}

	public function testDeletingABookRemovesSidecarsOfBothLayouts(): void {
		$this->location = 'meta';
		$this->book('a.epub', 'beside');
		$this->fs->addFile(self::BOOKS . '/.meta/a.epub.opf', self::xml());
		$this->fs->file(self::BOOKS . '/a.epub')->delete();
		$this->assertSame([], $this->sidecarsUnder());
	}

	#[DataProvider('layouts')]
	public function testCopyingABookCopiesItsSidecar(string $layout): void {
		$this->location = $layout;
		$this->book('a.epub', $layout);
		$this->fs->file(self::BOOKS . '/a.epub')->copy(self::BOOKS . '/a copy.epub');
		$expected = $layout === 'meta'
			? [self::BOOKS . '/.meta', self::BOOKS . '/.meta/a copy.epub.opf', self::BOOKS . '/.meta/a.epub.opf']
			: [self::BOOKS . '/.a copy.epub.opf', self::BOOKS . '/.a.epub.opf'];
		$this->assertSame($expected, $this->sidecarsUnder());
		$this->assertContains(self::BOOKS . '/a copy.epub', $this->indexed, 'the copy is indexed again after it got its sidecar');
	}

	public function testCopyingABookIntoAnotherFolderCreatesTheMetaFolderThere(): void {
		$this->location = 'meta';
		$this->book('a.epub', 'meta');
		$this->fs->addFolder('/u/files/Other');
		$this->fs->file(self::BOOKS . '/a.epub')->copy('/u/files/Other/a.epub');
		$this->assertSame([
			self::BOOKS . '/.meta', self::BOOKS . '/.meta/a.epub.opf',
			'/u/files/Other/.meta', '/u/files/Other/.meta/a.epub.opf',
		], $this->sidecarsUnder());
	}

	public function testCopyingAFileThatIsNoBookDoesNothing(): void {
		$this->fs->addFile(self::BOOKS . '/notes.txt', 'n');
		$this->fs->file(self::BOOKS . '/notes.txt')->copy(self::BOOKS . '/notes2.txt');
		$this->assertSame([], $this->sidecarsUnder());
	}

	#[DataProvider('layouts')]
	public function testMovingAWholeFolderTakesTheSidecarsAlongUntouched(string $layout): void {
		$this->location = $layout;
		$this->book('a.epub', $layout, self::BOOKS . '/Saga');
		$this->book('b.epub', $layout, self::BOOKS . '/Saga');
		$this->fs->log = [];
		$this->fs->folder(self::BOOKS . '/Saga')->move(self::BOOKS . '/Renamed');
		$this->assertSame([self::BOOKS . '/Renamed'], array_map(static fn (string $l): string => substr($l, strpos($l, '-> ') + 3), $this->fs->log), 'only the folder itself is moved');
		$expected = $layout === 'meta'
			? [self::BOOKS . '/Renamed/.meta', self::BOOKS . '/Renamed/.meta/a.epub.opf', self::BOOKS . '/Renamed/.meta/b.epub.opf']
			: [self::BOOKS . '/Renamed/.a.epub.opf', self::BOOKS . '/Renamed/.b.epub.opf'];
		$this->assertSame($expected, $this->sidecarsUnder());
	}

	// ------------------------------------------------------------------ audit: the app's own paths

	#[DataProvider('layouts')]
	public function testAppRenameAndOrganizeAreNoOpsAfterTheEventMovedTheSidecar(string $layout): void {
		// RenameService/EditorService and OrganizeService move the file (the event moves the sidecar) and then call moveAlong again
		$this->location = $layout;
		$this->book('Old.epub', $layout);
		$parent = $this->fs->folder(self::BOOKS);
		$this->fs->file(self::BOOKS . '/Old.epub')->move(self::BOOKS . '/New.epub');
		$before = $this->sidecarsUnder();
		$logSize = count($this->fs->log);
		$this->assertFalse($this->sidecar->moveAlong($parent, 'Old.epub', $parent, 'New.epub'));
		$this->assertSame($before, $this->sidecarsUnder());
		$this->assertCount($logSize, $this->fs->log);
	}

	#[DataProvider('layouts')]
	public function testMoveAlongWorksWithoutTheEvent(string $layout): void {
		// the app moves a book on a path that raises no event (no listener attached): it moves the sidecar itself
		$this->fs->listener = null;
		$this->location = $layout;
		$this->book('Old.epub', $layout);
		$this->fs->addFolder('/u/files/Sorted');
		$from = $this->fs->folder(self::BOOKS);
		$to = $this->fs->folder('/u/files/Sorted');
		$this->assertTrue($this->sidecar->moveAlong($from, 'Old.epub', $to, 'New.epub'));
		$expected = $layout === 'meta' ? ['/u/files/Sorted/.meta', '/u/files/Sorted/.meta/New.epub.opf'] : ['/u/files/Sorted/.New.epub.opf'];
		$this->assertSame($expected, $this->sidecarsUnder());
	}

	#[DataProvider('layouts')]
	public function testConvertCopiesTheSidecarAndDeletingTheOriginalRemovesItsSidecar(string $layout): void {
		$this->location = $layout;
		$this->book('a.cbz', $layout);
		$parent = $this->fs->folder(self::BOOKS);
		// ConvertService: new file next to the original, copyAlong, index, then delete the original (+ deleteFor)
		$this->fs->addFile(self::BOOKS . '/a.cbt', 'converted');
		$this->assertTrue($this->sidecar->copyAlong($parent, 'a.cbz', $parent, 'a.cbt'));
		$this->fs->file(self::BOOKS . '/a.cbz')->delete();
		$this->sidecar->deleteFor($parent, 'a.cbz');
		$expected = $layout === 'meta' ? [self::BOOKS . '/.meta', self::BOOKS . '/.meta/a.cbt.opf'] : [self::BOOKS . '/.a.cbt.opf'];
		$this->assertSame($expected, $this->sidecarsUnder());
	}

	#[DataProvider('layouts')]
	public function testConvertWithoutDeletingTheOriginalKeepsBothSidecars(string $layout): void {
		$this->location = $layout;
		$this->book('a.cbz', $layout);
		$parent = $this->fs->folder(self::BOOKS);
		$this->fs->addFile(self::BOOKS . '/a.cbt', 'converted');
		$this->sidecar->copyAlong($parent, 'a.cbz', $parent, 'a.cbt');
		$this->assertCount($layout === 'meta' ? 3 : 2, $this->sidecarsUnder());
	}

	public function testDeleteFileForUserStyleDeletionAfterTheEventIsAHarmlessSecondCall(): void {
		$this->location = 'meta';
		$this->book('a.epub', 'meta');
		$parent = $this->fs->folder(self::BOOKS);
		$this->fs->file(self::BOOKS . '/a.epub')->delete();
		$this->assertFalse($this->sidecar->deleteFor($parent, 'a.epub'));
		$this->assertSame([], $this->sidecarsUnder());
	}

	// ------------------------------------------------------------------ events of sidecars and .meta folders

	public function testExternalEditOfAMetaSidecarReindexesItsBook(): void {
		$this->location = 'meta';
		$this->book('a.epub', 'meta');
		$sidecar = $this->fs->file(self::BOOKS . '/.meta/a.epub.opf');
		$sidecar->putContent(self::xml('Edited in the Files app'));
		$this->assertSame([self::BOOKS . '/a.epub'], $this->indexed);
	}

	public function testExternalCreateAndDeleteOfAMetaSidecarReindexTheBook(): void {
		$this->book('a.epub', 'none');
		$this->fs->folder(self::BOOKS)->newFolder('.meta')->newFile('a.epub.opf', self::xml());
		$this->assertSame([self::BOOKS . '/a.epub'], $this->indexed);
		$this->fs->file(self::BOOKS . '/.meta/a.epub.opf')->delete();
		$this->assertSame([self::BOOKS . '/a.epub', self::BOOKS . '/a.epub'], $this->indexed);
	}

	public function testExternalMoveOfAMetaSidecarReindexesBooksOfBothNames(): void {
		$this->location = 'meta';
		$this->book('a.epub', 'meta');
		$this->book('b.epub', 'none');
		$this->fs->file(self::BOOKS . '/.meta/a.epub.opf')->move(self::BOOKS . '/.meta/b.epub.opf');
		$this->assertEqualsCanonicalizing([self::BOOKS . '/a.epub', self::BOOKS . '/b.epub'], $this->indexed);
	}

	public function testTheMetaFolderItselfQueuesNoScanAndNeverCountsAsLibraryFolder(): void {
		$this->fs->addFolder(self::BOOKS);
		$this->fs->folder(self::BOOKS)->newFolder('.meta');
		$this->fs->folder(self::BOOKS . '/.meta')->delete();
		$this->assertSame([], $this->jobsAdded());
		$this->fs->folder(self::BOOKS)->newFolder('Real');
		$this->assertSame([ScanFileJob::class], $this->jobsAdded(), 'a normal folder still queues its scan');
	}

	/** @return list<string> class names of the jobs queued so far */
	private function jobsAdded(): array {
		return array_map(static fn (array $j): string => $j[0], $this->jobs);
	}

	// ------------------------------------------------------------------ the listing a scan reads

	public function testInListingPicksSidecarsOfBothLayouts(): void {
		$this->location = 'beside';
		$this->book('a.epub', 'beside');
		$this->book('b.epub', 'meta');
		$this->book('c.epub', 'beside');
		$this->fs->addFile(self::BOOKS . '/.meta/c.epub.opf', self::xml('Meta c'));
		$folder = $this->fs->folder(self::BOOKS);
		$map = $this->sidecar->inListing($folder, $folder->getDirectoryListing());
		$names = array_keys($map);
		sort($names);
		$this->assertSame(['a.epub', 'b.epub', 'c.epub'], $names);
		$this->assertSame(self::BOOKS . '/.c.epub.opf', $map['c.epub']->getPath(), 'beside is configured: it wins');
		$this->location = 'meta';
		$map = $this->sidecar->inListing($folder, $folder->getDirectoryListing());
		$this->assertSame(self::BOOKS . '/.meta/c.epub.opf', $map['c.epub']->getPath());
		$this->assertSame(self::BOOKS . '/.a.epub.opf', $map['a.epub']->getPath());
	}

	// ------------------------------------------------------------------ setting change: relocation

	public function testRelocateMovesToTheRequestedLayoutAndIsIdempotent(): void {
		$this->book('a.epub', 'beside');
		$book = $this->fs->file(self::BOOKS . '/a.epub');
		$this->assertTrue($this->sidecar->relocate($book, 'meta'));
		$this->assertSame([self::BOOKS . '/.meta', self::BOOKS . '/.meta/a.epub.opf'], $this->sidecarsUnder());
		$this->assertFalse($this->sidecar->relocate($book, 'meta'), 'already there');
		$this->assertTrue($this->sidecar->relocate($book, 'beside'));
		$this->assertSame([self::BOOKS . '/.a.epub.opf'], $this->sidecarsUnder());
		$this->assertSame([], $this->indexed, 'moving sidecars around is no reason to index');
	}

	public function testRelocateLeavesDuplicatesAlone(): void {
		$this->book('a.epub', 'beside');
		$this->fs->addFile(self::BOOKS . '/.meta/a.epub.opf', self::xml('Other'));
		$this->assertFalse($this->sidecar->relocate($this->fs->file(self::BOOKS . '/a.epub'), 'meta'));
		$this->assertCount(3, $this->sidecarsUnder());
	}

	public function testRelocateWithoutSidecarDoesNothing(): void {
		$this->book('a.epub', 'none');
		$this->assertFalse($this->sidecar->relocate($this->fs->file(self::BOOKS . '/a.epub'), 'meta'));
		$this->assertSame([], $this->fs->log);
	}

	public function testRelocateRespectsReadOnlySidecars(): void {
		$this->book('a.epub', 'beside');
		$this->fs->readOnly[] = self::BOOKS . '/.a.epub.opf';
		$this->assertFalse($this->sidecar->relocate($this->fs->file(self::BOOKS . '/a.epub'), 'meta'));
		$this->assertSame([self::BOOKS . '/.a.epub.opf'], $this->sidecarsUnder());
	}

	private function job(string $target): MoveSidecarsJob {
		$this->location = $target;
		$settings = $this->createMock(SettingsService::class);
		$settings->method('sidecarLocation')->willReturn($target);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);
		return new MoveSidecarsJob($this->createMock(ITimeFactory::class), $this->library, $settings, $this->sidecar, $users, $this->jobList, $this->createMock(LoggerInterface::class));
	}

	/** Makes the library walk report these books (with the state of their sidecar) like walkLibrary does. @param list<string> $paths */
	private function walk(array $paths): void {
		$library = $this->createMock(LibraryService::class);
		$library->method('walkLibrary')->willReturnCallback(function (string $u, callable $cb) use ($paths): bool {
			foreach ($paths as $p) {
				$file = $this->fs->file($p);
				$cb($file, 'x', $this->sidecar->etagOf($file));
			}
			return true;
		});
		$this->library = $library;
	}

	public function testTheJobMovesAllSidecarsToTheNewLayoutAndCanRunAgain(): void {
		$this->book('a.epub', 'beside');
		$this->book('b.epub', 'beside', self::BOOKS . '/Sub');
		$this->book('c.epub', 'none');
		$this->book('d.epub', 'meta');
		$paths = [self::BOOKS . '/a.epub', self::BOOKS . '/Sub/b.epub', self::BOOKS . '/c.epub', self::BOOKS . '/d.epub'];
		$this->walk($paths);
		$job = $this->job('meta');
		$this->assertSame(['moved' => 2, 'complete' => true], $job->moveAll('u', 60.0));
		$this->assertSame([
			self::BOOKS . '/.meta', self::BOOKS . '/.meta/a.epub.opf', self::BOOKS . '/.meta/d.epub.opf',
			self::BOOKS . '/Sub/.meta', self::BOOKS . '/Sub/.meta/b.epub.opf',
		], $this->sidecarsUnder());
		$this->assertSame(['moved' => 0, 'complete' => true], $job->moveAll('u', 60.0), 'idempotent');

		// back to "beside": every .meta folder is gone afterwards
		$this->assertSame(['moved' => 3, 'complete' => true], $this->job('beside')->moveAll('u', 60.0));
		$this->assertSame([self::BOOKS . '/.a.epub.opf', self::BOOKS . '/.d.epub.opf', self::BOOKS . '/Sub/.b.epub.opf'], $this->sidecarsUnder());
		$this->assertSame([], $this->indexed);
	}

	public function testTheJobSkipsSharedFiles(): void {
		$this->book('a.epub', 'beside');
		$shared = $this->createMock(\OCP\Files\Storage\ISharedStorage::class);
		$shared->method('instanceOfStorage')->willReturn(true);
		$file = $this->createMock(File::class);
		$file->method('getStorage')->willReturn($shared);
		$library = $this->createMock(LibraryService::class);
		$library->method('walkLibrary')->willReturnCallback(static function (string $u, callable $cb) use ($file): bool {
			$cb($file, 'x', 'etag:1');
			return true;
		});
		$this->library = $library;
		$sidecar = $this->createMock(SidecarService::class);
		$sidecar->expects($this->never())->method('relocate');
		$users = $this->createMock(IUserManager::class);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('sidecarLocation')->willReturn('meta');
		$job = new MoveSidecarsJob($this->createMock(ITimeFactory::class), $library, $settings, $sidecar, $users, $this->jobList, $this->createMock(LoggerInterface::class));
		$this->assertSame(['moved' => 0, 'complete' => true], $job->moveAll('u', 60.0));
	}

	public function testTheJobQueuesItselfWhenTheTimeBudgetIsUsedUp(): void {
		$this->book('a.epub', 'beside');
		$this->walk([self::BOOKS . '/a.epub']);
		$job = $this->job('meta');
		$this->assertSame(['moved' => 0, 'complete' => false], $job->moveAll('u', -1.0));
		$this->assertSame([[MoveSidecarsJob::class, ['userId' => 'u']]], $this->jobs);
		$this->assertSame([self::BOOKS . '/.a.epub.opf'], $this->sidecarsUnder(), 'nothing was moved, the next run does it');
	}

	public function testTheJobArgumentIsStableForDeduplication(): void {
		$this->assertSame(['userId' => 'u'], MoveSidecarsJob::argument('u'));
	}
}
