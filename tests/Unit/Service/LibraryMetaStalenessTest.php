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
use OCA\EbookReader\Metadata\MetadataService;
use OCA\EbookReader\Metadata\SidecarService;
use OCA\EbookReader\Service\CoverService;
use OCA\EbookReader\Service\GenreClassifier;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\SettingsService;
use OCP\BackgroundJob\IJobList;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
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
 * A book shared through the app is stale for the recipient when the owner changed its descriptive data (meta_updated_at),
 * not when the owner rated it, changed its status or read it (updated_at).
 */
class LibraryMetaStalenessTest extends TestCase {
	private LibraryService $service;
	private BookMapper&MockObject $books;
	/** @var list<string> every comparison the staleness queries built ("column > :value") */
	private array $comparisons = [];
	/** @var list<string> arguments of max() */
	private array $maxColumns = [];

	protected function setUp(): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->willReturnCallback(static fn (string $p): ?string => str_starts_with($p, '/bob/files') ? substr($p, strlen('/bob/files')) : null);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('get')->willReturn(['libraryFolders' => ['/Books'], 'reader' => [], 'filenamePattern' => '', 'genreList' => []]);
		$metadata = $this->createMock(MetadataService::class);
		$metadata->method('detectFormat')->willReturn('epub');
		$tags = $this->createMock(TagMapper::class);
		$this->books = $this->createMock(BookMapper::class);
		$this->service = new LibraryService(
			$this->books,
			$tags,
			$metadata,
			$this->createMock(CoverService::class),
			new GenreClassifier($settings, $tags),
			$settings,
			$root,
			$this->createMock(IJobList::class),
			$this->db(),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SidecarService::class),
		);
	}

	private function db(): IDBConnection {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn(false);
		$result->method('fetch')->willReturn(false);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('1 = 1');
		$expr->method('neq')->willReturn('1 = 1');
		$expr->method('isNull')->willReturn('1 = 1');
		$expr->method('gt')->willReturnCallback(function (string $col, string $param): string {
			$this->comparisons[] = $col . ' > ' . $param;
			return '1 = 1';
		});
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('max')->willReturnCallback(function (string $col): IQueryFunction {
			$this->maxColumns[] = $col;
			return $this->createMock(IQueryFunction::class);
		});
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($result, $expr, $func): IQueryBuilder {
			$qb = $this->createMock(IQueryBuilder::class);
			foreach (['select', 'selectDistinct', 'selectAlias', 'from', 'where', 'andWhere', 'innerJoin', 'groupBy', 'setMaxResults'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$qb->method('expr')->willReturn($expr);
			$qb->method('func')->willReturn($func);
			$qb->method('createNamedParameter')->willReturnCallback(static fn (mixed $v): string => 'p' . (is_scalar($v) ? (string)$v : ''));
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		});
		return $db;
	}

	private function recipientRow(): Book {
		$b = new Book();
		$b->setId(9);
		$b->setUserId('bob');
		$b->setFileId(5);
		$b->setFormat('epub');
		$b->setPath('/Shared Book.epub');
		$b->setFileMtime(10);
		$b->setFileEtag('etag');
		// the recipient's copy was indexed at 100; the owner's rating/status changes since moved updated_at, not meta_updated_at
		$b->setUpdatedAt(500);
		$b->setMetaUpdatedAt(100);
		return $b;
	}

	private function file(): File {
		$f = $this->createMock(File::class);
		$f->method('getId')->willReturn(5);
		$f->method('getName')->willReturn('Shared Book.epub');
		$f->method('getPath')->willReturn('/bob/files/Shared Book.epub');
		$f->method('getMimeType')->willReturn('application/epub+zip');
		$f->method('getMTime')->willReturn(10);
		$f->method('getEtag')->willReturn('etag');
		return $f;
	}

	public function testRecipientIsComparedByTheDescriptiveTimestampNotByUpdatedAt(): void {
		$row = $this->recipientRow();
		$this->books->method('findByUserAndFile')->willReturn($row);
		$this->books->expects($this->never())->method('update');

		$book = $this->service->indexFile('bob', $this->file());

		$this->assertSame($row, $book, 'nothing changed: no reindex');
		$this->assertSame(['ob.meta_updated_at > p100'], $this->comparisons, 'the owner row is compared with the recipient\'s own descriptive timestamp');
	}

	public function testScanComparesTheNewestDescriptiveStampOfTheOwners(): void {
		$m = new \ReflectionMethod($this->service, 'sharedOwnerStamps');
		$m->invoke($this->service, 'bob');
		$this->assertSame(['ob.meta_updated_at'], $this->maxColumns);
	}

	public function testRatingStatusAndProgressDoNotCountAsDescriptiveChanges(): void {
		$row = Book::fromRow(['id' => 1, 'user_id' => 'owner', 'file_id' => 5, 'title' => 'T', 'rating' => null, 'read_status' => 'unread', 'updated_at' => 1, 'meta_updated_at' => 1]);
		$this->assertFalse($row->hasPendingMetaChange());
		$row->setRating(5);
		$row->setReadStatus(Book::STATUS_FINISHED);
		$row->setReadStatusManual(true);
		$row->setCompletion(Book::COMPLETION_COMPLETED);
		$row->setManualAgeRating(12);
		$row->setUpdatedAt(999);
		$this->assertFalse($row->hasPendingMetaChange(), 'owner activity does not mark the recipient stale');
	}

	public function testMetadataCoverAndFileChangesCountAsDescriptiveChanges(): void {
		foreach ([
			'title' => static fn (Book $b) => $b->setTitle('New'),
			'description' => static fn (Book $b) => $b->setDescription('d'),
			'cover' => static fn (Book $b) => $b->setCoverEtag('c2'),
			'overrides' => static fn (Book $b) => $b->setOverridesArray(['title']),
			'file' => static fn (Book $b) => $b->setFileEtag('e2'),
		] as $what => $change) {
			$row = Book::fromRow(['id' => 1, 'user_id' => 'owner', 'file_id' => 5, 'title' => 'T', 'updated_at' => 1, 'meta_updated_at' => 1]);
			$change($row);
			$this->assertTrue($row->hasPendingMetaChange(), $what);
		}
	}
}
