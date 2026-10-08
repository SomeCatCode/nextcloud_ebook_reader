<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\BackgroundJob\ScanFileJob;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
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
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountManager;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\ISharedStorage;
use OCP\Files\Storage\IStorage;
use OCP\IDBConnection;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/OcHooksEmitterStub.php';
require_once __DIR__ . '/DoctrineStubs.php';

/**
 * Books inside folders other users shared with the user (app shares and shares made in Files) belong to the library wherever
 * the share is mounted; the owner of such a file is stored with the book row (shared_owner).
 */
class LibraryIncomingFoldersTest extends TestCase {
	private IMountManager&MockObject $mounts;
	private IRootFolder&MockObject $root;
	private BookMapper&MockObject $books;
	private IJobList&MockObject $jobs;
	private MetadataService&MockObject $metadata;
	/** @var list<IMountPoint> */
	private array $mountList = [];
	/** @var array<string, Folder> mount point => folder node */
	private array $nodes = [];
	/** @var list<array{0: string, 1: array<string, mixed>}> */
	private array $queued = [];
	/** @var list<Book> */
	private array $stored = [];
	private LibraryService $service;

	protected function setUp(): void {
		$this->mounts = $this->createMock(IMountManager::class);
		$this->mounts->method('findIn')->willReturnCallback(fn (): array => $this->mountList);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->willReturnCallback(static fn (string $p): ?string => str_starts_with($p, '/bob/files') ? substr($p, strlen('/bob/files')) : null);
		$userFolder->method('get')->willThrowException(new NotFoundException());
		$this->root = $this->createMock(IRootFolder::class);
		$this->root->method('getUserFolder')->willReturn($userFolder);
		$this->root->method('get')->willReturnCallback(fn (string $p): Folder => $this->nodes[$p] ?? throw new NotFoundException());

		$this->books = $this->createMock(BookMapper::class);
		$this->books->method('findAllByUser')->willReturnCallback(fn (): array => $this->stored);
		$this->books->method('findByUserAndFile')->willThrowException(new DoesNotExistException(''));
		$this->books->method('insert')->willReturnCallback(function (Book $b): Book {
			$b->setId(count($this->stored) + 1);
			$this->stored[] = $b;
			return $b;
		});
		$this->metadata = $this->createMock(MetadataService::class);
		$this->metadata->method('detectFormat')->willReturn('epub');
		$this->metadata->method('extract')->willReturn(new BookMetadata(title: 'T'));
		$this->jobs = $this->createMock(IJobList::class);
		$this->jobs->method('add')->willReturnCallback(function (string $class, mixed $arg): void {
			$this->queued[] = [$class, (array)$arg];
		});
		$settings = $this->createMock(SettingsService::class);
		$settings->method('get')->willReturn(['libraryFolders' => ['/Books'], 'reader' => [], 'filenamePattern' => '', 'genreList' => []]);
		$tags = $this->createMock(TagMapper::class);

		$this->service = new LibraryService(
			$this->books,
			$tags,
			$this->metadata,
			$this->createMock(CoverService::class),
			new GenreClassifier($settings, $tags),
			$settings,
			$this->root,
			$this->jobs,
			$this->db(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SidecarService::class),
			null,
			$this->mounts,
		);
	}

