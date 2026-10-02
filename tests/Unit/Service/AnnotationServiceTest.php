<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Db\Annotation;
use OCA\EbookReader\Db\AnnotationMapper;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\ProgressMapper;
use OCA\EbookReader\Service\AnnotationService;
use OCA\EbookReader\Service\ProgressService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AnnotationServiceTest extends TestCase {
	private const NOW_MS = 1_800_000_000_123;
	private const UUID = '3f2b8c1e-5a4d-4e6f-9a7b-1c2d3e4f5a6b';

	private AnnotationMapper&MockObject $mapper;
	private AnnotationService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(AnnotationMapper::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(\DateTimeImmutable::createFromFormat('U.u', '1800000000.123000'));
		$progress = new ProgressService($this->createMock(ProgressMapper::class), $this->createMock(BookMapper::class), $time);
		$this->service = new AnnotationService($this->mapper, $progress, $time);
	}

	/** @return array<string, mixed> */
	private function locator(): array {
		return ['href' => 'ch1.xhtml', 'locations' => ['progression' => 0.1, 'totalProgression' => 0.2, 'cfi' => 'epubcfi(/6/2!/4/2,/1:0,/1:5)']];
	}

	private function stored(int $clientTs, bool $deleted = false, int $fileId = 7): Annotation {
		$a = new Annotation();
		$a->setUserId('u');
		$a->setFileId($fileId);
		$a->setType('highlight');
		$a->setUuid(self::UUID);
		$a->setLocator(json_encode($this->locator()));
		$a->setText('old');
		$a->setColor('yellow');
		$a->setClientUpdatedAt($clientTs);
		$a->setUpdatedAt(1);
		$a->setDeleted($deleted ? 1 : 0);
		return $a;
	}

	private function missing(): void {
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException(''));
		$this->mapper->method('countLiveByUserAndFile')->willReturn(0);
	}

	public function testInsertsNewAnnotationForTheGivenUser(): void {
		$this->missing();
		$this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);
		$r = $this->service->upsert('u', 7, strtoupper(self::UUID), 'highlight', $this->locator(), 'text', null, 'green', 1000, 500);
		$a = $r['annotation'];
		$this->assertSame('ok', $r['status']);
		$this->assertSame('u', $a->getUserId());
		$this->assertSame(7, $a->getFileId());
		$this->assertSame(self::UUID, $a->getUuid());
		$this->assertSame('green', $a->getColor());
		$this->assertSame(1000, $a->getClientUpdatedAt());
		$this->assertSame(500, $a->getCreatedAt());
		$this->assertSame(self::NOW_MS, $a->getUpdatedAt());
		$this->assertFalse($a->isDeleted());
	}

	public function testMissingUuidIsGenerated(): void {
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException(''));
		$this->mapper->method('countLiveByUserAndFile')->willReturn(0);
		$this->mapper->method('insert')->willReturnArgument(0);
		$r = $this->service->upsert('u', 7, null, 'bookmark', $this->locator(), null, null, null, null);
		$this->assertMatchesRegularExpression(AnnotationService::UUID_PATTERN, $r['annotation']->getUuid());
		$this->assertSame(self::NOW_MS, $r['annotation']->getClientUpdatedAt());
		$this->assertSame(self::NOW_MS, $r['annotation']->getCreatedAt());
	}

	public function testFutureCreatedAtAndClientTimeAreClamped(): void {
		$this->missing();
		$this->mapper->method('insert')->willReturnArgument(0);
		$r = $this->service->upsert('u', 7, self::UUID, 'bookmark', $this->locator(), null, null, null, self::NOW_MS + 6 * 60 * 1000, self::NOW_MS + 99999);
		$this->assertSame(self::NOW_MS, $r['annotation']->getClientUpdatedAt());
		$this->assertSame(self::NOW_MS, $r['annotation']->getCreatedAt());
	}

	public function testNewerClientWinsAndResurrectsTombstone(): void {
		$row = $this->stored(1000, true);
		$this->mapper->method('findByUuid')->willReturn($row);
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);
		$r = $this->service->upsert('u', 7, self::UUID, 'note', $this->locator(), 'new', 'my note', 'blue', 2000);
		$this->assertSame('ok', $r['status']);
		$this->assertFalse($row->isDeleted());
		$this->assertSame('my note', $row->getNote());
		$this->assertSame('note', $row->getType());
		$this->assertSame(2000, $row->getClientUpdatedAt());
	}

	public function testOlderClientConflictsAndReturnsCurrent(): void {
		$row = $this->stored(3000);
		$this->mapper->method('findByUuid')->willReturn($row);
		$this->mapper->expects($this->never())->method('update');
		$r = $this->service->upsert('u', 7, self::UUID, 'highlight', $this->locator(), 'x', null, null, 2000);
		$this->assertSame('conflict', $r['status']);
		$this->assertSame($row, $r['annotation']);
		$this->assertSame('old', $row->getText());
	}

	public function testUuidOfAnotherBookIsRejected(): void {
		$this->mapper->method('findByUuid')->willReturn($this->stored(1000, false, 99));
		$this->expectException(\InvalidArgumentException::class);
		$this->service->upsert('u', 7, self::UUID, 'highlight', $this->locator(), null, null, null, 2000);
	}

	public function testPerBookLimit(): void {
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException(''));
		$this->mapper->method('countLiveByUserAndFile')->willReturn(AnnotationService::MAX_PER_BOOK);
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);
		$this->service->upsert('u', 7, self::UUID, 'highlight', $this->locator(), null, null, null, null);
	}

	/** @param array<string, mixed> $args */
	#[DataProvider('invalidProvider')]
	public function testInvalidInputIsRejected(array $args): void {
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException(''));
		$this->mapper->method('countLiveByUserAndFile')->willReturn(0);
		$this->mapper->expects($this->never())->method('insert');
		$args += ['uuid' => self::UUID, 'type' => 'highlight', 'locator' => $this->locator(), 'text' => null, 'note' => null, 'color' => null, 'ts' => 1000];
		$this->expectException(\InvalidArgumentException::class);
		$this->service->upsert('u', 7, $args['uuid'], $args['type'], $args['locator'], $args['text'], $args['note'], $args['color'], $args['ts']);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function invalidProvider(): array {
		return [
			'unknown type' => [['type' => 'underline']],
			'bad uuid' => [['uuid' => 'not-a-uuid']],
			'bad color' => [['color' => 'red']],
			'text too long' => [['text' => str_repeat('a', AnnotationService::MAX_TEXT_CHARS + 1)]],
			'note too long' => [['note' => str_repeat('a', AnnotationService::MAX_NOTE_CHARS + 1)]],
			'locator without href' => [['locator' => ['locations' => []]]],
			'negative client time' => [['ts' => -1]],
		];
	}

	public function testLimitsAreInCharactersNotBytes(): void {
		$this->missing();
		$this->mapper->method('insert')->willReturnArgument(0);
		$r = $this->service->upsert('u', 7, self::UUID, 'highlight', $this->locator(), str_repeat('ä', AnnotationService::MAX_TEXT_CHARS), null, null, null);
		$this->assertSame(AnnotationService::MAX_TEXT_CHARS, mb_strlen((string)$r['annotation']->getText()));
	}

	public function testPatchChangesOnlyGivenFieldsAndClearsWithEmptyString(): void {
		$row = $this->stored(1000);
		$row->setNote('keep?');
		$this->mapper->method('findByUuid')->willReturn($row);
		$this->mapper->expects($this->exactly(2))->method('update')->willReturnArgument(0);

		$r = $this->service->patch('u', self::UUID, null, null, 'changed', null, 2000);
		$this->assertSame('ok', $r['status'] ?? null);
		$this->assertSame('changed', $row->getNote());
		$this->assertSame('yellow', $row->getColor());
		$this->assertSame('old', $row->getText());
		$this->assertSame(self::NOW_MS, $row->getUpdatedAt());

		$this->service->patch('u', self::UUID, null, null, '', '', 3000);
		$this->assertNull($row->getNote());
		$this->assertNull($row->getColor());
	}

	public function testPatchConflictAndMissing(): void {
		$row = $this->stored(5000);
		$this->mapper->method('findByUuid')->willReturnOnConsecutiveCalls($row, $this->stored(1, true));
		$r = $this->service->patch('u', self::UUID, null, null, 'x', null, 4000);
		$this->assertSame('conflict', $r['status'] ?? null);
		$this->assertNull($this->service->patch('u', self::UUID, null, null, 'x', null, 4000), 'tombstone');
	}

	public function testPatchRejectsInvalidColor(): void {
		$this->mapper->method('findByUuid')->willReturn($this->stored(1000));
		$this->expectException(\InvalidArgumentException::class);
		$this->service->patch('u', self::UUID, null, null, null, 'red', null);
	}

	public function testDeleteSetsTombstoneAndIsIdempotent(): void {
		$row = $this->stored(1000);
		$this->mapper->method('findByUuid')->willReturn($row);
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);
		$r = $this->service->delete('u', self::UUID, 2000);
		$this->assertSame('ok', $r['status'] ?? null);
		$this->assertTrue($row->isDeleted());
		$this->assertSame(self::NOW_MS, $row->getUpdatedAt());
		// second delete: no further update
		$this->assertSame('ok', $this->service->delete('u', self::UUID, 3000)['status'] ?? null);
	}

	public function testDeleteOlderThanTheLastEditConflicts(): void {
		$row = $this->stored(5000);
		$this->mapper->method('findByUuid')->willReturn($row);
		$this->mapper->expects($this->never())->method('update');
		$this->assertSame('conflict', $this->service->delete('u', self::UUID, 4000)['status'] ?? null);
		$this->assertFalse($row->isDeleted());
	}

	public function testDeleteUnknownReturnsNull(): void {
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException(''));
		$this->assertNull($this->service->delete('u', self::UUID, null));
	}

	public function testToApiShape(): void {
		$a = $this->stored(1000, true);
		$api = $a->toApi();
		$this->assertSame(self::UUID, $api['uuid']);
		$this->assertSame($this->locator(), $api['locator']);
		$this->assertTrue($api['deleted']);
		$this->assertArrayNotHasKey('userId', $api);
	}
}
