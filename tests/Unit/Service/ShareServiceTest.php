<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\BackgroundJob\ScanFileJob;
use OCA\EbookReader\BackgroundJob\SyncShelfShareJob;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\FileShare;
use OCA\EbookReader\Db\FileShareMapper;
use OCA\EbookReader\Db\FolderShare;
use OCA\EbookReader\Db\FolderShareMapper;
use OCA\EbookReader\Db\SeriesShare;
use OCA\EbookReader\Db\SeriesShareMapper;
use OCA\EbookReader\Db\Shelf;
use OCA\EbookReader\Db\ShelfBookMapper;
use OCA\EbookReader\Db\ShelfMapper;
use OCA\EbookReader\Db\ShelfShare;
use OCA\EbookReader\Db\ShelfShareMapper;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\ShareException;
use OCA\EbookReader\Service\ShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IUserManager;
use OCP\Share\Exceptions\GenericShareException;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * ShareService against in-memory fakes of the mappers and the Nextcloud share manager.
 */
class ShareServiceTest extends TestCase {
	private IManager&MockObject $manager;
	private LibraryService&MockObject $library;
	private IJobList&MockObject $jobs;
	private ShareService $service;

	/** @var array<string, array{with: string, by: string, fileId: int}> Nextcloud shares by full id */
	private array $ncShares = [];
	private int $nextNcId = 100;
	/** @var array<int, FileShare> */
	private array $rows = [];
	/** @var array<int, ShelfShare> */
	private array $shelfShareStore = [];
	/** @var array<int, Shelf> */
	private array $shelfStore = [];
	/** @var array<int, list<int>> shelf id => file ids */
	private array $assigned = [];
	/** @var array<string, list<int>> user => file ids in their library */
	private array $library_ = ['alice' => [1, 2, 3, 4], 'bob' => []];
	/** @var array<int, int> file id => permissions of alice's node */
	private array $perms = [];
	/** @var list<array{0: string, 1: array<string, mixed>}> queued jobs */
	private array $queued = [];
	/** @var array<string, list<int>> book rows per user when they differ from the reachable files */
	private array $bookRows = [];
	/** @var array<int, SeriesShare> */
	private array $seriesShareStore = [];
	/** @var array<int, FolderShare> */
	private array $folderShareStore = [];
	/** @var array<int, string> file id => series name of alice's books */
	private array $seriesOf = [1 => 'Saga', 2 => 'saga', 3 => 'Saga', 4 => 'Other'];
	/** @var array<string, int> alice's folders: path => folder (file) id */
	private array $folders = ['/Books/Saga' => 500, '/Books' => 501, '/Music' => 502];
	/** @var array<int, int> folder id => permissions */
	private array $folderPerms = [];
	private bool $sharingEnabled = true;
	/** @var list<array{0: string, 1: list<int>|string, 2: int}> touched owner books: [owner, file ids or folder path, updated_at] */
	private array $touched = [];
	private int $nextId = 1;

