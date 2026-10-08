<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Controller\BooksController;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\TagMapper;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Metadata\MetadataService;
use OCA\EbookReader\Metadata\SidecarService;
use OCA\EbookReader\Service\BookQuery;
use OCA\EbookReader\Service\CoverService;
use OCA\EbookReader\Service\GenreClassifier;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\ProgressService;
use OCA\EbookReader\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/OcHooksEmitterStub.php';
require_once __DIR__ . '/FakeQueryBuilder.php';

/** The `folder` / `folderRecursive` filter of the book list: parsing, SQL and the controller hand-over. */
class FolderFilterTest extends TestCase {
	private LibraryService $service;

	protected function setUp(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => FakeQueryBuilder::create($this));
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $s): string => addcslashes($s, '%_\\'));
		$settings = $this->createMock(SettingsService::class);
		$tags = $this->createMock(TagMapper::class);
		$this->service = new LibraryService(
			$this->createMock(BookMapper::class),
			$tags,
			$this->createMock(MetadataService::class),
			$this->createMock(CoverService::class),
			new GenreClassifier($settings, $tags),
			$settings,
			$this->createMock(IRootFolder::class),
			$this->createMock(IJobList::class),
			$db,
			$this->createMock(LoggerInterface::class),
			$this->createMock(SidecarService::class),
		);
	}

	/** @return list<string> */
	private function conditions(BookQuery $q): array {
		$m = new \ReflectionMethod(LibraryService::class, 'filterConditions');
		/** @var list<string> $out */
		$out = $m->invoke($this->service, FakeQueryBuilder::create($this), 'u', $q, true);
		return $out;
	}

	/** @return array<string, array{mixed, ?string}> */
	public static function folderProvider(): array {
		return [
			'plain' => ['/Books/Saga', '/Books/Saga'],
			'no leading slash' => ['Books/Saga', '/Books/Saga'],
			'trailing slash' => ['/Books/Saga/', '/Books/Saga'],
			'backslashes and doubled slashes' => ['\\Books\\\\Saga//', '/Books/Saga'],
			'dot segments' => ['/Books/./Saga', '/Books/Saga'],
			'root' => ['/', '/'],
			'parent segment' => ['/Books/../Secret', null],
			'empty' => ['', null],
			'blank' => ['  ', null],
			'not a string' => [['/Books'], null],
			'control character' => ["/Books/a\x00b", null],
		];
	}

	/** @dataProvider folderProvider */
	#[\PHPUnit\Framework\Attributes\DataProvider('folderProvider')]
	public function testFolderIsNormalised(mixed $raw, ?string $expected): void {
		$this->assertSame($expected, BookQuery::fromRequestParams(['folder' => $raw])->folder);
	}

	public function testRecursiveFlagAcceptsOneAndTrue(): void {
		foreach ([1, '1', true, 'true'] as $v) {
			$this->assertTrue(BookQuery::fromRequestParams(['folder' => '/B', 'folderRecursive' => $v])->folderRecursive);
		}
		foreach ([0, '0', false, 'false', null, 'yes'] as $v) {
			$this->assertFalse(BookQuery::fromRequestParams(['folder' => '/B', 'folderRecursive' => $v])->folderRecursive);
		}
		$this->assertFalse(BookQuery::fromRequestParams([])->folderRecursive);
		$this->assertNull(BookQuery::fromRequestParams([])->folder);
	}

	public function testNoFolderNoCondition(): void {
		$this->assertSame([], $this->conditions(BookQuery::fromRequestParams([])));
	}

	public function testDirectChildrenOnly(): void {
		$c = $this->conditions(BookQuery::fromRequestParams(['folder' => '/Books/Comics/Saga']));
		$this->assertSame(["like(b.path,'/Books/Comics/Saga/%')", "notLike(b.path,'/Books/Comics/Saga/%/%')"], $c);
	}

	public function testRecursiveHasNoExclusionOfSubfolders(): void {
		$c = $this->conditions(BookQuery::fromRequestParams(['folder' => '/Books/Comics/Saga', 'folderRecursive' => 1]));
		$this->assertSame(["like(b.path,'/Books/Comics/Saga/%')"], $c);
	}

	public function testRootFolder(): void {
		$this->assertSame(["like(b.path,'/%')", "notLike(b.path,'/%/%')"], $this->conditions(BookQuery::fromRequestParams(['folder' => '/'])));
		$this->assertSame(["like(b.path,'/%')"], $this->conditions(BookQuery::fromRequestParams(['folder' => '/', 'folderRecursive' => true])));
	}

	public function testLikeWildcardsInFolderNamesAreEscaped(): void {
		$c = $this->conditions(BookQuery::fromRequestParams(['folder' => '/100%_Fun']));
		$this->assertSame("like(b.path,'/100\\%\\_Fun/%')", $c[0]);
		$this->assertSame("notLike(b.path,'/100\\%\\_Fun/%/%')", $c[1]);
	}

	public function testCombinesWithOtherFilters(): void {
		$c = $this->conditions(BookQuery::fromRequestParams(['folder' => '/A', 'status' => 'unread', 'include' => ['format:cbz']]));
		$this->assertContains("eq(b.read_status,'unread')", $c);
		$this->assertContains("like(b.path,'/A/%')", $c);
		$this->assertCount(4, $c);
	}

	public function testControllerPassesTheFolderParameters(): void {
		$library = $this->createMock(LibraryService::class);
		$captured = null;
		$library->method('findBooks')->willReturnCallback(function (string $u, BookQuery $q) use (&$captured): array {
			$captured = $q;
			return ['books' => [], 'total' => 0];
		});
		$serializer = $this->createMock(BookSerializer::class);
		$serializer->method('serializeMany')->willReturn([]);
		$controller = new BooksController($this->createMock(IRequest::class), 'u', $library, $this->createMock(BookMapper::class), $serializer, $this->createMock(ITimeFactory::class), $this->createMock(ProgressService::class));
		$controller->index(folder: '/Books/Saga/', folderRecursive: true);
		$this->assertSame('/Books/Saga', $captured?->folder);
		$this->assertTrue($captured->folderRecursive);
		$controller->index();
		$this->assertNull($captured->folder);
		$this->assertFalse($captured->folderRecursive);
	}
}
