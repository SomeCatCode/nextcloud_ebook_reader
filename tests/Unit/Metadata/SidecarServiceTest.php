<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Metadata\SidecarService;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Sidecar metadata files (".<book>.opf"): format, reading rules and file handling. */
class SidecarServiceTest extends TestCase {
	private SidecarService $service;

	protected function setUp(): void {
		$this->service = new SidecarService($this->createMock(LoggerInterface::class));
	}

	public function testNames(): void {
		$this->assertSame('.Golden Boy 01.cbz.opf', SidecarService::nameFor('Golden Boy 01.cbz'));
		$this->assertTrue(SidecarService::isSidecarName('.Golden Boy 01.cbz.opf'));
		$this->assertTrue(SidecarService::isSidecarName('.x.fb2.zip.opf'));
		$this->assertTrue(SidecarService::isSidecarName('.X.EPUB.opf'));
		$this->assertSame('Golden Boy 01.cbz', SidecarService::bookNameOf('.Golden Boy 01.cbz.opf'));
		$this->assertFalse(SidecarService::isSidecarName('metadata.opf'));
		$this->assertFalse(SidecarService::isSidecarName('.hidden.opf'));
		$this->assertFalse(SidecarService::isSidecarName('.notes.txt.opf'));
		$this->assertFalse(SidecarService::isSidecarName('Golden Boy.cbz'));
		$this->assertNull(SidecarService::bookNameOf('book.epub'));
	}

	public function testRoundTripWithSpecialCharactersGenresTagsAndSeries(): void {
		$meta = [
			'title' => 'Tom & Jerry <"Best"> \'Of\'',
			'authors' => ['Ärzte & Söhne', 'Jane "JD" Doe'],
			'series' => 'Series <1> & more',
			'seriesIndex' => 2.5,
			'description' => '<p>Line one &amp; <b>bold</b></p><p>Second</p>',
			'language' => 'de',
			'publisher' => 'Verlag "X" & Co',
			'isbn' => '978-3-16-148410-0',
			'publishedAt' => '2020-05-17',
			'genres' => ['Krimi', 'Science-Fiction'],
			'tags' => ['Lieblingsbuch', 'a<b', 'krimi'],
		];
		$xml = SidecarService::build($meta);
		$this->assertStringContainsString('xmlns:dc="http://purl.org/dc/elements/1.1/"', $xml);
		$this->assertStringContainsString('unique-identifier="uuid_id"', $xml);
		$this->assertStringContainsString('<meta name="calibre:series_index" content="2.5"', $xml);
		$this->assertStringContainsString('opf:role="aut"', $xml);
		$this->assertStringContainsString('opf:scheme="ISBN"', $xml);
		$this->assertStringContainsString('name="ebookreader:version"', $xml);
		$this->assertStringNotContainsString('<b>', $xml, 'description is stored as plain text');

		$read = SidecarService::parse($xml);
		$this->assertNotNull($read);
		$m = $read->metadata;
		$this->assertSame($meta['title'], $m->title);
		$this->assertSame($meta['authors'], $m->authors);
		$this->assertSame($meta['series'], $m->series);
		$this->assertSame(2.5, $m->seriesIndex);
		$this->assertSame('de', $m->language);
		$this->assertSame($meta['publisher'], $m->publisher);
		$this->assertSame('978-3-16-148410-0', $m->isbn);
		$this->assertSame('2020-05-17', $m->publishedAt);
		$this->assertSame('<p>Line one &amp; bold</p><p>Second</p>', $m->description);
		// genres and tags stay apart; a tag named like a genre collapses into the genre
		$this->assertSame(['Krimi', 'Science-Fiction'], $m->genres);
		$this->assertSame(['Lieblingsbuch', 'a<b'], $m->tags);
		$this->assertSame([], $m->subjects);
		$this->assertTrue($read->labelsAuthoritative);
	}

	public function testBuildKeepsTheUuidAndSkipsEmptyFields(): void {
		$xml = SidecarService::build(['title' => 'T', 'authors' => [], 'series' => null, 'seriesIndex' => 3, 'genres' => [], 'tags' => []], '11111111-2222-4333-8444-555555555555');
		$this->assertStringContainsString('11111111-2222-4333-8444-555555555555', $xml);
		$this->assertStringNotContainsString('calibre:series', $xml, 'an index without a series is dropped');
		$this->assertStringNotContainsString('dc:creator', $xml);
		$read = SidecarService::parse($xml);
		$this->assertSame('11111111-2222-4333-8444-555555555555', $read?->uuid);
		$this->assertSame([], $read->metadata->authors);
		$this->assertNull($read->metadata->series);
		$this->assertTrue($read->labelsAuthoritative, 'an app written sidecar without labels clears the embedded ones');
	}