	protected function setUp(): void {
		$this->manager = $this->createMock(IManager::class);
		$this->manager->method('shareApiEnabled')->willReturnCallback(fn (): bool => $this->sharingEnabled);
		$this->manager->method('sharingDisabledForUser')->willReturn(false);
		$this->manager->method('newShare')->willReturnCallback(fn (): IShare => $this->newShareMock());
		$this->manager->method('createShare')->willReturnCallback(function (IShare $share): IShare {
			$id = 'ocinternal:' . $this->nextNcId++;
			/** @var IShare&MockObject $share */
			$this->ncShares[$id] = ['with' => (string)$share->getSharedWith(), 'by' => (string)$share->getSharedBy(), 'fileId' => $share->getNode()->getId()];
			$created = $this->createMock(IShare::class);
			$created->method('getFullId')->willReturn($id);
			return $created;
		});
		$this->manager->method('getShareById')->willReturnCallback(function (string $id): IShare {
			if (!isset($this->ncShares[$id])) {
				throw new ShareNotFound();
			}
			$share = $this->createMock(IShare::class);
			$share->method('getFullId')->willReturn($id);
			return $share;
		});
		$this->manager->method('deleteShare')->willReturnCallback(function (IShare $share): void {
			unset($this->ncShares[$share->getFullId()]);
		});
		$this->manager->method('getSharesBy')->willReturn([]);

		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $u): bool => in_array($u, ['alice', 'bob', 'carol'], true));
		$users->method('getDisplayName')->willReturnCallback(static fn (string $u): ?string => ucfirst($u));

		$this->library = $this->createMock(LibraryService::class);
		$this->library->method('getFileForUser')->willReturnCallback(function (string $u, int $id): File {
			if (!in_array($id, $this->library_[$u] ?? [], true)) {
				throw new NotFoundException();
			}
			$file = $this->createMock(File::class);
			$file->method('getId')->willReturn($id);
			$perm = $this->perms[$id] ?? Constants::PERMISSION_ALL;
			$file->method('getPermissions')->willReturn($perm);
			$file->method('isShareable')->willReturn(($perm & Constants::PERMISSION_SHARE) !== 0);
			return $file;
		});
		$this->library->method('canReadContent')->willReturn(true);
		$this->library->method('isPathInLibrary')->willReturnCallback(static fn (string $u, string $p): bool => str_starts_with($p, '/Books'));
		$this->library->method('getFolderForUser')->willReturnCallback(function (string $u, string $path): Folder {
			if ($u !== 'alice' || !isset($this->folders[$path])) {
				throw new NotFoundException();
			}
			$id = $this->folders[$path];
			$folder = $this->createMock(Folder::class);
			$folder->method('getId')->willReturn($id);
			$perm = $this->folderPerms[$id] ?? Constants::PERMISSION_ALL;
			$folder->method('getPermissions')->willReturn($perm);
			$folder->method('isShareable')->willReturn(($perm & Constants::PERMISSION_SHARE) !== 0);
			return $folder;
		});
		$this->library->method('sharedFolderPaths')->willReturnCallback(function (string $owner): array {
			$out = [];
			foreach ($this->folderShareStore as $row) {
				$path = array_search($row->getFolderId(), $this->folders, true);
				if ($row->getOwnerId() === $owner && $path !== false) {
					$out[$row->getFolderId()] = $path;
				}
			}
			return $out;
		});
		$this->library->method('countBooksBelow')->willReturn(3);
		$this->library->method('findFileIdsBelow')->willReturn([1, 2]);

		$books = $this->createMock(BookMapper::class);
		$books->method('findByUserAndFile')->willReturnCallback(function (string $u, int $id): Book {
			if (!in_array($id, $this->library_[$u] ?? [], true)) {
				throw new DoesNotExistException('');
			}
			return $this->book($id);
		});
		$books->method('touch')->willReturnCallback(function (string $u, array $ids, int $now): int {
			$this->touched[] = [$u, $ids, $now];
			return count($ids);
		});
		$books->method('touchBelow')->willReturnCallback(function (string $u, string $path, int $now): int {
			$this->touched[] = [$u, $path, $now];
			return 1;
		});
		$books->method('findBySeries')->willReturnCallback(function (string $u, array $series): array {
			$out = [];
			foreach ($this->library_[$u] ?? [] as $id) {
				foreach ($series as $name) {
					if (isset($this->seriesOf[$id]) && mb_strtolower($this->seriesOf[$id]) === mb_strtolower($name)) {
						$out[] = $this->book($id);
					}
				}
			}
			return $out;
		});
		$books->method('findByUserAndFiles')->willReturnCallback(fn (string $u, array $ids): array => array_values(array_map(
			fn (int $id): Book => $this->book($id),
			array_values(array_intersect($ids, $this->bookRows[$u] ?? $this->library_[$u] ?? [])),
		)));

		$shelves = $this->createMock(ShelfMapper::class);
		$shelves->method('findByUserAndId')->willReturnCallback(function (string $u, int $id): Shelf {
			if (isset($this->shelfStore[$id]) && $this->shelfStore[$id]->getUserId() === $u) {
				return $this->shelfStore[$id];
			}
			throw new DoesNotExistException('');
		});
		$shelves->method('findByUser')->willReturnCallback(fn (string $u): array => array_values(array_filter($this->shelfStore, static fn (Shelf $s): bool => $s->getUserId() === $u)));

		$shelfBooks = $this->createMock(ShelfBookMapper::class);
		$shelfBooks->method('findFileIds')->willReturnCallback(fn (int $id): array => $this->assigned[$id] ?? []);

		$this->service = new ShareService(
			$this->manager,
			$users,
			$this->library,
			$books,
			$shelves,
			$shelfBooks,
			$this->shelfShareMapper(),
			$this->fileShareMapper(),
			$this->jobs(),
			$this->time(),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->seriesShareMapper(),
			$this->folderShareMapper(),
		);
	}

	private function book(int $id): Book {
		$b = new Book();
		$b->setFileId($id);
		$b->setTitle('Book ' . $id);
		$b->setPath('/Books/' . $id . '.epub');
		$b->setSeries($this->seriesOf[$id] ?? null);
		return $b;
	}

	private function newShareMock(): IShare {
		$data = [];
		$share = $this->createMock(IShare::class);
		foreach (['setNode' => 'node', 'setShareType' => 'type', 'setSharedWith' => 'with', 'setSharedBy' => 'by', 'setPermissions' => 'perm', 'setMailSend' => 'mail'] as $setter => $key) {
			$share->method($setter)->willReturnCallback(function (mixed $v) use (&$data, $key, $share): IShare {
				$data[$key] = $v;
				return $share;
			});
		}
		$share->method('getNode')->willReturnCallback(function () use (&$data): Node {
			return $data['node'];
		});
		$share->method('getSharedWith')->willReturnCallback(function () use (&$data): string {
			return $data['with'];
		});
		$share->method('getSharedBy')->willReturnCallback(function () use (&$data): string {
			return $data['by'];
		});
		$share->method('getPermissions')->willReturnCallback(function () use (&$data): int {
			return $data['perm'];
		});
		$share->method('getMailSend')->willReturnCallback(function () use (&$data): bool {
			return $data['mail'];
		});
		$this->lastShare = $share;
		return $share;
	}

	private ?IShare $lastShare = null;

	private function time(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new \DateTimeImmutable('@1800000000'));
		return $time;
	}

	private function jobs(): IJobList {
		$this->jobs = $this->createMock(IJobList::class);
		$this->jobs->method('add')->willReturnCallback(function (string $class, mixed $arg): void {
			$this->queued[] = [$class, (array)$arg];
		});
		return $this->jobs;
	}

	private function fileShareMapper(): FileShareMapper {
		$m = $this->createMock(FileShareMapper::class);
		$m->method('insert')->willReturnCallback(function (FileShare $r): FileShare {
			$r->setId($this->nextId++);
			$this->rows[$r->getId()] = $r;
			return $r;
		});
		$m->method('delete')->willReturnCallback(function (FileShare $r): FileShare {
			unset($this->rows[$r->getId()]);
			return $r;
		});
		$filter = fn (callable $fn): array => array_values(array_filter($this->rows, $fn));
		$m->method('findByTriple')->willReturnCallback(fn (string $o, string $r, int $f): array => $filter(static fn (FileShare $x): bool => $x->getOwnerId() === $o && $x->getRecipientId() === $r && $x->getFileId() === $f));
		$m->method('findByShelfShare')->willReturnCallback(fn (int $id): array => $filter(static fn (FileShare $x): bool => $x->getShelfShareId() === $id));
		$m->method('findByShareId')->willReturnCallback(fn (string $id): array => $filter(static fn (FileShare $x): bool => $x->getShareId() === $id));
		$m->method('findDirectByOwner')->willReturnCallback(fn (string $o): array => $filter(static fn (FileShare $x): bool => $x->getOwnerId() === $o && $x->isDirect()));
		$m->method('findDirectByRecipient')->willReturnCallback(fn (string $r): array => $filter(static fn (FileShare $x): bool => $x->getRecipientId() === $r && $x->isDirect()));
		$m->method('findByUser')->willReturnCallback(fn (string $u): array => $filter(static fn (FileShare $x): bool => $x->getOwnerId() === $u || $x->getRecipientId() === $u));
		$m->method('findIncomingFileIds')->willReturnCallback(fn (string $r): array => array_values(array_unique(array_map(static fn (FileShare $x): int => $x->getFileId(), $filter(static fn (FileShare $x): bool => $x->getRecipientId() === $r)))));
		$m->method('countsByShelfShares')->willReturnCallback(function (array $ids): array {
			$out = [];
			foreach ($this->rows as $r) {
				if (in_array($r->getShelfShareId(), $ids, true)) {
					$out[$r->getShelfShareId()] = ($out[$r->getShelfShareId()] ?? 0) + 1;
				}
			}
			return $out;
		});
		$m->method('detachShare')->willReturnCallback(function (string $id): void {
			foreach ($this->rows as $r) {
				if ($r->getShareId() === $id) {
					$r->setShareId(null);
				}
			}
		});
		$m->method('deleteByShelfShare')->willReturnCallback(function (int $id): void {
			foreach ($this->rows as $k => $r) {
				if ($r->getShelfShareId() === $id) {
					unset($this->rows[$k]);
				}
			}
		});
		return $m;
	}

	private function shelfShareMapper(): ShelfShareMapper {
		$m = $this->createMock(ShelfShareMapper::class);
		$m->method('insert')->willReturnCallback(function (ShelfShare $s): ShelfShare {
			$s->setId($this->nextId++);
			$this->shelfShareStore[$s->getId()] = $s;
			return $s;
		});
		$m->method('update')->willReturnArgument(0);
		$m->method('delete')->willReturnCallback(function (ShelfShare $s): ShelfShare {
			unset($this->shelfShareStore[$s->getId()]);
			return $s;
		});
		$m->method('findByShelfAndRecipient')->willReturnCallback(function (int $shelfId, string $r): ShelfShare {
			foreach ($this->shelfShareStore as $s) {
				if ($s->getShelfId() === $shelfId && $s->getRecipientId() === $r) {
					return $s;
				}
			}
			throw new DoesNotExistException('');
		});
		$m->method('findById')->willReturnCallback(function (int $id): ShelfShare {
			return $this->shelfShareStore[$id] ?? throw new DoesNotExistException('');
		});
		$filter = fn (callable $fn): array => array_values(array_filter($this->shelfShareStore, $fn));
		$m->method('findByShelf')->willReturnCallback(fn (int $id): array => $filter(static fn (ShelfShare $s): bool => $s->getShelfId() === $id));
		$m->method('findByOwner')->willReturnCallback(fn (string $o): array => $filter(static fn (ShelfShare $s): bool => $s->getOwnerId() === $o));
		$m->method('findByRecipient')->willReturnCallback(fn (string $r): array => $filter(static fn (ShelfShare $s): bool => $s->getRecipientId() === $r));
		$m->method('findPage')->willReturnCallback(fn (int $after, int $limit): array => array_slice($filter(static fn (ShelfShare $s): bool => $s->getId() > $after), 0, $limit));
		return $m;
	}

	private function seriesShareMapper(): SeriesShareMapper {
		$m = $this->createMock(SeriesShareMapper::class);
		$m->method('insert')->willReturnCallback(function (SeriesShare $s): SeriesShare {
			$s->setId($this->nextId++);
			$this->seriesShareStore[$s->getId()] = $s;
			return $s;
		});
		$m->method('update')->willReturnArgument(0);
		$m->method('delete')->willReturnCallback(function (SeriesShare $s): SeriesShare {
			unset($this->seriesShareStore[$s->getId()]);
			return $s;
		});
		$m->method('findByOwnerSeriesAndRecipient')->willReturnCallback(function (string $o, string $key, string $r): SeriesShare {
			foreach ($this->seriesShareStore as $s) {
				if ($s->getOwnerId() === $o && $s->getSeriesKey() === $key && $s->getRecipientId() === $r) {
					return $s;
				}
			}
			throw new DoesNotExistException('');
		});
		$m->method('findById')->willReturnCallback(fn (int $id): SeriesShare => $this->seriesShareStore[$id] ?? throw new DoesNotExistException(''));
		$filter = fn (callable $fn): array => array_values(array_filter($this->seriesShareStore, $fn));
		$m->method('findByOwnerAndSeries')->willReturnCallback(fn (string $o, string $key): array => $filter(static fn (SeriesShare $s): bool => $s->getOwnerId() === $o && $s->getSeriesKey() === $key));
		$m->method('findByOwner')->willReturnCallback(fn (string $o): array => $filter(static fn (SeriesShare $s): bool => $s->getOwnerId() === $o));
		$m->method('findByRecipient')->willReturnCallback(fn (string $r): array => $filter(static fn (SeriesShare $s): bool => $s->getRecipientId() === $r));
		$m->method('findPage')->willReturnCallback(fn (int $after, int $limit): array => array_slice($filter(static fn (SeriesShare $s): bool => $s->getId() > $after), 0, $limit));
		$m->method('countsByOwner')->willReturnCallback(function (string $o): array {
			$out = [];
			foreach ($this->seriesShareStore as $s) {
				if ($s->getOwnerId() === $o) {
					$out[$s->getSeriesKey()] = ($out[$s->getSeriesKey()] ?? 0) + 1;
				}
			}
			return $out;
		});
		return $m;
	}

	private function folderShareMapper(): FolderShareMapper {
		$m = $this->createMock(FolderShareMapper::class);
		$m->method('insert')->willReturnCallback(function (FolderShare $s): FolderShare {
			$s->setId($this->nextId++);
			$this->folderShareStore[$s->getId()] = $s;
			return $s;
		});
		$m->method('delete')->willReturnCallback(function (FolderShare $s): FolderShare {
			unset($this->folderShareStore[$s->getId()]);
			return $s;
		});
		$filter = fn (callable $fn): array => array_values(array_filter($this->folderShareStore, $fn));
		$m->method('findByTriple')->willReturnCallback(function (string $o, int $folderId, string $r): FolderShare {
			foreach ($this->folderShareStore as $s) {
				if ($s->getOwnerId() === $o && $s->getFolderId() === $folderId && $s->getRecipientId() === $r) {
					return $s;
				}
			}
			throw new DoesNotExistException('');
		});
		$m->method('findByFolder')->willReturnCallback(fn (string $o, int $id): array => $filter(static fn (FolderShare $s): bool => $s->getOwnerId() === $o && $s->getFolderId() === $id));
		$m->method('findByOwner')->willReturnCallback(fn (string $o): array => $filter(static fn (FolderShare $s): bool => $s->getOwnerId() === $o));
		$m->method('findByRecipient')->willReturnCallback(fn (string $r): array => $filter(static fn (FolderShare $s): bool => $s->getRecipientId() === $r));
		$m->method('findByShareId')->willReturnCallback(fn (string $id): array => $filter(static fn (FolderShare $s): bool => $s->getShareId() === $id));
		$m->method('countsByOwner')->willReturnCallback(function (string $o): array {
			$out = [];
			foreach ($this->folderShareStore as $s) {
				if ($s->getOwnerId() === $o) {
					$out[$s->getFolderId()] = ($out[$s->getFolderId()] ?? 0) + 1;
				}
			}
			return $out;
		});
		return $m;
	}

	private function shelf(int $id, string $owner, string $type = Shelf::TYPE_MANUAL, array $fileIds = []): Shelf {
		$s = new Shelf();
		$s->setId($id);
		$s->setUserId($owner);
		$s->setName('Shelf ' . $id);
		$s->setType($type);
		if ($type === Shelf::TYPE_SMART) {
			$s->setQuery('{"include":["tag:x"]}');
		}
		$this->shelfStore[$id] = $s;
		$this->assigned[$id] = $fileIds;
		return $s;
	}

	private function assertShareError(string $reason, callable $fn): void {
		try {
			$fn();
		} catch (ShareException $e) {
			$this->assertSame($reason, $e->reason, $e->getMessage());
			return;
		}
		$this->fail('ShareException (' . $reason . ') expected');
	}

	/** @return list<int> */
	private function queuedScans(string $user): array {
		$out = [];
		foreach ($this->queued as [$class, $arg]) {
			if ($class === ScanFileJob::class && ($arg['userId'] ?? null) === $user) {
				$out[] = (int)$arg['fileId'];
			}
		}
		return $out;
	}

	// ---- book shares ------------------------------------------------------------------------------------------------

	public function testShareBookCreatesReadOnlyUserShareWithoutMailAndQueuesIndexing(): void {
		$share = $this->service->shareBook('alice', 1, ' bob ');
		$this->assertSame('book', $share['type']);
		$this->assertSame(1, $share['fileId']);
		$this->assertSame('Book 1', $share['name']);
		$this->assertSame('bob', $share['recipient']);
		$this->assertSame('Bob', $share['recipientDisplayName']);
		$this->assertSame('Alice', $share['ownerDisplayName']);
		$this->assertSame(1800000000000, $share['createdAt']);
		$this->assertCount(1, $this->ncShares);
		$this->assertNotNull($this->lastShare);
		$this->assertSame(Constants::PERMISSION_READ, $this->lastShare->getPermissions());
		$this->assertFalse($this->lastShare->getMailSend());
		$this->assertSame('bob', $this->lastShare->getSharedWith());
		$this->assertSame('alice', $this->lastShare->getSharedBy());
		$this->assertSame([1], $this->queuedScans('bob'));
	}

	public function testSharingTheSameBookTwiceIsIdempotent(): void {
		$this->service->shareBook('alice', 1, 'bob');
		$this->service->shareBook('alice', 1, 'bob');
		$this->assertCount(1, $this->ncShares);
		$this->assertCount(1, $this->rows);
	}

	public function testRecipientValidation(): void {
		$this->assertShareError(ShareException::INVALID, fn () => $this->service->shareBook('alice', 1, ''));
		$this->assertShareError(ShareException::INVALID, fn () => $this->service->shareBook('alice', 1, 'alice'));
		$this->assertShareError(ShareException::INVALID, fn () => $this->service->shareBook('alice', 1, 'mallory'));
		$this->assertSame([], $this->ncShares);
	}

	public function testOnlyBooksOfTheOwnLibraryCanBeShared(): void {
		$this->assertShareError(ShareException::NOT_FOUND, fn () => $this->service->shareBook('bob', 1, 'alice'));
		$this->assertShareError(ShareException::NOT_FOUND, fn () => $this->service->shareBook('alice', 99, 'bob'));
	}

	public function testSharingDisabledOrNoSharePermissionIsForbidden(): void {
		$this->perms[2] = Constants::PERMISSION_READ;
		$this->assertShareError(ShareException::FORBIDDEN, fn () => $this->service->shareBook('alice', 2, 'bob'));
		$this->sharingEnabled = false;
		$this->assertShareError(ShareException::FORBIDDEN, fn () => $this->service->shareBook('alice', 1, 'bob'));
		$this->assertSame([], $this->ncShares);
	}

	public function testShareManagerRefusalBecomesForbiddenWithItsHint(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('shareApiEnabled')->willReturn(true);
		$manager->method('newShare')->willReturnCallback(fn (): IShare => $this->newShareMock());
		$manager->method('getSharesBy')->willReturn([]);
		$manager->method('createShare')->willThrowException(new GenericShareException('internal', 'Sharing is only allowed with group members'));
		$service = $this->serviceWith($manager);
		try {
			$service->shareBook('alice', 1, 'bob');
			$this->fail('expected');
		} catch (ShareException $e) {
			$this->assertSame(ShareException::FORBIDDEN, $e->reason);
			$this->assertSame('Sharing is only allowed with group members', $e->getMessage());
		}
		$this->assertSame([], $this->rows);
	}

	private function serviceWith(IManager $manager): ShareService {
		$r = new \ReflectionClass($this->service);
		$args = [];
		foreach ($r->getConstructor()?->getParameters() ?? [] as $p) {
			$prop = $r->getProperty($p->getName());
			$args[] = $p->getName() === 'shareManager' ? $manager : $prop->getValue($this->service);
		}
		return new ShareService(...$args);
	}

	public function testExistingManualShareIsReusedAndNeverDeleted(): void {
		$existing = $this->createMock(IShare::class);
		$existing->method('getSharedWith')->willReturn('bob');
		$manager = $this->createMock(IManager::class);
		$manager->method('shareApiEnabled')->willReturn(true);
		$manager->method('getSharesBy')->willReturn([$existing]);
		$manager->expects($this->never())->method('createShare');
		$manager->expects($this->never())->method('deleteShare');
		$service = $this->serviceWith($manager);
		$service->shareBook('alice', 1, 'bob');
		$this->assertCount(1, $this->rows);
		$this->assertNull(array_values($this->rows)[0]->getShareId());
		$service->unshareBook('alice', 1, 'bob');
		$this->assertSame([], $this->rows);
	}

	public function testUnshareBookDeletesTheAppShare(): void {
		$this->service->shareBook('alice', 1, 'bob');
		$this->queued = [];
		$this->service->unshareBook('alice', 1, 'bob');
		$this->assertSame([], $this->ncShares);
		$this->assertSame([], $this->rows);
		// the recipient's library drops the book via the queued job
		$this->assertSame([1], $this->queuedScans('bob'));
		$this->assertShareError(ShareException::NOT_FOUND, fn () => $this->service->unshareBook('alice', 1, 'bob'));
	}

	public function testRecipientCanLeaveABookShare(): void {
		$this->service->shareBook('alice', 1, 'bob');
		$this->assertSame(1, $this->service->leaveBook('bob', 1, 'alice'));
		$this->assertSame([], $this->ncShares);
		$this->assertShareError(ShareException::NOT_FOUND, fn () => $this->service->leaveBook('bob', 1));
		// a user cannot remove somebody else's share by "leaving" it
		$this->service->shareBook('alice', 2, 'bob');
		$this->assertShareError(ShareException::NOT_FOUND, fn () => $this->service->leaveBook('carol', 2));
		$this->assertCount(1, $this->ncShares);
	}

	// ---- shelf shares -----------------------------------------------------------------------------------------------

	public function testShareManualShelfSharesItsBooksAndIgnoresForeignOnes(): void {
		$this->shelf(10, 'alice', Shelf::TYPE_MANUAL, [1, 2, 77]);
		$res = $this->service->shareShelf('alice', 10, 'bob');
		$this->assertSame('shelf', $res['share']['type']);
		$this->assertSame(10, $res['share']['shelfId']);
		$this->assertSame(2, $res['share']['bookCount']);
		$this->assertSame(0, $res['skipped']);
		$this->assertCount(2, $this->ncShares);
		$this->assertEqualsCanonicalizing([1, 2], $this->queuedScans('bob'));
	}

	public function testLiveSyncAddsAndRemovesBooksOfAManualShelf(): void {
		$this->shelf(10, 'alice', Shelf::TYPE_MANUAL, [1, 2]);
		$this->service->shareShelf('alice', 10, 'bob');
		$this->assigned[10] = [2, 3];
		$this->service->syncShelf(10);
		$shared = array_map(static fn (array $s): int => $s['fileId'], $this->ncShares);
		sort($shared);
		$this->assertSame([2, 3], $shared);
	}

	public function testFileStaysSharedWhileAnotherReasonNeedsIt(): void {
		$this->shelf(10, 'alice', Shelf::TYPE_MANUAL, [1, 2]);
		$this->service->shareShelf('alice', 10, 'bob');
		$this->service->shareBook('alice', 1, 'bob');
		// one Nextcloud share per file, two reasons for file 1
		$this->assertCount(2, $this->ncShares);
		$this->assertCount(3, $this->rows);

		$this->service->unshareShelf('alice', 10, 'bob');
		$this->assertCount(1, $this->ncShares);
		$this->assertSame(1, array_values($this->ncShares)[0]['fileId']);
		$this->assertSame([], $this->shelfShareStore);

		$this->service->unshareBook('alice', 1, 'bob');
		$this->assertSame([], $this->ncShares);
	}

	public function testBooksWithoutSharePermissionAreSkipped(): void {
		$this->perms[2] = Constants::PERMISSION_READ;
		$this->shelf(10, 'alice', Shelf::TYPE_MANUAL, [1, 2]);
		$res = $this->service->shareShelf('alice', 10, 'bob');
		$this->assertSame(1, $res['skipped']);
		$this->assertCount(1, $this->ncShares);
	}

	public function testSmartShelfSharesTheCurrentHits(): void {
		$this->shelf(11, 'alice', Shelf::TYPE_SMART);
		$hits = [3, 4];
		$this->library->method('findBooks')->willReturnCallback(function () use (&$hits): array {
			return ['books' => array_map(fn (int $id): Book => $this->book($id), $hits), 'total' => count($hits)];
		});
		$this->service->shareShelf('alice', 11, 'bob');
		$this->assertCount(2, $this->ncShares);
		$hits = [4];
		$this->assertSame(1, $this->service->syncAll());
		$this->assertSame([4], array_values(array_map(static fn (array $s): int => $s['fileId'], $this->ncShares)));
	}

	public function testOnlyTheOwnerCanShareAShelf(): void {
		$this->shelf(10, 'alice', Shelf::TYPE_MANUAL, [1]);
		$this->assertShareError(ShareException::NOT_FOUND, fn () => $this->service->shareShelf('bob', 10, 'carol'));
		$this->assertShareError(ShareException::NOT_FOUND, fn () => $this->service->unshareShelf('bob', 10, 'carol'));
	}

	public function testRemoveShelfAndRecipientLeavingDropEverything(): void {
		$this->shelf(10, 'alice', Shelf::TYPE_MANUAL, [1, 2]);
		$this->service->shareShelf('alice', 10, 'bob');
		$this->service->shareShelf('alice', 10, 'carol');
		$this->service->leaveShelf('carol', 10);
		$this->assertCount(2, $this->ncShares);
		$this->assertFalse($this->service->isIncomingShelf('carol', 10));
		$this->assertTrue($this->service->isIncomingShelf('bob', 10));
		$this->service->removeShelf(10);
		$this->assertSame([], $this->ncShares);
		$this->assertSame([], $this->rows);
		$this->assertSame([], $this->shelfShareStore);
	}

	public function testDeletedShelfDropsItsSharesOnSync(): void {
		$this->shelf(10, 'alice', Shelf::TYPE_MANUAL, [1]);
		$this->service->shareShelf('alice', 10, 'bob');
		unset($this->shelfStore[10]);
		$this->service->syncAll();
		$this->assertSame([], $this->ncShares);
		$this->assertSame([], $this->shelfShareStore);
	}

	public function testLargeShelvesContinueInABackgroundJob(): void {
		$this->library_['alice'] = range(1, ShareService::INLINE_NEW_SHARES + 5);
		$this->shelf(10, 'alice', Shelf::TYPE_MANUAL, $this->library_['alice']);
		$this->service->shareShelf('alice', 10, 'bob');
		$this->assertCount(ShareService::INLINE_NEW_SHARES, $this->ncShares);
		$this->assertContains(SyncShelfShareJob::class, array_column($this->queued, 0));
		$id = array_key_first($this->shelfShareStore);
		$this->service->syncById((int)$id);
		$this->assertCount(ShareService::INLINE_NEW_SHARES + 5, $this->ncShares);
	}

	// ---- overview and events ----------------------------------------------------------------------------------------

	public function testOverviewListsOutgoingAndIncoming(): void {
		$this->shelf(10, 'alice', Shelf::TYPE_MANUAL, [1, 2]);
		$this->service->shareShelf('alice', 10, 'bob');
		$this->service->shareBook('alice', 3, 'bob');
		$alice = $this->service->overview('alice');
		$this->assertSame([], $alice['incoming']);
		$this->assertSame(['book', 'shelf'], array_column($alice['outgoing'], 'type'));
		$this->assertSame(2, $alice['outgoing'][1]['bookCount']);
		$bob = $this->service->overview('bob');
		$this->assertSame([], $bob['outgoing']);
		$this->assertSame(['book', 'shelf'], array_column($bob['incoming'], 'type'));
		// the recipient has not indexed it yet: the owner's title is shown
		$this->assertSame('Book 3', $bob['incoming'][0]['name']);
		$this->assertSame('alice', $bob['incoming'][1]['owner']);
	}

	public function testOverviewDropsSharesOfBooksThatLeftTheLibrary(): void {
		$this->service->shareBook('alice', 1, 'bob');
		$this->library_['alice'] = [2, 3, 4];
		$this->assertSame([], $this->service->overview('alice')['outgoing']);
		$this->assertSame([], $this->ncShares);
	}

	public function testShareDeletedInFilesDetachesShelfReasonsAndDropsDirectOnes(): void {
		$this->shelf(10, 'alice', Shelf::TYPE_MANUAL, [1]);
		$this->service->shareShelf('alice', 10, 'bob');
		$this->service->shareBook('alice', 1, 'bob');
		$id = (string)array_key_first($this->ncShares);
		unset($this->ncShares[$id]);
		$event = $this->createMock(IShare::class);
		$event->method('getFullId')->willReturn($id);
		$this->service->onShareDeleted($event);
		$this->assertCount(1, $this->rows);
		$row = array_values($this->rows)[0];
		$this->assertFalse($row->isDirect());
		$this->assertNull($row->getShareId());
		// the user's decision is respected: the next sync does not share the file again
		$this->service->syncShelf(10);
		$this->assertSame([], $this->ncShares);
	}

	public function testEnsureIncomingIndexedIndexesMissingBooks(): void {
		$this->service->shareBook('alice', 1, 'bob');
		$this->service->shareBook('alice', 2, 'bob');
		$this->library_['bob'] = [1, 2];
		$this->library->expects($this->exactly(2))->method('indexFile')->willReturn(new Book());
		// both books are reachable for bob but not in his library rows yet
		$this->bookRows['bob'] = [];
		$this->assertSame(2, $this->service->ensureIncomingIndexed('bob'));
	}

	public function testDeleteAllForUserRemovesRecords(): void {
		$this->shelf(10, 'alice', Shelf::TYPE_MANUAL, [1]);
		$this->service->shareShelf('alice', 10, 'bob');
		$this->service->shareBook('alice', 2, 'carol');
		$this->service->deleteAllForUser('bob');
		$this->assertSame([], $this->shelfShareStore);
		$this->assertCount(1, $this->rows);
	}

	// ---- sharedOut reaches the delta sync (owner's rows are touched) --------------------------------------------------

	public function testSharingABookTouchesTheOwnersRowOnlyOnceWithMilliseconds(): void {
		$this->service->shareBook('alice', 1, 'bob');
		$this->assertSame([['alice', [1], 1800000000000]], $this->touched);
		$this->touched = [];
		$this->service->shareBook('alice', 1, 'bob');
		$this->assertSame([], $this->touched);
	}

	public function testUnsharingABookAndLeavingItTouchTheOwnersRow(): void {
		$this->service->shareBook('alice', 1, 'bob');
		$this->service->shareBook('alice', 2, 'bob');
		$this->touched = [];
		$this->service->unshareBook('alice', 1, 'bob');
		$this->assertSame([['alice', [1], 1800000000000]], $this->touched);
		$this->touched = [];
		$this->service->leaveBook('bob', 2);
		$this->assertSame([['alice', [2], 1800000000000]], $this->touched);
	}

	public function testShelfAndSeriesSyncTouchAddedAndRemovedBooks(): void {
		$this->shelf(10, 'alice', Shelf::TYPE_MANUAL, [1, 2]);
		$this->service->shareShelf('alice', 10, 'bob');
		$this->assertSame([['alice', [1, 2], 1800000000000]], $this->touched);
		$this->touched = [];
		$this->assigned[10] = [2];
		$this->service->syncShelf(10);
		$this->assertSame([['alice', [1], 1800000000000]], $this->touched);
		$this->touched = [];
		$this->service->unshareShelf('alice', 10, 'bob');
		$this->assertSame([['alice', [2], 1800000000000]], $this->touched);
		$this->touched = [];
		$this->service->shareSeries('alice', 'Saga', 'bob');
		$this->assertSame([['alice', [1, 2, 3], 1800000000000]], $this->touched);
		$this->touched = [];
		$this->service->unshareSeries('alice', 'Saga', 'bob');
		$this->assertCount(1, $this->touched);
		$this->assertSame('alice', $this->touched[0][0]);
		$this->assertEqualsCanonicalizing([1, 2, 3], $this->touched[0][1]);
	}

	public function testFolderShareAndUnshareTouchTheOwnersBooksBelowTheFolder(): void {
		$this->service->shareFolder('alice', '/Books/Saga', 'bob');
		$this->assertSame([['alice', '/Books/Saga', 1800000000000]], $this->touched);
		$this->touched = [];
		$this->service->shareFolder('alice', '/Books/Saga', 'bob');
		$this->assertSame([], $this->touched);
		$this->service->unshareFolder('alice', '/Books/Saga', 'bob');
		$this->assertSame([['alice', '/Books/Saga', 1800000000000]], $this->touched);
	}

	public function testShareDeletedInFilesTouchesTheOwnersBooks(): void {
		$this->service->shareBook('alice', 1, 'bob');
		$this->service->shareFolder('alice', '/Books/Saga', 'carol');
		$this->touched = [];
		$id = (string)array_key_first($this->ncShares);
		$event = $this->createMock(IShare::class);
		$event->method('getFullId')->willReturn($id);
		$this->service->onShareDeleted($event);
		$this->assertSame([['alice', [1], 1800000000000]], $this->touched);

		$this->touched = [];
		$folderShareId = (string)array_key_last($this->ncShares);
		$event = $this->createMock(IShare::class);
		$event->method('getFullId')->willReturn($folderShareId);
		$this->service->onShareDeleted($event);
		$this->assertSame([['alice', '/Books/Saga', 1800000000000]], $this->touched);
	}

	public function testDeletingARecipientTouchesTheOwnersBooksButNotTheRecipientsRows(): void {
		$this->service->shareBook('alice', 1, 'bob');
		$this->service->shareFolder('alice', '/Books/Saga', 'bob');
		$this->touched = [];
		$this->service->deleteAllForUser('bob');
		$this->assertSame([['alice', [1], 1800000000000], ['alice', '/Books/Saga', 1800000000000]], $this->touched);
	}

	public function testDeletingAnOwnerTouchesNothing(): void {
		$this->service->shareBook('alice', 1, 'bob');
		$this->touched = [];
		$this->service->deleteAllForUser('alice');
		$this->assertSame([], $this->touched);
	}

	// ---- series shares ----------------------------------------------------------------------------------------------

	public function testShareSeriesSharesAllOwnBooksOfTheSeriesCaseInsensitively(): void {
		$result = $this->service->shareSeries('alice', ' SAGA ', 'bob');
		$this->assertSame('series', $result['share']['type']);
		$this->assertSame('Saga', $result['share']['series']);
		$this->assertSame('Saga', $result['share']['name']);
		$this->assertSame(3, $result['share']['bookCount']);
		$this->assertSame(0, $result['skipped']);
		// books 1, 2 and 3 ("Saga", "saga", "Saga"); book 4 belongs to another series
		$this->assertSame([1, 2, 3], array_values(array_map(static fn (FileShare $r): int => $r->getFileId(), $this->rows)));
		$this->assertCount(3, $this->ncShares);
		foreach ($this->rows as $row) {
			$this->assertFalse($row->isDirect());
			$this->assertLessThan(0, $row->getShelfShareId(), 'series reasons are negative');
		}
		$this->assertSame([1, 2, 3], $this->queuedScans('bob'));
	}

	public function testShareSeriesIsIdempotentAndKeepsSharesApart(): void {
		$this->service->shareSeries('alice', 'Saga', 'bob');
		$this->service->shareSeries('alice', 'saga', 'bob');
		$this->assertCount(1, $this->seriesShareStore);
		$this->assertCount(3, $this->rows);
		$this->assertCount(3, $this->ncShares);
	}

	public function testShareSeriesValidation(): void {
		$this->assertShareError(ShareException::INVALID, fn () => $this->service->shareSeries('alice', '  ', 'bob'));
		$this->assertShareError(ShareException::INVALID, fn () => $this->service->shareSeries('alice', 'Saga', 'alice'));
		$this->assertShareError(ShareException::INVALID, fn () => $this->service->shareSeries('alice', 'Saga', 'mallory'));
		$this->assertShareError(ShareException::NOT_FOUND, fn () => $this->service->shareSeries('alice', 'Unknown series', 'bob'));
		// the series of another user is not shareable by name
		$this->assertShareError(ShareException::NOT_FOUND, fn () => $this->service->shareSeries('bob', 'Saga', 'alice'));
		$this->sharingEnabled = false;
		$this->assertShareError(ShareException::FORBIDDEN, fn () => $this->service->shareSeries('alice', 'Saga', 'bob'));
		$this->assertSame([], $this->ncShares);
		$this->assertSame([], $this->seriesShareStore);
	}

	public function testSeriesIsLiveNewBooksAreSharedAndLeavingBooksUnshared(): void {
		$this->service->shareSeries('alice', 'Saga', 'bob');
		$this->library_['alice'][] = 5;
		$this->seriesOf[5] = 'Saga';
		unset($this->seriesOf[2]);
		$this->assertSame(1, $this->service->syncAll());
		$files = array_values(array_map(static fn (FileShare $r): int => $r->getFileId(), $this->rows));
		sort($files);
		$this->assertSame([1, 3, 5], $files);
		$this->assertCount(3, $this->ncShares);
	}

	public function testSeriesShareSkipsBooksWithoutSharePermissionAndIncomingBooks(): void {
		$this->perms[3] = Constants::PERMISSION_READ;
		$result = $this->service->shareSeries('alice', 'Saga', 'bob');
		$this->assertSame(1, $result['skipped']);
		$this->assertCount(2, $this->rows);
	}

	public function testUnshareSeriesRemovesFileSharesButKeepsDirectBookShares(): void {
		$this->service->shareBook('alice', 1, 'bob');
		$this->service->shareSeries('alice', 'Saga', 'bob');
		$this->queued = [];
		$this->service->unshareSeries('alice', 'saga', 'bob');
		$this->assertSame([], $this->seriesShareStore);
		// book 1 stays shared directly (its Nextcloud share is reused), books 2 and 3 are unshared
		$this->assertCount(1, $this->rows);
		$this->assertCount(1, $this->ncShares);
		// the recipient's library re-checks the books whose share reason went away
		$this->assertEqualsCanonicalizing([1, 2, 3], $this->queuedScans('bob'));
		$this->assertShareError(ShareException::NOT_FOUND, fn () => $this->service->unshareSeries('alice', 'Saga', 'bob'));
	}

	public function testSeriesAndShelfSharesNeedingTheSameFileKeepItShared(): void {
		$this->shelf(10, 'alice', Shelf::TYPE_MANUAL, [1]);
		$this->service->shareShelf('alice', 10, 'bob');
		$this->service->shareSeries('alice', 'Saga', 'bob');
		$this->assertCount(3, $this->ncShares);
		$this->service->unshareSeries('alice', 'Saga', 'bob');
		$this->assertCount(1, $this->ncShares);
		$this->assertCount(1, $this->rows);
	}

	public function testSeriesShareCountsAreKeyedByCaseInsensitiveName(): void {
		$this->service->shareSeries('alice', 'Saga', 'bob');
		$this->service->shareSeries('alice', 'saga', 'carol');
		$counts = $this->service->seriesShareCounts('alice');
		$this->assertSame([SeriesShare::keyOf('SAGA') => 2], $counts);
	}

	public function testSeriesRecipientLimit(): void {
		$this->service->shareSeries('alice', 'Saga', 'bob');
		for ($i = 0; $i < ShareService::MAX_SERIES_FOLDER_RECIPIENTS - 1; $i++) {
			$s = new SeriesShare();
			$s->setOwnerId('alice');
			$s->setRecipientId('fake' . $i);
			$s->setSeries('Saga');
			$s->setSeriesKey(SeriesShare::keyOf('Saga'));
			$s->setId($this->nextId++);
			$this->seriesShareStore[$s->getId()] = $s;
		}
		$this->assertShareError(ShareException::INVALID, fn () => $this->service->shareSeries('alice', 'Saga', 'carol'));
	}

	public function testOverviewListsSeriesShares(): void {
		$this->service->shareSeries('alice', 'Saga', 'bob');
		$out = $this->service->overview('alice')['outgoing'];
		$series = array_values(array_filter($out, static fn (array $s): bool => $s['type'] === 'series'));
		$this->assertCount(1, $series);
		$this->assertSame('Saga', $series[0]['series']);
		$this->assertSame(3, $series[0]['bookCount']);
		$this->assertSame('bob', $series[0]['recipient']);
		$in = $this->service->overview('bob')['incoming'];
		$series = array_values(array_filter($in, static fn (array $s): bool => $s['type'] === 'series'));
		$this->assertCount(1, $series);
		$this->assertSame('alice', $series[0]['owner']);
	}

	public function testDeleteAllForUserRemovesSeriesAndFolderRecords(): void {
		$this->service->shareSeries('alice', 'Saga', 'bob');
		$this->service->shareFolder('alice', '/Books/Saga', 'bob');
		$this->service->deleteAllForUser('bob');
		$this->assertSame([], $this->seriesShareStore);
		$this->assertSame([], $this->folderShareStore);
	}

	// ---- folder shares ----------------------------------------------------------------------------------------------

	public function testShareFolderCreatesOneReadOnlyShareOfTheFolder(): void {
		$result = $this->service->shareFolder('alice', '/Books/Saga/', 'bob');
		$this->assertSame('folder', $result['share']['type']);
		$this->assertSame('Saga', $result['share']['name']);
		$this->assertSame('/Books/Saga', $result['share']['path']);
		$this->assertSame('bob', $result['share']['recipient']);
		$this->assertCount(1, $this->ncShares);
		$this->assertSame(500, array_values($this->ncShares)[0]['fileId']);
		$this->assertNotNull($this->lastShare);
		$this->assertSame(Constants::PERMISSION_READ, $this->lastShare->getPermissions());
		$this->assertFalse($this->lastShare->getMailSend());
		$this->assertSame([], $this->rows, 'no per-book share rows');
		$this->assertCount(1, $this->folderShareStore);
		// the recipient's library picks the books up through a scan of the folder
		$this->assertSame([500], $this->queuedScans('bob'));
	}

	public function testShareFolderIsIdempotent(): void {
		$this->service->shareFolder('alice', '/Books/Saga', 'bob');
		$this->service->shareFolder('alice', 'Books//Saga', 'bob');
		$this->assertCount(1, $this->ncShares);
		$this->assertCount(1, $this->folderShareStore);
	}

	public function testShareFolderRules(): void {
		$this->assertShareError(ShareException::INVALID, fn () => $this->service->shareFolder('alice', '', 'bob'));
		$this->assertShareError(ShareException::INVALID, fn () => $this->service->shareFolder('alice', '/', 'bob'));
		$this->assertShareError(ShareException::INVALID, fn () => $this->service->shareFolder('alice', '/Books/../Music', 'bob'));
		$this->assertShareError(ShareException::INVALID, fn () => $this->service->shareFolder('alice', '/Books/Saga', 'alice'));
		$this->assertShareError(ShareException::INVALID, fn () => $this->service->shareFolder('alice', '/Books/Saga', 'mallory'));
		// outside the library folders
		$this->assertShareError(ShareException::FORBIDDEN, fn () => $this->service->shareFolder('alice', '/Music', 'bob'));
		// not an existing folder of the user
		$this->assertShareError(ShareException::NOT_FOUND, fn () => $this->service->shareFolder('alice', '/Books/Nothing', 'bob'));
		$this->assertShareError(ShareException::NOT_FOUND, fn () => $this->service->shareFolder('bob', '/Books/Saga', 'alice'));
		$this->folderPerms[500] = Constants::PERMISSION_READ;
		$this->assertShareError(ShareException::FORBIDDEN, fn () => $this->service->shareFolder('alice', '/Books/Saga', 'bob'));
		$this->folderPerms = [];
		$this->sharingEnabled = false;
		$this->assertShareError(ShareException::FORBIDDEN, fn () => $this->service->shareFolder('alice', '/Books/Saga', 'bob'));
		$this->assertSame([], $this->ncShares);
		$this->assertSame([], $this->folderShareStore);
	}

	public function testUnshareFolderDeletesTheShareAndQueuesTheRecipientCheck(): void {
		$this->service->shareFolder('alice', '/Books/Saga', 'bob');
		$this->queued = [];
		$this->service->unshareFolder('alice', '/Books/Saga', 'bob');
		$this->assertSame([], $this->ncShares);
		$this->assertSame([], $this->folderShareStore);
		// books 1 and 2 of the folder are re-checked for bob (his rows are tombstoned when unreachable)
		$this->assertSame([1, 2], $this->queuedScans('bob'));
		$this->assertShareError(ShareException::NOT_FOUND, fn () => $this->service->unshareFolder('alice', '/Books/Saga', 'bob'));
	}

	public function testUnshareFolderNeverDeletesAShareTheUserMadeInFiles(): void {
		$existing = $this->createMock(IShare::class);
		$existing->method('getSharedWith')->willReturn('bob');
		$manager = $this->createMock(IManager::class);
		$manager->method('shareApiEnabled')->willReturn(true);
		$manager->method('getSharesBy')->willReturn([$existing]);
		$manager->expects($this->never())->method('createShare');
		$manager->expects($this->never())->method('deleteShare');
		$service = $this->serviceWith($manager);
		$service->shareFolder('alice', '/Books/Saga', 'bob');
		$this->assertNull(array_values($this->folderShareStore)[0]->getShareId());
		$service->unshareFolder('alice', '/Books/Saga', 'bob');
		$this->assertSame([], $this->folderShareStore);
	}

	public function testFolderShareCountsAreKeyedByCurrentPath(): void {
		$this->service->shareFolder('alice', '/Books/Saga', 'bob');
		$this->service->shareFolder('alice', '/Books/Saga', 'carol');
		$this->service->shareFolder('alice', '/Books', 'bob');
		$this->assertSame(['/Books/Saga' => 2, '/Books' => 1], $this->service->folderShareCounts('alice'));
		$this->assertSame([], $this->service->folderShareCounts('bob'));
	}

	public function testFolderRecipientLimit(): void {
		$this->service->shareFolder('alice', '/Books/Saga', 'bob');
		for ($i = 0; $i < ShareService::MAX_SERIES_FOLDER_RECIPIENTS - 1; $i++) {
			$s = new FolderShare();
			$s->setOwnerId('alice');
			$s->setRecipientId('fake' . $i);
			$s->setFolderId(500);
			$s->setId($this->nextId++);
			$this->folderShareStore[$s->getId()] = $s;
		}
		$this->assertShareError(ShareException::INVALID, fn () => $this->service->shareFolder('alice', '/Books/Saga', 'carol'));
	}

	public function testOverviewListsFolderSharesAndHidesThePathFromTheRecipient(): void {
		$this->service->shareFolder('alice', '/Books/Saga', 'bob');
		$out = array_values(array_filter($this->service->overview('alice')['outgoing'], static fn (array $s): bool => $s['type'] === 'folder'));
		$this->assertCount(1, $out);
		$this->assertSame('/Books/Saga', $out[0]['path']);
		$this->assertSame(3, $out[0]['bookCount']);
		$in = array_values(array_filter($this->service->overview('bob')['incoming'], static fn (array $s): bool => $s['type'] === 'folder'));
		$this->assertCount(1, $in);
		$this->assertSame('Saga', $in[0]['name']);
		$this->assertNull($in[0]['path']);
		$this->assertSame('alice', $in[0]['owner']);
	}

	public function testShareDeletedInFilesDropsTheFolderShareRecord(): void {
		$this->service->shareFolder('alice', '/Books/Saga', 'bob');
		$id = array_key_first($this->ncShares);
		$event = $this->createMock(IShare::class);
		$event->method('getFullId')->willReturn($id);
		$this->service->onShareDeleted($event);
		$this->assertSame([], $this->folderShareStore);
		$this->assertContains(1, $this->queuedScans('bob'));
	}

	public function testAcceptingAFolderShareQueuesTheFolderScan(): void {
		$this->service->shareFolder('alice', '/Books/Saga', 'bob');
		$this->queued = [];
		$event = $this->createMock(IShare::class);
		$event->method('getFullId')->willReturn((string)array_key_first($this->ncShares));
		$this->service->onShareAccepted($event);
		$this->assertSame([500], $this->queuedScans('bob'));
	}

	public function testEnsureIncomingIndexedAlsoIndexesIncomingFolders(): void {
		$this->library->expects($this->once())->method('indexIncomingFolders')->with('bob', ShareService::INLINE_INDEX, 5.0)->willReturn(['indexed' => 4, 'pending' => 0]);
		$this->assertSame(4, $this->service->ensureIncomingIndexed('bob'));
	}

	public function testIncomingFolderWalkIsThrottled(): void {
		$values = [];
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getUserValue')->willReturnCallback(static function (string $u, string $app, string $key, string $default = '') use (&$values): string {
			return $values[$u . $key] ?? $default;
		});
		$config->method('setUserValue')->willReturnCallback(static function (string $u, string $app, string $key, mixed $value) use (&$values): void {
			$values[$u . $key] = (string)$value;
		});
		$r = new \ReflectionClass($this->service);
		$args = [];
		foreach ($r->getConstructor()?->getParameters() ?? [] as $p) {
			$args[] = $p->getName() === 'config' ? $config : $r->getProperty($p->getName())->getValue($this->service);
		}
		$service = new ShareService(...$args);
		$this->library->expects($this->once())->method('indexIncomingFolders')->willReturn(['indexed' => 0, 'pending' => 0]);
		$service->ensureIncomingIndexed('bob');
		$service->ensureIncomingIndexed('bob');
	}
}
