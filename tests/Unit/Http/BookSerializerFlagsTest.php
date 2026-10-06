<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Http;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\ProgressMapper;
use OCA\EbookReader\Db\TagMapper;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Service\LibraryService;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

/** completion / ageRating in the book JSON (also what GET /sync delivers). */
class BookSerializerFlagsTest extends TestCase {
	private function serialize(Book $book): array {
		$library = $this->createMock(LibraryService::class);
		$library->method('getFileForUser')->willThrowException(new NotFoundException());
		return (new BookSerializer($library, $this->createMock(TagMapper::class), $this->createMock(ProgressMapper::class)))->serialize('u', $book, []);
	}

	public function testDefaultsAreNull(): void {
		$data = $this->serialize(new Book());
		$this->assertNull($data['completion']);
		$this->assertNull($data['ageRating']);
		$this->assertFalse($data['ageRatingManual']);
	}

	public function testValuesAreSerialized(): void {
		$book = new Book();
		$book->setCompletion(Book::COMPLETION_COMPLETED);
		$book->setManualAgeRating(16);
		$data = $this->serialize($book);
		$this->assertSame('completed', $data['completion']);
		$this->assertSame(16, $data['ageRating']);
		$this->assertTrue($data['ageRatingManual']);
	}

	public function testInvalidStoredValuesBecomeNull(): void {
		$book = new Book();
		$book->setCompletion('bogus');
		$book->setAgeRating(15);
		$data = $this->serialize($book);
		$this->assertNull($data['completion']);
		$this->assertNull($data['ageRating']);
	}
}
