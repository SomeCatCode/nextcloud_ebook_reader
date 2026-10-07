<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Db\Annotation;
use OCA\EbookReader\Db\AnnotationMapper;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Progress;
use OCA\EbookReader\Db\Tag;
use OCA\EbookReader\Metadata\SidecarService;
use OCA\EbookReader\Service\ArchiveCache;
use OCA\EbookReader\Service\ArchiveTools;
use OCA\EbookReader\Service\ConvertService;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\ProgressService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\ITempManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/OcHooksEmitterStub.php';

/**
 * Converting/optimizing a comic in a folder that is shared with other users: everybody who has the original in their library
 * gets the new file indexed and their status, rating, tags, reading position and annotations carried over, not just the
 * acting user (deleting the original tombstones the rows of all of them).
 */
class ConvertSharedFolderTest extends TestCase {
	private LibraryService&MockObject $library;
	private ProgressService&MockObject $progress;
	private BookMapper&MockObject $books;
	private AnnotationMapper&MockObject $annotations;
	private ConvertService $service;
	private Folder&MockObject $folder;
	/** @var array<string, Book> user id => stored row of the new file */
	private array $newBooks = [];
	/** @var array<string, Book> user id => row of the original */
	private array $oldBooks = [];
	/** @var list<array{string, int, array<string, mixed>, float, ?string, int}> */
	private array $puts = [];
	/** @var array<string, list<Annotation>> */
	private array $annotationsOf = [];
	/** @var list<string> users whose library does not contain the new file */
	private array $notInLibrary = [];
	private string $tmp;

	protected function setUp(): void {
		$this->tmp = sys_get_temp_dir() . '/ebr-convert-shared-' . bin2hex(random_bytes(4));
		mkdir($this->tmp);
		file_put_contents($this->tmp . '/a.cbr', 'rar');
		file_put_contents($this->tmp . '/a.cbz', 'zip');
		$this->library = $this->createMock(LibraryService::class);
		$this->progress = $this->createMock(ProgressService::class);
		$this->books = $this->createMock(BookMapper::class);
		$this->annotations = $this->createMock(AnnotationMapper::class);
		$this->folder = $this->createMock(Folder::class);

		$original = $this->file('a.cbr', 5);
		$uploaded = $this->file('a.cbz', 101);
		$this->folder->method('get')->willReturnCallback(fn (string $n): File => $n === 'a.cbz' ? $uploaded : throw new NotFoundException());

		$this->oldBooks = [
			'alice' => $this->oldBook('alice', 1, 4, Book::STATUS_FINISHED),
			'bob' => $this->oldBook('bob', 2, 2, Book::STATUS_READING),
			'carol' => $this->oldBook('carol', 3, null, Book::STATUS_UNREAD),
		];
		$this->library->method('getBook')->willReturn($this->oldBooks['alice']);
		$this->library->method('getFileForUser')->willReturnCallback(fn (string $u, int $id): File => $id === 5 ? $original : $uploaded);
		$this->library->method('isInLibrary')->willReturnCallback(fn (string $u): bool => !in_array($u, $this->notInLibrary, true));
		$this->library->method('indexFile')->willReturnCallback(function (string $u): Book {
			return $this->newBooks[$u] = $this->newBook($u);
		});
		$this->library->method('getTags')->willReturnCallback(function (int $bookId): array {
			$t = new Tag();
			$t->setType(Tag::TYPE_TAG);
			$t->setName('mine-' . $bookId);
			$t->setSource(Tag::SOURCE_APP);
			return [$t];
		});
		$this->books->method('findByFileId')->willReturn(array_values($this->oldBooks));
		$this->books->method('findByUserAndFile')->willReturnCallback(fn (string $u): Book => $this->newBooks[$u]);
		$this->books->method('update')->willReturnArgument(0);

		$this->progress->method('get')->willReturnCallback(function (string $u, int $id): ?Progress {
			if ($u === 'carol') {
				return null;
			}
			$p = new Progress();
			$p->setLocator((string)json_encode(['href' => 'p2.jpg', 'type' => 'image/jpeg', 'locations' => ['position' => 2, 'totalProgression' => 0.5]]));
			$p->setPercentage($u === 'alice' ? 0.5 : 0.3);
			$p->setClientUpdatedAt($u === 'alice' ? 1111 : 2222);
			return $p;
		});
		$this->progress->method('put')->willReturnCallback(function (string $u, int $id, array $locator, float $pct, ?string $device, int $client): array {
			$this->puts[] = [$u, $id, $locator, $pct, $device, $client];
			return ['status' => 'ok'];
		});
		$this->annotations->method('findByUserAndFile')->willReturnCallback(fn (string $u, int $id): array => $id === 5 ? ($this->annotationsOf[$u] ?? []) : []);
		$this->annotations->method('update')->willReturnArgument(0);

		$temp = $this->createMock(ITempManager::class);
		$this->service = new ConvertService(
			$this->library,
			new ArchiveTools([]),
			$this->progress,
			$this->books,
			$temp,
			$this->createMock(LoggerInterface::class),
			new ArchiveCache($temp, $this->createMock(IAppConfig::class), $this->createMock(LoggerInterface::class)),
			$this->createMock(SidecarService::class),
			null,
			null,
			$this->annotations,
		);
	}

