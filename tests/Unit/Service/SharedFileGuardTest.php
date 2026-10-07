<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\ProgressMapper;
use OCA\EbookReader\Db\TagMapper;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Metadata\SidecarService;
use OCA\EbookReader\Service\FileOwnership;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\OrganizeService;
use OCA\EbookReader\Service\RenameService;
use OCA\EbookReader\Service\SettingsService;
use OCA\EbookReader\Service\SharedBookException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Storage\ISharedStorage;
use OCP\Files\Storage\IStorage;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/OcHooksEmitterStub.php';

/** Books that reached the user through a share belong to the owner: never deleted, moved or removed by this app. */
class SharedFileGuardTest extends TestCase {
	/** @param class-string<Node> $class */
	private function node(string $class, ?string $shareOwner, string $internalPath = 'x'): Node&MockObject {
		if ($shareOwner === null) {
			$storage = $this->createMock(IStorage::class);
			$storage->method('instanceOfStorage')->willReturn(false);
		} else {
			$share = $this->createMock(IShare::class);
			$share->method('getShareOwner')->willReturn($shareOwner);
			$storage = $this->createMock(ISharedStorage::class);
			$storage->method('instanceOfStorage')->willReturnCallback(static fn (string $c): bool => $c === ISharedStorage::class);
			$storage->method('getShare')->willReturn($share);
		}
		$node = $this->createMock($class);
		$node->method('getStorage')->willReturn($storage);
		$node->method('getInternalPath')->willReturn($internalPath);
		return $node;
	}

	public function testOwnership(): void {
		$own = $this->node(File::class, null);
		$shared = $this->node(File::class, 'alice');
		$this->assertFalse(FileOwnership::isShared($own));
		$this->assertNull(FileOwnership::shareOwner($own));
		$this->assertTrue(FileOwnership::isShared($shared));
		$this->assertSame('alice', FileOwnership::shareOwner($shared));
		$this->assertFalse(FileOwnership::isShareRoot($this->node(Folder::class, 'alice', 'sub')));
		$this->assertTrue(FileOwnership::isShareRoot($this->node(Folder::class, 'alice', '')));
		$this->assertFalse(FileOwnership::isShareRoot($this->node(Folder::class, null, '')));
	}

	public function testSerializerExposesOwnerAndShared(): void {
		$library = $this->createMock(LibraryService::class);
		$library->method('getFileForUser')->willReturn($this->node(File::class, 'alice'));
		$serializer = new BookSerializer($library, $this->createMock(TagMapper::class), $this->createMock(ProgressMapper::class));
		$data = $serializer->serialize('bob', new Book(), []);
		$this->assertTrue($data['shared']);
		$this->assertSame('alice', $data['owner']);
	}

	public function testSerializerOwnFileIsOwnedByTheUser(): void {
		$library = $this->createMock(LibraryService::class);
		$library->method('getFileForUser')->willReturn($this->node(File::class, null));
		$serializer = new BookSerializer($library, $this->createMock(TagMapper::class), $this->createMock(ProgressMapper::class));
		$data = $serializer->serialize('bob', new Book(), []);
		$this->assertFalse($data['shared']);
		$this->assertSame('bob', $data['owner']);
	}

	public function testDeleteOfSharedBookIsRefusedAndNothingIsDeleted(): void {
		$file = $this->node(File::class, 'alice');
		$file->method('isDeletable')->willReturn(true);
		$file->method('isReadable')->willReturn(true);
		$file->expects($this->never())->method('delete');
		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')->willReturn($file);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);
		$library = $this->getMockBuilder(LibraryService::class)->disableOriginalConstructor()->onlyMethods(['getNodeForUser'])->getMock();
		$library->method('getNodeForUser')->willReturn($file);
		try {
			$library->deleteFileForUser('bob', 5);
			$this->fail('expected SharedBookException');
		} catch (SharedBookException $e) {
			$this->assertSame('alice', $e->getOwner());
			$this->assertStringContainsString('alice', $e->getMessage());
		}
	}

	/** @return list<array<string, mixed>> */
	private function organize(Node $file, Folder $emptyFolder): array {
		$book = new Book();
		$book->setPath('/Books/shared/old.epub');
		$book->setId(1);
		$mapper = $this->createMock(BookMapper::class);
		$mapper->method('findByUserAndFile')->willReturn($book);
		$library = $this->createMock(LibraryService::class);
		$library->method('getFileForUser')->willReturn($file);
		$library->method('isPathInLibrary')->willReturn(true);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('get')->willReturn(['libraryFolders' => ['/Books'], 'reader' => [], 'filenamePattern' => '', 'genreList' => null]);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->willReturn('/Books/shared/old.epub');
		$userFolder->method('getPath')->willReturn('/bob/files');
		$userFolder->method('nodeExists')->willReturn(false);
		$userFolder->method('get')->willReturn($emptyFolder);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		$validator = $this->createMock(IFilenameValidator::class);
		$validator->method('sanitizeFilename')->willReturnArgument(0);
		$service = new OrganizeService($mapper, $this->createMock(TagMapper::class), $library, new RenameService(), $settings, $root, $validator, $this->createMock(LoggerInterface::class), $this->createMock(SidecarService::class));
		return $service->apply('bob', [5], 'new', null)['items'];
	}

	public function testOrganizeSkipsForeignFiles(): void {
		$file = $this->node(File::class, 'alice');
		$file->method('getName')->willReturn('old.epub');
		$file->method('getPath')->willReturn('/bob/files/Books/shared/old.epub');
		$file->method('isUpdateable')->willReturn(true);
		$file->method('isDeletable')->willReturn(true);
		$file->expects($this->never())->method('move');
		$folder = $this->node(Folder::class, 'alice', '');
		$folder->expects($this->never())->method('delete');
		$items = $this->organize($file, $folder);
		$this->assertSame('error', $items[0]['status']);
		$this->assertStringContainsString('alice', $items[0]['message']);
	}

	public function testEmptyFolderInsideShareIsNotRemovedAfterOwnMove(): void {
		$file = $this->node(File::class, null);
		$file->method('getName')->willReturn('old.epub');
		$file->method('getPath')->willReturn('/bob/files/Books/shared/old.epub');
		$file->method('isUpdateable')->willReturn(true);
		$file->method('isDeletable')->willReturn(true);
		$file->method('getParent')->willReturn($this->createMock(Folder::class));
		$folder = $this->node(Folder::class, 'alice', '');
		$folder->method('getDirectoryListing')->willReturn([]);
		$folder->method('isDeletable')->willReturn(true);
		$folder->expects($this->never())->method('delete');
		$items = $this->organize($file, $folder);
		$this->assertSame('moved', $items[0]['status']);
	}
}
