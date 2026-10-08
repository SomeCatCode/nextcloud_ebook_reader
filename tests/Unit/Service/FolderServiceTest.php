<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Controller\FoldersController;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Service\FolderService;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\ShareService;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** GET /folders: the folder tree computed from the book paths. */
class FolderServiceTest extends TestCase {
	private BookMapper&MockObject $books;
	private LibraryService&MockObject $library;
	private IUserMountCache&MockObject $mounts;
	private IUserManager&MockObject $users;
	private ?ShareService $sharing = null;
	/** @var list<string> */
	private array $libraryRoots = ['/Books'];

	protected function setUp(): void {
		$this->libraryRoots = ['/Books'];
		$this->books = $this->createMock(BookMapper::class);
		$this->library = $this->createMock(LibraryService::class);
		$this->library->method('isPathInLibrary')->willReturnCallback(function (string $u, string $path): bool {
			foreach ($this->libraryRoots as $root) {
				if ($root === '/' || $path === $root || str_starts_with($path, $root . '/')) {
					return true;
				}
			}
			return false;
		});
		$this->mounts = $this->createMock(IUserMountCache::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('get')->willReturn($this->createMock(IUser::class));
	}

	private function service(): FolderService {
		return new FolderService($this->books, $this->library, $this->mounts, $this->users, $this->sharing ?? $this->createMock(ShareService::class), $this->createMock(LoggerInterface::class));
	}

	/** @param list<string> $points user-relative mount points of incoming shares */
	private function shareMounts(array $points): void {
		$infos = [];
		foreach ($points as $p) {
			$m = $this->createMock(ICachedMountInfo::class);
			$m->method('getMountProvider')->willReturn('OCA\\Files_Sharing\\MountProvider');
			$m->method('getMountPoint')->willReturn('/u/files' . $p . '/');
			$infos[] = $m;
		}
		$other = $this->createMock(ICachedMountInfo::class);
		$other->method('getMountProvider')->willReturn('OC\\Files\\Mount\\LocalHomeMountProvider');
		$other->method('getMountPoint')->willReturn('/u/files/');
		$infos[] = $other;
		$this->mounts->method('getMountsForUser')->willReturn($infos);
	}

	/** @param list<string> $paths */
	private function paths(array $paths): void {
		$this->books->method('findPathsByUser')->with('u')->willReturn($paths);
	}

	/** @return array<string, array<string, mixed>> */
	private function byPath(): array {
		$out = [];
		foreach ($this->service()->listFolders('u') as $f) {
			$out[$f['path']] = $f;
		}
		return $out;
	}

	public function testCountsDirectAndTotalBooksAndListsAncestorsUpToTheLibraryRoot(): void {
		$this->shareMounts([]);
		$this->paths(['/Books/a.epub', '/Books/Comics/Saga/v01.cbz', '/Books/Comics/Saga/v02.cbz', '/Books/Comics/Other/x.cbz', '/Books/Comics/Saga/Extra/e.cbz']);
		$f = $this->byPath();
		$this->assertSame(['/Books', '/Books/Comics', '/Books/Comics/Other', '/Books/Comics/Saga', '/Books/Comics/Saga/Extra'], array_keys($f));
		$this->assertEquals(['name' => 'Books', 'parent' => null, 'bookCount' => 1, 'totalCount' => 5], array_intersect_key($f['/Books'], array_flip(['bookCount', 'totalCount', 'parent', 'name'])));
		$this->assertEquals(['name' => 'Comics', 'parent' => '/Books', 'bookCount' => 0, 'totalCount' => 4], array_intersect_key($f['/Books/Comics'], array_flip(['bookCount', 'totalCount', 'parent', 'name'])));
		$this->assertSame(2, $f['/Books/Comics/Saga']['bookCount']);
		$this->assertSame(3, $f['/Books/Comics/Saga']['totalCount']);
		$this->assertSame('/Books/Comics', $f['/Books/Comics/Saga']['parent']);
		$this->assertSame(1, $f['/Books/Comics/Saga/Extra']['bookCount']);
	}

	public function testAncestorsOutsideTheLibraryAreNotListed(): void {
		$this->shareMounts([]);
		$this->libraryRoots = ['/Books/Comics'];
		$this->paths(['/Books/Comics/Saga/v01.cbz']);
		$this->assertSame(['/Books/Comics', '/Books/Comics/Saga'], array_keys($this->byPath()));
		$this->assertNull($this->byPath()['/Books/Comics']['parent'], 'the top-most folder inside the library is a top-level entry');
	}

	public function testBooksOutsideTheLibraryStillGetTheirFolder(): void {
		// a book shared through the app and mounted outside the library folders
		$this->shareMounts([]);
		$this->paths(['/Shared/x.epub']);
		$f = $this->byPath();
		$this->assertSame(['/Shared'], array_keys($f));
		$this->assertFalse($f['/Shared']['shared']);
	}

	public function testIncomingShareMountsAreMarkedAndEndTheAncestorChain(): void {
		$this->libraryRoots = ['/Books'];
		$this->shareMounts(['/Shared/Comics']);
		$this->paths(['/Shared/Comics/Saga/v01.cbz', '/Shared/Comics/c.cbz', '/Books/own.epub']);
		$f = $this->byPath();
		$this->assertSame(['/Books', '/Shared/Comics', '/Shared/Comics/Saga'], array_keys($f));
		$this->assertTrue($f['/Shared/Comics']['shared']);
		$this->assertTrue($f['/Shared/Comics/Saga']['shared']);
		$this->assertFalse($f['/Books']['shared']);
		$this->assertNull($f['/Shared/Comics']['parent'], '/Shared is neither library nor share');
		$this->assertSame(2, $f['/Shared/Comics']['totalCount']);
	}

	public function testShareMountInsideALibraryFolder(): void {
		$this->shareMounts(['/Books/FromAnna']);
		$this->paths(['/Books/FromAnna/a.epub', '/Books/b.epub']);
		$f = $this->byPath();
		$this->assertTrue($f['/Books/FromAnna']['shared']);
		$this->assertSame('/Books', $f['/Books/FromAnna']['parent']);
		$this->assertFalse($f['/Books']['shared']);
		$this->assertSame(2, $f['/Books']['totalCount']);
	}

	public function testLibraryRootOfTheWholeHome(): void {
		$this->shareMounts([]);
		$this->libraryRoots = ['/'];
		$this->paths(['/top.epub', '/Books/a.epub', '/Books/Sub/b.epub']);
		$f = $this->byPath();
		$this->assertSame(['/Books', '/Books/Sub'], array_keys($f), 'the home folder itself has no entry');
		$this->assertNull($f['/Books']['parent']);
	}

	public function testMetaFoldersNeverAppear(): void {
		$this->shareMounts([]);
		$this->paths(['/Books/a.epub', '/Books/.meta/stray.epub']);
		$this->assertSame(['/Books'], array_keys($this->byPath()));
	}

	public function testSortedByPathInTreeOrder(): void {
		$this->shareMounts([]);
		$this->paths(['/Books/B/x.epub', '/Books/A/sub/x.epub', '/Books/a2/x.epub', '/Books/a10/x.epub']);
		$this->assertSame(
			['/Books', '/Books/A', '/Books/A/sub', '/Books/a2', '/Books/a10', '/Books/B'],
			array_column($this->service()->listFolders('u'), 'path'),
		);
	}

	public function testSharedWithComesFromTheFolderShares(): void {
		$this->shareMounts([]);
		$this->paths(['/Books/a.epub', '/Books/Saga/b.epub']);
		$sharing = $this->createMock(ShareService::class);
		$sharing->method('folderShareCounts')->with('u')->willReturn(['/Books/Saga' => 2]);
		$this->sharing = $sharing;
		$this->assertSame(2, $this->byPath()['/Books/Saga']['sharedWith']);
		$this->assertSame(0, $this->byPath()['/Books']['sharedWith']);
	}

	public function testEmptyLibrary(): void {
		$this->shareMounts([]);
		$this->paths([]);
		$this->assertSame([], $this->service()->listFolders('u'));
	}

	public function testMountCacheFailureDoesNotBreakTheList(): void {
		$this->mounts->method('getMountsForUser')->willThrowException(new \RuntimeException('boom'));
		$this->paths(['/Books/a.epub']);
		$this->assertSame(['/Books'], array_keys($this->byPath()));
	}

	public function testControllerWrapsTheListInFolders(): void {
		$this->shareMounts([]);
		$this->paths(['/Books/a.epub']);
		$controller = new FoldersController($this->createMock(IRequest::class), 'u', $this->service());
		$data = $controller->index()->getData();
		$this->assertSame(['folders'], array_keys($data));
		$this->assertSame('/Books', $data['folders'][0]['path']);
		$this->assertSame(['path', 'name', 'parent', 'bookCount', 'totalCount', 'sharedWith', 'shared'], array_keys($data['folders'][0]));
	}
}
