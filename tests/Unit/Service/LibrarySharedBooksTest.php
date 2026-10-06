<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/OcHooksEmitterStub.php';
require_once __DIR__ . '/DoctrineStubs.php';

/**
 * Books the app shared with a user belong to their library wherever Nextcloud mounted the share (share folder),
 * even outside the library folders.
 */
class LibrarySharedBooksTest extends TestCase {
	private LibraryService $service;
	private File $shared;
	private File $other;

	protected function setUp(): void {
		$this->shared = $this->file(5, 'Shared Book.epub', '/bob/files/Shared Book.epub');
		$this->other = $this->file(6, 'Other.epub', '/bob/files/Other.epub');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->willReturnCallback(static fn (string $p): ?string => str_starts_with($p, '/bob/files') ? substr($p, strlen('/bob/files')) : null);
		$userFolder->method('get')->willThrowException(new NotFoundException());
		$userFolder->method('getFirstNodeById')->willReturnCallback(fn (int $id): ?File => $id === 5 ? $this->shared : null);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('get')->willReturn(['libraryFolders' => ['/Books'], 'reader' => [], 'filenamePattern' => '', 'genreList' => []]);
		$metadata = $this->createMock(MetadataService::class);
		$metadata->method('detectFormat')->willReturn('epub');
		$tags = $this->createMock(TagMapper::class);

		$this->service = new LibraryService(
			$this->createMock(BookMapper::class),
			$tags,
			$metadata,
			$this->createMock(CoverService::class),
			new GenreClassifier($settings, $tags),
			$settings,
			$root,
			$this->createMock(IJobList::class),
			$this->db([5]),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SidecarService::class),
		);
	}

	private function file(int $id, string $name, string $path): File {
		$f = $this->createMock(File::class);
		$f->method('getId')->willReturn($id);
		$f->method('getName')->willReturn($name);
		$f->method('getPath')->willReturn($path);
		$f->method('getMimeType')->willReturn('application/epub+zip');
		return $f;
	}

	/** @param list<int> $sharedIds file ids returned by every query (the shared-file lookup) */
	private function db(array $sharedIds): IDBConnection {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($sharedIds);
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

	public function testSharedFileOutsideTheLibraryFoldersBelongsToTheLibrary(): void {
		$this->assertSame([5], $this->service->sharedFileIds('bob'));
		$this->assertTrue($this->service->isInLibrary('bob', $this->shared));
		$this->assertFalse($this->service->isInLibrary('bob', $this->other));
	}

	public function testWalkVisitsSharedBooks(): void {
		$seen = [];
		$complete = $this->service->walkLibrary('bob', static function (File $f, string $format) use (&$seen): void {
			$seen[] = [$f->getId(), $format];
		});
		$this->assertTrue($complete);
		$this->assertSame([[5, 'epub']], $seen);
	}
}