	private function db(): IDBConnection {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([]);
		$result->method('fetchOne')->willReturn(false);
		$result->method('fetch')->willReturn(false);
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'gt', 'isNull'] as $m) {
			$expr->method($m)->willReturn('1 = 1');
		}
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($result, $expr): IQueryBuilder {
			$qb = $this->createMock(IQueryBuilder::class);
			foreach (['select', 'selectDistinct', 'selectAlias', 'from', 'where', 'andWhere', 'innerJoin', 'groupBy', 'setMaxResults'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn(':p');
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		});
		return $db;
	}

	private function sharedStorage(string $nodeType = 'folder', string $owner = 'alice'): ISharedStorage&MockObject {
		$share = $this->createMock(IShare::class);
		$share->method('getShareOwner')->willReturn($owner);
		$share->method('getNodeType')->willReturn($nodeType);
		$storage = $this->createMock(ISharedStorage::class);
		$storage->method('instanceOfStorage')->willReturnCallback(static fn (string $c): bool => $c === ISharedStorage::class || $c === IStorage::class);
		$storage->method('getShare')->willReturn($share);
		return $storage;
	}

	private function mount(string $mountPoint, bool $shared): IMountPoint {
		$mount = $this->createMock(IMountPoint::class);
		$mount->method('getMountPoint')->willReturn($mountPoint);
		if ($shared) {
			$mount->method('getStorage')->willReturn($this->sharedStorage());
		} else {
			$storage = $this->createMock(IStorage::class);
			$storage->method('instanceOfStorage')->willReturn(false);
			$mount->method('getStorage')->willReturn($storage);
		}
		return $mount;
	}

	private function book(int $id, ?int $size = 10, bool $shared = true): File&MockObject {
		$f = $this->createMock(File::class);
		$f->method('getId')->willReturn($id);
		$f->method('getName')->willReturn('b' . $id . '.epub');
		$f->method('getPath')->willReturn('/bob/files/Inbox/Saga/b' . $id . '.epub');
		$f->method('getMimeType')->willReturn('application/epub+zip');
		$f->method('getMTime')->willReturn(100);
		$f->method('getEtag')->willReturn('e' . $id);
		$f->method('getSize')->willReturn($size);
		if ($shared) {
			$f->method('getStorage')->willReturn($this->sharedStorage());
		}
		return $f;
	}

	/** @param list<File> $children */
	private function folderNode(array $children): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn($children);
		return $folder;
	}

	/** @return list<int> */
	private function walked(?bool &$complete = null): array {
		$seen = [];
		$complete = $this->service->walkLibrary('bob', static function (File $f) use (&$seen): void {
			$seen[] = $f->getId();
		});
		return $seen;
	}

	public function testWalkVisitsBooksOfIncomingFolderSharesWhereverTheyAreMounted(): void {
		$this->mountList = [
			$this->mount('/bob/files/Inbox/Saga/', true),
			$this->mount('/bob/files/Somewhere/Else/', true),
		];
		$this->nodes['/bob/files/Inbox/Saga'] = $this->folderNode([$this->book(11), $this->book(12)]);
		$this->nodes['/bob/files/Somewhere/Else'] = $this->folderNode([$this->book(13)]);
		$this->assertSame([11, 12, 13], $this->walked($complete));
		$this->assertTrue($complete);
	}

	public function testOnlyShareMountsAreWalkedNotOtherMounts(): void {
		$this->mountList = [$this->mount('/bob/files/External/', false), $this->mount('/bob/files/Inbox/Saga/', true)];
		$this->nodes['/bob/files/External'] = $this->folderNode([$this->book(20)]);
		$this->nodes['/bob/files/Inbox/Saga'] = $this->folderNode([$this->book(11)]);
		$this->assertSame([11], $this->walked());
	}

	public function testShareMountedInsideALibraryFolderIsLeftToTheLibraryWalk(): void {
		// /Books/Saga is inside the library folder: the library walk covers it, so no duplicate visit here
		$this->mountList = [$this->mount('/bob/files/Books/Saga/', true)];
		$this->nodes['/bob/files/Books/Saga'] = $this->folderNode([$this->book(11)]);
		$this->assertSame([], $this->walked());
	}

	public function testSingleFileSharesAreNoFolderRoots(): void {
		$this->mountList = [$this->mount('/bob/files/one.epub', true)];
		$this->nodes['/bob/files/one.epub'] = $this->createMock(File::class);
		$this->assertSame([], $this->walked());
	}

	public function testBooksAreNotVisitedTwice(): void {
		$this->mountList = [$this->mount('/bob/files/A/', true), $this->mount('/bob/files/B/', true)];
		$this->nodes['/bob/files/A'] = $this->folderNode([$this->book(11)]);
		$this->nodes['/bob/files/B'] = $this->folderNode([$this->book(11), $this->book(12)]);
		$this->assertSame([11, 12], $this->walked());
	}

	public function testUnlistableMountsMakeTheWalkIncompleteSoNothingIsTombstoned(): void {
		$mounts = $this->createMock(IMountManager::class);
		$mounts->method('findIn')->willThrowException(new \RuntimeException('boom'));
		$r = new \ReflectionProperty(LibraryService::class, 'mounts');
		$r->setValue($this->service, $mounts);
		$this->assertSame([], $this->walked($complete));
		$this->assertFalse($complete);
	}

	public function testFilesInsideAFolderShareBelongToTheLibrary(): void {
		$inside = $this->book(11);
		$this->assertTrue($this->service->isInLibrary('bob', $inside));
	}

	public function testFilesOfASingleFileShareOrOwnFilesOutsideTheLibraryDoNot(): void {
		$single = $this->createMock(File::class);
		$single->method('getId')->willReturn(30);
		$single->method('getPath')->willReturn('/bob/files/one.epub');
		$single->method('getStorage')->willReturn($this->sharedStorage('file'));
		$this->assertFalse($this->service->isInLibrary('bob', $single));
		$own = $this->book(31, 10, false);
		$this->assertFalse($this->service->isInLibrary('bob', $own));
	}

	public function testIndexFileStoresTheOwnerOfASharedFile(): void {
		$book = $this->service->indexFile('bob', $this->book(11));
		$this->assertNotNull($book);
		$this->assertSame('alice', $book->getSharedOwner());
	}

	public function testIndexFileLeavesOwnFilesWithoutSharedOwner(): void {
		$book = $this->service->indexFile('bob', $this->book(11, 10, false));
		$this->assertNotNull($book);
		$this->assertNull($book->getSharedOwner());
	}

	public function testIndexIncomingFoldersIndexesOnlyMissingBooksWithinTheBudget(): void {
		$this->mountList = [$this->mount('/bob/files/Inbox/Saga/', true)];
		$have = new Book();
		$have->setFileId(11);
		$this->stored = [$have];
		$this->nodes['/bob/files/Inbox/Saga'] = $this->folderNode([
			$this->book(11),
			$this->book(12),
			$this->book(13),
			$this->book(14, LibraryService::INTERACTIVE_MAX_BYTES + 1),
		]);
		$result = $this->service->indexIncomingFolders('bob', 1, 60.0);
		// 11 exists; 12 is indexed now; 13 (over the inline count) and 14 (too large) are queued
		$this->assertSame(['indexed' => 1, 'pending' => 2], $result);
		$queued = array_map(static fn (array $q): int => (int)$q[1]['fileId'], array_filter($this->queued, static fn (array $q): bool => $q[0] === ScanFileJob::class && $q[1]['userId'] === 'bob'));
		$this->assertSame([13, 14], array_values($queued));
		$this->assertCount(2, $this->stored);
		$this->assertSame('alice', $this->stored[1]->getSharedOwner());
	}

	public function testIndexIncomingFoldersWithoutShareMountsDoesNothing(): void {
		$this->assertSame(['indexed' => 0, 'pending' => 0], $this->service->indexIncomingFolders('bob', 25, 5.0));
	}

	public function testSharedOutIgnoresIncomingBooksAndFindsBooksBelowSharedFolders(): void {
		$cache = new \ReflectionProperty(LibraryService::class, 'sharedFolderCache');
		$cache->setValue($this->service, ['bob' => [7 => '/Books/Saga']]);
		$inFolder = new Book();
		$inFolder->setFileId(1);
		$inFolder->setPath('/Books/Saga/v1.cbz');
		$sibling = new Book();
		$sibling->setFileId(2);
		$sibling->setPath('/Books/Saga2/v1.cbz');
		$incoming = new Book();
		$incoming->setFileId(3);
		$incoming->setPath('/Books/Saga/v3.cbz');
		$incoming->setSharedOwner('alice');
		$out = $this->service->sharedOutFileIds('bob', [$inFolder, $sibling, $incoming]);
		$this->assertSame([1 => true], $out);
	}

	public function testSharedOutOfAnEmptyPageNeedsNoQuery(): void {
		$this->assertSame([], $this->service->sharedOutFileIds('bob', []));
	}
}
