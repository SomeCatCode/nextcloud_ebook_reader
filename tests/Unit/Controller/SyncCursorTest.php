<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Http;

use OCA\EbookReader\Http\SyncCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SyncCursorTest extends TestCase {
	private static function enc(string $json): string {
		return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
	}

	public function testRoundTrip(): void {
		$c = new SyncCursor([1800000000123, 42], [5, 6]);
		$d = SyncCursor::decode($c->encode());
		$this->assertSame([1800000000123, 42], $d->books);
		$this->assertSame([5, 6], $d->progress);
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $c->encode());
	}

	public function testRoundTripWithAnnotations(): void {
		$d = SyncCursor::decode((new SyncCursor([1, 2], [3, 4], [5, 6]))->encode());
		$this->assertSame([5, 6], $d->annotations);
	}

	public function testCursorWithoutAnnotationPositionStartsAnnotationsFromTheBeginning(): void {
		$d = SyncCursor::decode(self::enc('{"b":[1,2],"p":[3,4]}'));
		$this->assertSame([0, 0], $d->annotations);
		$this->assertSame([3, 4], $d->progress);
	}

	public function testEmptyIsStart(): void {
		$this->assertSame([0, 0], SyncCursor::decode('')->books);
		$this->assertSame([0, 0], SyncCursor::decode(null)->progress);
	}

	#[DataProvider('badProvider')]
	public function testInvalid(string $cursor): void {
		$this->expectException(\InvalidArgumentException::class);
		SyncCursor::decode($cursor);
	}

	public static function badProvider(): array {
		return [
			'garbage' => ['!!!'],
			'not json' => [self::enc('nope')],
			'missing p' => [self::enc('{"b":[1,2]}')],
			'strings' => [self::enc('{"b":["1","2"],"p":[1,2]}')],
			'negative' => [self::enc('{"b":[-1,2],"p":[1,2]}')],
			'bad annotation pair' => [self::enc('{"b":[1,2],"p":[1,2],"a":["x",1]}')],
			'too long' => [str_repeat('A', 300)],
		];
	}
}
