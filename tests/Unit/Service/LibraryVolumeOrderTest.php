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
use OCA\EbookReader\Metadata\BookMetadata;
use OCA\EbookReader\Metadata\MetadataService;
use OCA\EbookReader\Metadata\SidecarService;
use OCA\EbookReader\Service\BookQuery;
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
 * Natural volume order: the series index parsed at indexing time and the SQL sort of the series volumes.
 */
class LibraryVolumeOrderTest extends TestCase {
	private BookMapper&MockObject $books;
	private MetadataService&MockObject $metadata;
	private LibraryService $service;
	/** @var list<array{string, string}> */
	private array $orderBy = [];

	protected function setUp(): void {
		$this->orderBy = [];
		$this->books = $this->createMock(BookMapper::class);
		$tags = $this->createMock(TagMapper::class);
		$tags->method('findByBook')->willReturn([]);
		$this->metadata = $this->createMock(MetadataService::class);
		$this->metadata->method('detectFormat')->willReturn('cbz');
		$settings = $this->createMock(SettingsService::class);
		$settings->method('get')->willReturn(['libraryFolders' => ['/Books'], 'reader' => [], 'filenamePattern' => '', 'genreList' => []]);
		$root = $this->createMock(IRootFolder::class);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->willReturn('/Books/Saga 02.cbz');
		$root->method('getUserFolder')->willReturn($userFolder);

		$this->service = new LibraryService(
			$this->books,
			$tags,
			$this->metadata,
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
		$result->method('fetchAll')->willReturn([]);
		$result->method('fetchOne')->willReturn(false);
		$result->method('fetch')->willReturn(false);
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'gt', 'isNull'] as $op) {
			$expr->method($op)->willReturn('1 = 1');
		}
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($result, $expr): IQueryBuilder {
			$qb = $this->createMock(IQueryBuilder::class);
			foreach (['select', 'selectDistinct', 'selectAlias', 'from', 'where', 'andWhere', 'innerJoin', 'groupBy', 'setMaxResults'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn(':p');
			$qb->method('createFunction')->willReturnCallback(static fn (string $f) => $f);
			$qb->method('executeQuery')->willReturn($result);
			$qb->method('orderBy')->willReturnCallback(function ($sort, $dir = 'ASC') use ($qb): IQueryBuilder {
				$this->orderBy = [[(string)$sort, (string)$dir]];
				return $qb;
			});
			$qb->method('addOrderBy')->willReturnCallback(function ($sort, $dir = 'ASC') use ($qb): IQueryBuilder {
				$this->orderBy[] = [(string)$sort, (string)$dir];
				return $qb;
			});
			return $qb;
		});
		return $db;
	}

	private function file(string $name): File&MockObject {
		$f = $this->createMock(File::class);
		$f->method('getId')->willReturn(5);
		$f->method('getName')->willReturn($name);
		$f->method('getPath')->willReturn('/bob/files/Books/' . $name);
		$f->method('getMimeType')->willReturn('application/vnd.comicbook+zip');
		$f->method('getMTime')->willReturn(200);
		$f->method('getEtag')->willReturn('new');
		$f->method('getSize')->willReturn(100);
		return $f;
	}

	private function index(BookMetadata $meta, ?Book $existing = null): Book {
		if ($existing === null) {
			$this->books->method('findByUserAndFile')->willThrowException(new DoesNotExistException('none'));
		} else {
			$this->books->method('findByUserAndFile')->willReturn($existing);
		}
		$this->books->method('insert')->willReturnCallback(static function (Book $b): Book {
			$b->setId(2);
			return $b;
		});
		$this->books->method('update')->willReturnArgument(0);
		$this->metadata->method('extract')->willReturn($meta);
		$book = $this->service->indexFile('bob', $this->file('Saga 02.cbz'), true);
		$this->assertNotNull($book);
		return $book;
	}

	public function testIndexingTakesTheVolumeNumberFromTheTitleWhenTheMetadataHasNone(): void {
		$book = $this->index(new BookMetadata(title: 'Saga Vol. 10', series: 'Saga'));
		$this->assertSame(10.0, $book->getSeriesIndex());
		$this->assertTrue($book->getSeriesIndexDerived());
	}

	public function testIndexingFallsBackToTheFileName(): void {
		$book = $this->index(new BookMetadata(title: 'The Beginning', series: 'Saga'));
		$this->assertSame(2.0, $book->getSeriesIndex());
		$this->assertTrue($book->getSeriesIndexDerived());
	}

	public function testMetadataIndexWins(): void {
		$book = $this->index(new BookMetadata(title: 'Saga 10', series: 'Saga', seriesIndex: 3.5));
		$this->assertSame(3.5, $book->getSeriesIndex());
		$this->assertFalse($book->getSeriesIndexDerived());
	}

	public function testNoSeriesNoDerivedIndex(): void {
		$book = $this->index(new BookMetadata(title: 'Saga 10'));
		$this->assertNull($book->getSeriesIndex());
		$this->assertFalse($book->getSeriesIndexDerived());
	}

	public function testAnIndexSetByHandWinsAndClearsTheDerivedMark(): void {
		$book = new Book();
		$book->setSeriesIndex(7.0);
		$book->setSeriesIndexDerived(true);
		$book->setSeriesIndex(8.0);
		$this->assertFalse($book->getSeriesIndexDerived());
	}

	public function testSeriesSortOrdersByNumberThenNaturalTitle(): void {
		$m = new \ReflectionMethod(LibraryService::class, 'applySort');
		$qb = $this->db()->getQueryBuilder();
		$m->invoke($this->service, $qb, 'bob', BookQuery::fromRequestParams(['sort' => 'series', 'order' => 'asc']));
		$sql = implode(' | ', array_map(static fn (array $o): string => $o[0] . ' ' . $o[1], $this->orderBy));
		$this->assertStringContainsString("LOWER(COALESCE(b.series, '')) ASC", $sql);
		$this->assertMatchesRegularExpression('/b\.series_index IS NULL THEN 1 ELSE 0 END ASC \| b\.series_index ASC \| COALESCE\(b\.title_sort, [^|]+ ASC \| b\.id ASC/', $sql);
	}

	public function testDescendingSeriesSortKeepsVolumesWithoutNumberLast(): void {
		$m = new \ReflectionMethod(LibraryService::class, 'applySort');
		$qb = $this->db()->getQueryBuilder();
		$m->invoke($this->service, $qb, 'bob', BookQuery::fromRequestParams(['sort' => 'series', 'order' => 'desc']));
		$sql = implode(' | ', array_map(static fn (array $o): string => $o[0] . ' ' . $o[1], $this->orderBy));
		$this->assertStringContainsString('b.series_index IS NULL THEN 1 ELSE 0 END ASC | b.series_index DESC', $sql);
	}

	public function testTitleSortUsesTheNaturalKeyColumn(): void {
		$m = new \ReflectionMethod(LibraryService::class, 'applySort');
		$qb = $this->db()->getQueryBuilder();
		$m->invoke($this->service, $qb, 'bob', BookQuery::fromRequestParams(['sort' => 'title']));
		$this->assertStringContainsString('COALESCE(b.title_sort,', $this->orderBy[0][0]);
	}
}