	public function testCalibreSidecarSubjectsAreUnclassified(): void {
		$opf = '<?xml version="1.0" encoding="utf-8"?><package xmlns="http://www.idpf.org/2007/opf" version="2.0" unique-identifier="uuid_id">'
			. '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:opf="http://www.idpf.org/2007/opf">'
			. '<dc:title>Calibre Book</dc:title><dc:creator opf:role="aut" opf:file-as="Doe, J">J Doe</dc:creator><dc:creator opf:role="edt">Editor</dc:creator>'
			. '<dc:subject>Fantasy</dc:subject><dc:subject>Mine</dc:subject>'
			. '<dc:identifier opf:scheme="calibre">1</dc:identifier><dc:identifier opf:scheme="ISBN">9781234567897</dc:identifier>'
			. '<dc:date>2019-03-04T10:00:00+00:00</dc:date>'
			. '<meta name="calibre:series" content="Saga"/><meta name="calibre:series_index" content="3.0"/>'
			. '</metadata></package>';
		$read = SidecarService::parse($opf);
		$this->assertNotNull($read);
		$this->assertSame('Calibre Book', $read->metadata->title);
		$this->assertSame(['J Doe'], $read->metadata->authors, 'only authors');
		$this->assertSame(['Fantasy', 'Mine'], $read->metadata->subjects);
		$this->assertSame([], $read->metadata->genres);
		$this->assertFalse($read->labelsAuthoritative);
		$this->assertSame('Saga', $read->metadata->series);
		$this->assertSame(3.0, $read->metadata->seriesIndex);
		$this->assertSame('9781234567897', $read->metadata->isbn);
		$this->assertSame('2019-03-04', $read->metadata->publishedAt);
		$this->assertNull($read->metadata->language, 'only fields that are present');
		$this->assertNull($read->metadata->description);
	}

	public function testDtdAndEntitiesAreRejected(): void {
		$xxe = '<?xml version="1.0"?><!DOCTYPE package [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
			. '<package xmlns="http://www.idpf.org/2007/opf" version="2.0"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>&x;</dc:title></metadata></package>';
		$this->assertNull(SidecarService::parse($xxe));
		$bomb = '<?xml version="1.0"?><!DOCTYPE package [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;">]>'
			. '<package xmlns="http://www.idpf.org/2007/opf"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>&b;</dc:title></metadata></package>';
		$this->assertNull(SidecarService::parse($bomb));
		$this->assertNull(SidecarService::parse('<html><body/></html>'), 'not an OPF package');
		$this->assertNull(SidecarService::parse('not xml'));
	}

	public function testOversizedContentIsIgnored(): void {
		$big = SidecarService::build(['title' => str_repeat('x', SidecarService::MAX_BYTES)]);
		$this->assertNull(SidecarService::parse($big));
	}

	/** @return array{File&MockObject, Folder&MockObject} */
	private function book(string $name = 'Golden Boy 01.cbz'): array {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/u/files/Books');
		$book = $this->createMock(File::class);
		$book->method('getName')->willReturn($name);
		$book->method('getParent')->willReturn($folder);
		return [$book, $folder];
	}

	private function sidecarNode(Folder&MockObject $folder, string $content, bool $updateable = true): File&MockObject {
		$sc = $this->createMock(File::class);
		$sc->method('getName')->willReturn('.Golden Boy 01.cbz.opf');
		$sc->method('getPath')->willReturn('/u/files/Books/.Golden Boy 01.cbz.opf');
		$sc->method('getSize')->willReturn(strlen($content));
		$sc->method('getContent')->willReturn($content);
		$sc->method('isReadable')->willReturn(true);
		$sc->method('isUpdateable')->willReturn($updateable);
		$sc->method('getEtag')->willReturn('abc');
		$sc->method('getMTime')->willReturn(42);
		$folder->method('nodeExists')->willReturn(true);
		$folder->method('get')->with('.Golden Boy 01.cbz.opf')->willReturn($sc);
		return $sc;
	}

	public function testWriteCreatesTheHiddenFileNextToTheBook(): void {
		[$book, $folder] = $this->book();
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('isCreatable')->willReturn(true);
		$created = null;
		$folder->expects($this->once())->method('newFile')->willReturnCallback(function (string $name, $content) use (&$created): File {
			$created = [$name, $content];
			return $this->createMock(File::class);
		});
		$this->assertTrue($this->service->write($book, ['title' => 'Golden Boy', 'authors' => ['Tatsuya Ishida']]));
		$this->assertSame('.Golden Boy 01.cbz.opf', $created[0]);
		$this->assertSame('Golden Boy', SidecarService::parse($created[1])?->metadata->title);
	}

	public function testWriteOnlyTouchesTheFileWhenTheContentChanges(): void {
		[$book, $folder] = $this->book();
		$meta = ['title' => 'Same', 'authors' => ['A']];
		$sc = $this->sidecarNode($folder, SidecarService::build($meta, 'u-1'));
		$sc->expects($this->never())->method('putContent');
		$this->assertTrue($this->service->write($book, $meta), 'identical content: nothing to write, but up to date');
	}