	protected function tearDown(): void {
		foreach (glob($this->tmp . '/*') ?: [] as $f) {
			@unlink($f);
		}
		@rmdir($this->tmp);
	}

	private function file(string $name, int $id): File&MockObject {
		$f = $this->createMock(File::class);
		$f->method('getName')->willReturn($name);
		$f->method('getId')->willReturn($id);
		$f->method('getParent')->willReturn($this->folder);
		$f->method('isDeletable')->willReturn(true);
		return $f;
	}

	private function oldBook(string $user, int $rowId, ?int $rating, string $status): Book {
		$b = new Book();
		$b->setId($rowId);
		$b->setUserId($user);
		$b->setFileId(5);
		$b->setFormat('cbr');
		$b->setRating($rating);
		$b->setReadStatus($status);
		$b->setReadStatusManual($user === 'bob');
		$b->setCompletion($user === 'bob' ? Book::COMPLETION_COMPLETED : null);
		return $b;
	}

	private function newBook(string $user): Book {
		$b = new Book();
		$b->setId(100 + strlen($user));
		$b->setUserId($user);
		$b->setFileId(101);
		$b->setFormat('cbz');
		return $b;
	}

	private function annotation(string $uuid, string $href, int $position): Annotation {
		$a = new Annotation();
		$a->setUserId('bob');
		$a->setFileId(5);
		$a->setUuid($uuid);
		$a->setLocator((string)json_encode(['href' => $href, 'locations' => ['position' => $position, 'totalProgression' => 0.1]]));
		$a->setClientUpdatedAt(777);
		$a->setUpdatedAt(1);
		return $a;
	}

	public function testEveryUserWithTheOriginalGetsTheNewBookIndexedAndTheirDataCarriedOver(): void {
		$this->annotationsOf['bob'] = [$this->annotation('11111111-1111-4111-8111-111111111111', 'p1.jpg', 1)];
		$setTags = [];
		$this->library->method('setTags')->willReturnCallback(function (int $bookId, string $type, array $names, string $source) use (&$setTags): void {
			$setTags[] = [$bookId, $names];
		});

		$res = $this->service->adoptClientResult('alice', 5, 'a.cbz', true, ['p1.jpg', 'p2.jpg'], ['0001.jpg', '0002.jpg']);

		$this->assertSame(101, $res['fileId']);
		$this->assertEqualsCanonicalizing(['alice', 'bob', 'carol'], array_keys($this->newBooks), 'the new file is indexed for all users');
		$this->assertSame(Book::STATUS_FINISHED, $this->newBooks['alice']->getReadStatus());
		$this->assertSame(4, $this->newBooks['alice']->getRating());
		$this->assertSame(Book::STATUS_READING, $this->newBooks['bob']->getReadStatus());
		$this->assertTrue($this->newBooks['bob']->getReadStatusManual());
		$this->assertSame(2, $this->newBooks['bob']->getRating());
		$this->assertSame(Book::COMPLETION_COMPLETED, $this->newBooks['bob']->getCompletion());
		$this->assertNull($this->newBooks['carol']->getRating());

		// progress: alice and bob (carol has none), each with their own percentage and clientUpdatedAt
		$byUser = [];
		foreach ($this->puts as $put) {
			$byUser[$put[0]] = $put;
		}
		$this->assertSame(['alice', 'bob'], array_keys($byUser));
		$this->assertSame(101, $byUser['bob'][1]);
		$this->assertSame('0002.jpg', $byUser['bob'][2]['href']);
		$this->assertSame(0.3, $byUser['bob'][3]);
		$this->assertSame(2222, $byUser['bob'][5]);
		$this->assertSame(1111, $byUser['alice'][5]);

		// app tags of each user's own row
		$this->assertCount(3, $setTags);
	}

