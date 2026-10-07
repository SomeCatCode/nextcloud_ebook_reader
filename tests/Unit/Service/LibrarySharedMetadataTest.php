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
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/OcHooksEmitterStub.php';
require_once __DIR__ . '/DoctrineStubs.php';

/**
 * Metadata of a book the app shared with a user: the owner's values, but never the owner's app-only tags, and never
 * on top of what the recipient edited himself.
 */
class LibrarySharedMetadataTest extends TestCase {
	private BookMapper&MockObject $books;
	private TagMapper&MockObject $tags;
	private MetadataService&MockObject $metadata;
	private LibraryService $service;
	/** @var list<Tag> */
	private array $inserted = [];

	protected function setUp(): void {
		$this->books = $this->createMock(BookMapper::class);
		$this->tags = $this->createMock(TagMapper::class);
		$this->metadata = $this->createMock(MetadataService::class);
		$this->metadata->method('detectFormat')->willReturn('epub');
		$settings = $this->createMock(SettingsService::class);
		$settings->method('get')->willReturn(['libraryFolders' => ['/Books'], 'reader' => [], 'filenamePattern' => '', 'genreList' => []]);
		$this->tags->method('insert')->willReturnCallback(function (Tag $t): Tag {
			$this->inserted[] = $t;
			return $t;
		});
		$root = $this->createMock(IRootFolder::class);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->willReturn('/Shared/x.epub');
		$root->method('getUserFolder')->willReturn($userFolder);

		$this->service = new LibraryService(
			$this->books,
			$this->tags,
			$this->metadata,
			$this->createMock(CoverService::class),
			new GenreClassifier($settings, $this->tags),
			$settings,
			$root,
			$this->createMock(IJobList::class),
			$this->db(['alice']),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SidecarService::class),
		);
	}

	/** @param list<string> $owners result of every query: the users who shared the file */
	private function db(array $owners): IDBConnection {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($owners);
		$result->method('fetchOne')->willReturn(false);
		$result->method('fetch')->willReturn(false);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('1 = 1');
		$expr->method('neq')->willReturn('1 = 1');
		$expr->method('gt')->willReturn('1 = 1');
		$expr->method('isNull')->willReturn('1 = 1');
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

	private function file(): File&MockObject {
		$f = $this->createMock(File::class);
		$f->method('getId')->willReturn(5);
		$f->method('getName')->willReturn('x.epub');
		$f->method('getPath')->willReturn('/bob/files/Shared/x.epub');
		$f->method('getMimeType')->willReturn('application/epub+zip');
		$f->method('getMTime')->willReturn(200);
		$f->method('getEtag')->willReturn('new');
		$f->method('getSize')->willReturn(100);
		return $f;
	}

	private function tag(string $type, string $name, string $source): Tag {
		$t = new Tag();
		$t->setType($type);
		$t->setName($name);
		$t->setSource($source);
		return $t;
	}

	private function ownerBook(): Book {
		$b = new Book();
		$b->setId(1);
		$b->setUserId('alice');
		$b->setFileId(5);
		$b->setTitle('Owner title');
		$b->setAuthorsArray(['Owner author']);
		$b->setSeries('Owner series');
		$b->setDescription('Owner description');
		return $b;
	}

	public function testRecipientOverridesWinOverTheOwnersMetadata(): void {
		$mine = new Book();
		$mine->setId(2);
		$mine->setUserId('bob');
		$mine->setFileId(5);
		$mine->setTitle('My title');
		$mine->setSeries('My series');
		$mine->setOverridesArray(['title', 'series']);
		$mine->setFileMtime(1);
		$mine->setFileEtag('old');
		$this->books->method('findByUserAndFile')->willReturnCallback(fn (string $u): Book => $u === 'alice' ? $this->ownerBook() : $mine);
		$this->books->method('update')->willReturnArgument(0);
		$this->tags->method('findByBook')->willReturn([]);
		$this->metadata->method('extract')->willReturn(new BookMetadata(title: 'File title'));

		$book = $this->service->indexFile('bob', $this->file(), true);
		$this->assertNotNull($book);
		$this->assertSame('My title', $book->getTitle());
		$this->assertSame('My series', $book->getSeries());
		$this->assertSame('Owner description', $book->getDescription(), 'fields without an override follow the owner');
		$this->assertSame(['Owner author'], $book->getAuthorsArray());
		$this->assertSame(['title', 'series'], $book->getOverridesArray());
	}

	public function testTheOwnersAppOnlyTagsAreNotCopiedToTheRecipient(): void {
		$this->books->method('findByUserAndFile')->willReturnCallback(function (string $u): Book {
			if ($u === 'alice') {
				return $this->ownerBook();
			}
			throw new DoesNotExistException('none');
		});
		$this->books->method('insert')->willReturnCallback(static function (Book $b): Book {
			$b->setId(2);
			return $b;
		});
		$this->tags->method('findByBook')->willReturnCallback(fn (int $id): array => $id === 1 ? [
			$this->tag(Tag::TYPE_GENRE, 'Fantasy', Tag::SOURCE_FILE),
			$this->tag(Tag::TYPE_TAG, 'public', Tag::SOURCE_FILE),
			$this->tag(Tag::TYPE_TAG, 'my secret shelf', Tag::SOURCE_APP),
			$this->tag(Tag::TYPE_GENRE, 'my private genre', Tag::SOURCE_APP),
		] : []);
		$this->metadata->method('extract')->willReturn(new BookMetadata(title: 'File title'));

		$this->service->indexFile('bob', $this->file(), true);
		$names = array_map(static fn (Tag $t): string => $t->getName(), $this->inserted);
		$this->assertContains('public', $names);
		$this->assertContains('Fantasy', $names);
		$this->assertNotContains('my secret shelf', $names);
		$this->assertNotContains('my private genre', $names);
	}

	public function testIsSharedWithUserFollowsTheShareRows(): void {
		$this->assertTrue($this->service->isSharedWithUser('bob', 5));
	}

	public function testReindexForAllUsersCanSkipTheUserWhoJustSavedTheRow(): void {
		$a = new Book();
		$a->setUserId('alice');
		$b = new Book();
		$b->setUserId('bob');
		$this->books->method('findByFileId')->with(5)->willReturn([$a, $b]);
		$this->books->method('findByUserAndFile')->willThrowException(new DoesNotExistException('none'));
		$seen = [];
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(static function (string $u) use (&$seen): Folder {
			$seen[] = $u;
			throw new \OCP\Files\NotFoundException();
		});
		$service = new LibraryService(
			$this->books,
			$this->tags,
			$this->metadata,
			$this->createMock(CoverService::class),
			new GenreClassifier($this->createMock(SettingsService::class), $this->tags),
			$this->createMock(SettingsService::class),
			$root,
			$this->createMock(IJobList::class),
			$this->db([]),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SidecarService::class),
		);
		$service->reindexFileForAllUsers(5, 'alice');
		$this->assertNotContains('alice', $seen);
		$this->assertContains('bob', $seen);
	}
}