	public function testWriteUpdatesChangedContentAndKeepsTheUuid(): void {
		[$book, $folder] = $this->book();
		$sc = $this->sidecarNode($folder, SidecarService::build(['title' => 'Old'], 'keep-this-id'));
		$written = null;
		$sc->expects($this->once())->method('putContent')->willReturnCallback(function (string $c) use (&$written): void {
			$written = $c;
		});
		$this->assertTrue($this->service->write($book, ['title' => 'New']));
		$this->assertSame('keep-this-id', SidecarService::parse((string)$written)?->uuid);
		$this->assertSame('New', SidecarService::parse((string)$written)->metadata->title);
	}

	public function testWriteFailsWithoutPermission(): void {
		[$book, $folder] = $this->book();
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('isCreatable')->willReturn(false);
		$folder->expects($this->never())->method('newFile');
		$this->assertFalse($this->service->write($book, ['title' => 'X']));

		[$book2, $folder2] = $this->book();
		$sc = $this->sidecarNode($folder2, SidecarService::build(['title' => 'Old']), false);
		$sc->expects($this->never())->method('putContent');
		$this->assertFalse($this->service->write($book2, ['title' => 'New']));
	}

	public function testWriteWithoutCreateOnlyUpdatesAnExistingSidecar(): void {
		[$book, $folder] = $this->book();
		$folder->method('nodeExists')->willReturn(false);
		$folder->expects($this->never())->method('newFile');
		$this->assertTrue($this->service->write($book, ['title' => 'X'], false));
	}

	public function testReadAndChangeMarker(): void {
		[$book, $folder] = $this->book();
		$this->sidecarNode($folder, SidecarService::build(['title' => 'Read me']));
		$this->assertSame('Read me', $this->service->read($book)?->metadata->title);
		$this->assertSame('abc:42', $this->service->etagOf($book));
		[$none, $emptyFolder] = $this->book();
		$emptyFolder->method('nodeExists')->willReturn(false);
		$this->assertNull($this->service->read($none));
		$this->assertNull($this->service->etagOf($none));
	}

	public function testReadIgnoresOversizedSidecars(): void {
		[$book, $folder] = $this->book();
		$sc = $this->createMock(File::class);
		$sc->method('isReadable')->willReturn(true);
		$sc->method('getSize')->willReturn(SidecarService::MAX_BYTES + 1);
		$sc->expects($this->never())->method('getContent');
		$folder->method('nodeExists')->willReturn(true);
		$folder->method('get')->willReturn($sc);
		$this->assertNull($this->service->read($book));
	}

	public function testMoveCopyAndDeleteHelpers(): void {
		[, $from] = $this->book();
		$sc = $this->sidecarNode($from, SidecarService::build(['title' => 'X']));
		$sc->method('isDeletable')->willReturn(true);
		$to = $this->createMock(Folder::class);
		$to->method('getPath')->willReturn('/u/files/Sorted');
		$to->method('nodeExists')->willReturn(false);
		$to->method('isCreatable')->willReturn(true);
		$sc->expects($this->once())->method('move')->with('/u/files/Sorted/.New Name.cbz.opf');
		$this->assertTrue($this->service->moveAlong($from, 'Golden Boy 01.cbz', $to, 'New Name.cbz'));

		[, $from2] = $this->book();
		$sc2 = $this->sidecarNode($from2, SidecarService::build(['title' => 'X']));
		$sc2->expects($this->once())->method('copy')->with('/u/files/Sorted/.Copy.cb7.opf');
		$this->assertTrue($this->service->copyAlong($from2, 'Golden Boy 01.cbz', $to, 'Copy.cb7'));

		[, $from3] = $this->book();
		$sc3 = $this->sidecarNode($from3, SidecarService::build(['title' => 'X']));
		$sc3->method('isDeletable')->willReturn(true);
		$sc3->expects($this->once())->method('delete');
		$this->assertTrue($this->service->deleteFor($from3, 'Golden Boy 01.cbz'));
	}

	public function testMoveSkipsWhenTheTargetExists(): void {
		[, $from] = $this->book();
		$sc = $this->sidecarNode($from, SidecarService::build(['title' => 'X']));
		$sc->method('isDeletable')->willReturn(true);
		$sc->expects($this->never())->method('move');
		$to = $this->createMock(Folder::class);
		$to->method('getPath')->willReturn('/u/files/Sorted');
		$to->method('nodeExists')->with('.New.cbz.opf')->willReturn(true);
		$this->assertFalse($this->service->moveAlong($from, 'Golden Boy 01.cbz', $to, 'New.cbz'));
	}

	public function testOwnChangesAreGuardedWhileTheyRun(): void {
		[$book, $folder] = $this->book();
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('isCreatable')->willReturn(true);
		$seen = null;
		$folder->method('newFile')->willReturnCallback(function (string $name) use (&$seen): File {
			$seen = SidecarService::isGuarded('/u/files/Books/' . $name);
			return $this->createMock(File::class);
		});
		$this->service->write($book, ['title' => 'X']);
		$this->assertTrue($seen, 'the listener must ignore events raised while we write');
		$this->assertFalse(SidecarService::isGuarded('/u/files/Books/.Golden Boy 01.cbz.opf'), 'and only then');
	}
}
