<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Metadata\SafeZip;
use OCA\EbookReader\Metadata\UnsafeArchiveException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures.php';

class SafeZipTest extends TestCase {
	#[DataProvider('unsafeNames')]
	public function testUnsafeNames(string $name): void {
		$this->assertFalse(SafeZip::isSafeName($name));
	}

	/** @return array<string, list<string>> */
	public static function unsafeNames(): array {
		return [
			'parent' => ['../evil.txt'],
			'nested parent' => ['a/../../evil.txt'],
			'absolute' => ['/etc/passwd'],
			'windows drive' => ['C:/evil.txt'],
			'backslash' => ['a\\..\\evil.txt'],
			'nul' => ["a\0b"],
			'empty' => [''],
		];
	}

	public function testSafeNames(): void {
		$this->assertTrue(SafeZip::isSafeName('OEBPS/ch1.xhtml'));
		$this->assertTrue(SafeZip::isSafeName('a/b..c/d.txt'));
	}

	public function testZipSlipArchiveIsRejected(): void {
		$path = Fixtures::temp('.zip');
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		$zip->addFromString('ok.txt', 'ok');
		$zip->addFromString('../evil.txt', 'bad');
		$zip->close();
		$this->expectException(UnsafeArchiveException::class);
		SafeZip::open($path);
	}

	public function testEntrySizeCap(): void {
		$path = Fixtures::temp('.zip');
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		$zip->addFromString('big.xml', str_repeat('a', 2048));
		$zip->close();
		$sz = SafeZip::open($path);
		$this->assertSame(2048, strlen((string)$sz->read('big.xml')));
		$this->expectException(UnsafeArchiveException::class);
		$sz->read('big.xml', 1024);
	}

	public function testReadFindAndCaseInsensitiveLookup(): void {
		$sz = SafeZip::open(Fixtures::path('epub2.epub'));
		$this->assertTrue($sz->has('META-INF/container.xml'));
		$this->assertSame('META-INF/container.xml', $sz->find('meta-inf/CONTAINER.xml'));
		$this->assertNull($sz->read('nope.txt'));
		$this->assertContains('OEBPS/content.opf', $sz->names());
		$this->assertGreaterThan(0, $sz->size('OEBPS/content.opf'));
		$sz->close();
		$sz->close();
	}

	public function testMissingArchive(): void {
		$this->expectException(UnsafeArchiveException::class);
		SafeZip::open(Fixtures::temp('.zip'));
	}

	public function testResolve(): void {
		$this->assertSame('OEBPS/img/a b.png', SafeZip::resolve('OEBPS', 'img/a%20b.png#frag'));
		$this->assertSame('OEBPS/x.png', SafeZip::resolve('OEBPS/text', '../x.png'));
		$this->assertSame('x.png', SafeZip::resolve('OEBPS', '/x.png'));
		$this->assertNull(SafeZip::resolve('OEBPS', '../../x.png'));
		$this->assertNull(SafeZip::resolve('', ''));
		$this->assertSame('a/c', SafeZip::resolve('', './a/./b/../c'));
	}
}
