<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Listener;

use OCA\EbookReader\Listener\FileEventListener;
use OCA\EbookReader\Metadata\MetadataService;
use OCA\EbookReader\Metadata\SidecarService;
use OCA\EbookReader\Service\LibraryService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Sidecar handling of the file event listener: external changes re-index, own writes are ignored, books carry their sidecar. */
class FileEventListenerSidecarTest extends TestCase {
	private LibraryService&MockObject $library;
	private MetadataService&MockObject $metadata;
	private SidecarService&MockObject $sidecar;
	private FileEventListener $listener;

	protected function setUp(): void {
		$this->library = $this->createMock(LibraryService::class);
		$this->metadata = $this->createMock(MetadataService::class);
		$this->metadata->method('detectFormat')->willReturnCallback(static fn (string $name): ?string => str_ends_with($name, '.epub') ? 'epub' : null);
		$this->library->method('isInLibrary')->willReturn(true);
		$this->sidecar = $this->createMock(SidecarService::class);
		$this->listener = new FileEventListener($this->library, $this->metadata, $this->createMock(IJobList::class), $this->createMock(LoggerInterface::class), $this->sidecar);
	}

	private function file(string $name, string $dir = '/u/files/Books', ?Folder $parent = null): File&MockObject {
		$f = $this->createMock(File::class);
		$f->method('getName')->willReturn($name);
		$f->method('getPath')->willReturn($dir . '/' . $name);
		$f->method('getId')->willReturn(crc32($dir . $name) % 100000);
		$f->method('getSize')->willReturn(1000);
		$f->method('getMimeType')->willReturn('application/octet-stream');
		if ($parent !== null) {
			$f->method('getParent')->willReturn($parent);
		}
		return $f;
	}

	private function folderWith(File $book): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturnCallback(static fn (string $n): bool => $n === $book->getName());
		$folder->method('get')->willReturnCallback(static fn (string $n) => $n === $book->getName() ? $book : null);
		return $folder;
	}

	public function testSidecarCreatedExternallyReindexesItsBook(): void {
		$book = $this->file('x.epub');
		$sidecar = $this->file('.x.epub.opf', parent: $this->folderWith($book));
		$this->library->expects($this->once())->method('indexFile')->with('u', $book);
		$this->listener->handle(new NodeCreatedEvent($sidecar));
	}

	public function testSidecarWrittenAndDeletedExternallyReindexTheBook(): void {
		$book = $this->file('x.epub');
		$sidecar = $this->file('.x.epub.opf', parent: $this->folderWith($book));
		$this->library->expects($this->exactly(2))->method('indexFile')->with('u', $book);
		$this->listener->handle(new NodeWrittenEvent($sidecar));
		$this->listener->handle(new NodeDeletedEvent($sidecar));
		$this->library->expects($this->never())->method('removeFileForAllUsers');
	}

	public function testOpfFilesThatAreNoSidecarsAreIgnored(): void {
		$other = $this->file('metadata.opf');
		$this->library->expects($this->never())->method('indexFile');
		$this->listener->handle(new NodeCreatedEvent($other));
		$this->listener->handle(new NodeWrittenEvent($this->file('.hidden.opf')));
	}

	public function testOwnSidecarWritesDoNotLoopBackIntoIndexing(): void {
		$real = new SidecarService($this->createMock(LoggerInterface::class));
		$listener = new FileEventListener($this->library, $this->metadata, $this->createMock(IJobList::class), $this->createMock(LoggerInterface::class), $real);
		$book = $this->file('x.epub');
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/u/files/Books');
		$folder->method('nodeExists')->willReturnCallback(static fn (string $n): bool => $n === 'x.epub');
		$folder->method('get')->willReturn($book);
		$folder->method('isCreatable')->willReturn(true);
		$sidecarNode = $this->file('.x.epub.opf', parent: $folder);
		$folder->method('newFile')->willReturnCallback(function () use ($listener, $sidecarNode): File {
			// Nextcloud dispatches the event inside newFile()
			$listener->handle(new NodeCreatedEvent($sidecarNode));
			return $sidecarNode;
		});
		$bookWithFolder = $this->createMock(File::class);
		$bookWithFolder->method('getName')->willReturn('x.epub');
		$bookWithFolder->method('getParent')->willReturn($folder);

		$this->library->expects($this->never())->method('indexFile');
		$this->assertTrue($real->write($bookWithFolder, ['title' => 'T']));
	}

	public function testRenamedBookTakesItsSidecarAlong(): void {
		$oldParent = $this->createMock(Folder::class);
		$newParent = $this->createMock(Folder::class);
		$source = $this->file('old.epub', '/u/files/Books', $oldParent);
		$target = $this->file('new.epub', '/u/files/Sorted', $newParent);
		$this->sidecar->expects($this->once())->method('moveAlong')->with($oldParent, 'old.epub', $newParent, 'new.epub');
		$this->library->expects($this->once())->method('indexFile')->with('u', $target);
		$this->listener->handle(new NodeRenamedEvent($source, $target));
	}

	public function testDeletedBookDeletesItsSidecar(): void {
		$parent = $this->createMock(Folder::class);
		$book = $this->file('x.epub', parent: $parent);
		$this->sidecar->expects($this->once())->method('deleteFor')->with($parent, 'x.epub');
		$this->library->expects($this->once())->method('removeFileForAllUsers')->with($book->getId());
		$this->listener->handle(new NodeDeletedEvent($book));
	}

	public function testDeletedNonBookDoesNotLookForASidecar(): void {
		$doc = $this->file('notes.txt', parent: $this->createMock(Folder::class));
		$this->sidecar->expects($this->never())->method('deleteFor');
		$this->listener->handle(new NodeDeletedEvent($doc));
	}
}
