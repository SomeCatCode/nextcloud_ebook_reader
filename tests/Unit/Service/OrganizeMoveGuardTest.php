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
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\OrganizeService;
use OCA\EbookReader\Service\RenameService;
use OCA\EbookReader\Service\SettingsService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/OcHooksEmitterStub.php';

/** Regression test A6: files that may not be updated/deleted are never moved by "organize". */
class OrganizeMoveGuardTest extends TestCase {
	/** @return array<string, array{bool, bool, string}> */
	public static function permissions(): array {
		return [
			'read-only' => [false, true, 'failed'],
			'not deletable' => [true, false, 'failed'],
			'full access' => [true, true, 'moved'],
		];
	}

	#[DataProvider('permissions')]
	public function testMoveOnlyWithUpdateAndDeletePermission(bool $updateable, bool $deletable, string $expected): void {
		$book = new Book();
		$book->setPath('/Books/old.epub');
		$book->setId(1);
		$mapper = $this->createMock(BookMapper::class);
		$mapper->method('findByUserAndFile')->willReturn($book);

		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('old.epub');
		$file->method('getPath')->willReturn('/u/files/Books/old.epub');
		$file->method('getParent')->willReturn($this->createMock(Folder::class));
		$file->method('isUpdateable')->willReturn($updateable);
		$file->method('isDeletable')->willReturn($deletable);
		$file->expects($expected === 'moved' ? $this->once() : $this->never())->method('move');

		$library = $this->createMock(LibraryService::class);
		$library->method('getFileForUser')->willReturn($file);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('get')->willReturn(['libraryFolders' => ['/Books'], 'reader' => [], 'filenamePattern' => '', 'genreList' => null]);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->willReturn('/Books/old.epub');
		$userFolder->method('getPath')->willReturn('/u/files');
		$userFolder->method('nodeExists')->willReturn(false);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		$validator = $this->createMock(IFilenameValidator::class);
		$validator->method('sanitizeFilename')->willReturnArgument(0);

		$service = new OrganizeService($mapper, $this->createMock(TagMapper::class), $library, new RenameService(), $settings, $root, $validator, $this->createMock(LoggerInterface::class));
		$result = $service->apply('u', [5], 'new', null);
		$this->assertSame($expected, $result['items'][0]['status'], json_encode($result['items']));
	}
}