	public function testAnnotationsMoveToTheNewFileWithTheirUuidAndAMappedLocator(): void {
		$a = $this->annotation('11111111-1111-4111-8111-111111111111', 'p1.jpg', 1);
		$unmapped = $this->annotation('22222222-2222-4222-8222-222222222222', 'gone.jpg', 99);
		$this->annotationsOf['bob'] = [$a, $unmapped];

		$this->service->adoptClientResult('alice', 5, 'a.cbz', true, ['p1.jpg', 'p2.jpg'], ['0001.jpg', '0002.jpg']);

		$this->assertSame(101, $a->getFileId());
		$this->assertSame('11111111-1111-4111-8111-111111111111', $a->getUuid());
		$this->assertSame('0001.jpg', $a->getLocatorArray()['href']);
		$this->assertSame(777, $a->getClientUpdatedAt(), 'nothing was edited by the user');
		$this->assertGreaterThan(1, $a->getUpdatedAt(), '/sync delivers the moved annotation');
		// an annotation whose page is unknown keeps its content and only the overall position
		$this->assertSame(101, $unmapped->getFileId());
		$this->assertSame(['href' => '', 'locations' => ['totalProgression' => 0.1]], $unmapped->getLocatorArray());
	}

	public function testUsersWithoutTheNewFileInTheirLibraryKeepTheirDataOnTheOldFile(): void {
		$this->notInLibrary = ['carol'];
		$a = $this->annotation('11111111-1111-4111-8111-111111111111', 'p1.jpg', 1);
		$this->annotationsOf['carol'] = [$a];

		$this->service->adoptClientResult('alice', 5, 'a.cbz', true, ['p1.jpg', 'p2.jpg'], ['0001.jpg', '0002.jpg']);

		$this->assertArrayNotHasKey('carol', $this->newBooks);
		$this->assertSame(5, $a->getFileId());
	}

	public function testOneUsersFailureDoesNotStopTheOthersNorTheConversion(): void {
		$library = $this->createMock(LibraryService::class);
		$library->method('getBook')->willReturn($this->oldBooks['alice']);
		$library->method('getFileForUser')->willReturnCallback(function (string $u, int $id): File {
			if ($u === 'bob') {
				throw new NotFoundException();
			}
			return $this->file($id === 5 ? 'a.cbr' : 'a.cbz', $id);
		});
		$library->method('isInLibrary')->willReturn(true);
		$library->method('indexFile')->willReturnCallback(fn (string $u): Book => $this->newBooks[$u] = $this->newBook($u));
		$temp = $this->createMock(ITempManager::class);
		$service = new ConvertService($library, new ArchiveTools([]), $this->progress, $this->books, $temp, $this->createMock(LoggerInterface::class), new ArchiveCache($temp, $this->createMock(IAppConfig::class), $this->createMock(LoggerInterface::class)), $this->createMock(SidecarService::class), null, null, $this->annotations);

		$service->adoptClientResult('alice', 5, 'a.cbz', false, ['p1.jpg', 'p2.jpg'], ['0001.jpg', '0002.jpg']);

		$this->assertEqualsCanonicalizing(['alice', 'carol'], array_keys($this->newBooks));
	}
}
