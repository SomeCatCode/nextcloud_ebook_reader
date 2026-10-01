<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DoctrineStubs.php';

final class FakeComposite implements ICompositeExpression {
	/** @param list<mixed> $parts */
	public function __construct(
		private string $type,
		private array $parts,
	) {
	}

	public function addMultiple(array $parts = []): ICompositeExpression {
		$this->parts = array_merge($this->parts, $parts);
		return $this;
	}

	public function add($part): ICompositeExpression {
		$this->parts[] = $part;
		return $this;
	}

	public function count(): int {
		return count($this->parts);
	}

	public function getType(): string {
		return $this->type;
	}

	public function __toString(): string {
		return '(' . implode(' ' . $this->type . ' ', array_map('strval', $this->parts)) . ')';
	}
}

final class FakeFunction implements IQueryFunction {
	public function __construct(
		private string $sql,
	) {
	}

	public function __toString(): string {
		return $this->sql;
	}
}

/**
 * Query builder stand-in that renders conditions as readable strings, e.g. `iLike(t.name,'Fantasy')`.
 */
final class FakeQueryBuilder {
	/** @return IQueryBuilder&\PHPUnit\Framework\MockObject\MockObject */
	public static function create(TestCase $test): IQueryBuilder {
		$expr = (new MockBuilder($test, IExpressionBuilder::class))->getMock();
		$str = static fn ($v): string => (string)$v;
		$expr->method('eq')->willReturnCallback(static fn ($x, $y): string => 'eq(' . $str($x) . ',' . $str($y) . ')');
		$expr->method('neq')->willReturnCallback(static fn ($x, $y): string => 'neq(' . $str($x) . ',' . $str($y) . ')');
		$expr->method('iLike')->willReturnCallback(static fn ($x, $y): string => 'iLike(' . $str($x) . ',' . $str($y) . ')');
		$expr->method('in')->willReturnCallback(static fn ($x, $y): string => 'in(' . $str($x) . ',' . $str($y) . ')');
		$expr->method('notIn')->willReturnCallback(static fn ($x, $y): string => 'notIn(' . $str($x) . ',' . $str($y) . ')');
		$expr->method('isNull')->willReturnCallback(static fn ($x): string => 'isNull(' . $str($x) . ')');
		$expr->method('isNotNull')->willReturnCallback(static fn ($x): string => 'isNotNull(' . $str($x) . ')');
		$expr->method('andX')->willReturnCallback(static fn (...$x): ICompositeExpression => new FakeComposite('AND', $x));
		$expr->method('orX')->willReturnCallback(static fn (...$x): ICompositeExpression => new FakeComposite('OR', $x));

		$where = [];
		$qb = (new MockBuilder($test, IQueryBuilder::class))->getMock();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($v): string => is_array($v) ? '[' . implode(',', $v) . ']' : "'" . $str($v) . "'");
		$qb->method('createFunction')->willReturnCallback(static fn (string $sql): IQueryFunction => new FakeFunction($sql));
		foreach (['select', 'from'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('where')->willReturnCallback(static function ($c) use ($qb, &$where, $str): IQueryBuilder {
			$where[] = $str($c);
			return $qb;
		});
		$qb->method('andWhere')->willReturnCallback(static function ($c) use ($qb, &$where, $str): IQueryBuilder {
			$where[] = $str($c);
			return $qb;
		});
		$qb->method('getSQL')->willReturnCallback(static function () use (&$where): string {
			return 'SELECT ... WHERE ' . implode(' AND ', $where);
		});
		return $qb;
	}
}
