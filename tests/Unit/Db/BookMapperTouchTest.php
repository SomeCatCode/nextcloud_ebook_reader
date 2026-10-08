<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Db;

use OCA\EbookReader\Db\BookMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Service/DoctrineStubs.php';

/**
 * BookMapper::touch() / touchBelow(): one batched UPDATE of updated_at (milliseconds) per chunk.
 */
class BookMapperTouchTest extends TestCase {
	/** @var list<array{set: array<string, string>, where: list<string>}> */
	private array $updates = [];
	private BookMapper $mapper;

	protected function setUp(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $s): string => addcslashes($s, '%_\\'));
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());
		$this->mapper = new BookMapper($db);
	}

	private function builder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'in', 'like'] as $op) {
			$expr->method($op)->willReturnCallback(static fn ($a, $b): string => $op . '(' . $a . ',' . $b . ')');
		}
		$expr->method('isNull')->willReturnCallback(static fn ($a): string => 'isNull(' . $a . ')');
		$idx = count($this->updates);
		$this->updates[$idx] = ['set' => [], 'where' => []];
		/** @var IQueryBuilder&MockObject $qb */
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($v): string => is_array($v) ? '[' . implode(',', $v) . ']' : (string)$v);
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnCallback(function (string $k, $v) use ($qb, $idx): IQueryBuilder {
			$this->updates[$idx]['set'][$k] = (string)$v;
			return $qb;
		});
		foreach (['where', 'andWhere'] as $m) {
			$qb->method($m)->willReturnCallback(function ($c) use ($qb, $idx): IQueryBuilder {
				$this->updates[$idx]['where'][] = (string)$c;
				return $qb;
			});
		}
		$qb->method('executeStatement')->willReturn(2);
		return $qb;
	}

	public function testTouchSetsUpdatedAtInMillisecondsOfTheOwnersFilesOnly(): void {
		$this->assertSame(2, $this->mapper->touch('alice', [3, 1, 3], 1800000000123));
		$this->assertCount(1, $this->updates);
		$this->assertSame(['updated_at' => '1800000000123'], $this->updates[0]['set']);
		$this->assertSame(['eq(user_id,alice)', 'in(file_id,[3,1])'], $this->updates[0]['where']);
	}

	public function testTouchChunksLargeInLists(): void {
		$this->mapper->touch('alice', range(1, 1201), 5);
		$this->assertCount(3, $this->updates);
	}

	public function testTouchBelowEscapesTheFolderPathInTheLike(): void {
		$this->mapper->touchBelow('alice', '/Books/100%_Sci/', 7);
		$this->assertSame(['updated_at' => '7'], $this->updates[0]['set']);
		$this->assertContains('like(path,/Books/100\%\_Sci/%)', $this->updates[0]['where']);
		$this->assertContains('eq(user_id,alice)', $this->updates[0]['where']);
	}
}
