<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\OpdsController;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Http\OpdsDownloadResponse;
use OCA\EbookReader\Service\CoverService;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\OpdsCatalog;
use OCA\EbookReader\Service\OpdsSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OpdsControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private OpdsSettings&MockObject $settings;
	private OpdsCatalog&MockObject $catalog;
	private LibraryService&MockObject $library;
	private BookMapper&MockObject $books;
	private CoverService&MockObject $covers;
	/** @var array<string, string> */
	private array $headers = [];

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->settings = $this->createMock(OpdsSettings::class);
		$this->catalog = $this->createMock(OpdsCatalog::class);
		$this->library = $this->createMock(LibraryService::class);
		$this->books = $this->createMock(BookMapper::class);
		$this->covers = $this->createMock(CoverService::class);
	}

	private function controller(?string $user = 'alice'): OpdsController {
		return new OpdsController($this->request, $user, $this->settings, $this->catalog, $this->library, $this->books, $this->covers);
	}

	/**
	 * Headers set on the response itself (Response::getHeaders() needs a running server).
	 * @return array<string, string>
	 */
	private function headersOf(\OCP\AppFramework\Http\Response $r): array {
		$prop = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		/** @var array<string, string> $headers */
		$headers = $prop->getValue($r);
		return $headers;
	}

	private function active(bool $active = true): void {
		$this->settings->method('isActiveFor')->willReturn($active);
	}

	public function testUnauthenticatedRequestGets401WithBasicChallenge(): void {
		$this->catalog->expects($this->never())->method($this->anything());
		$r = $this->controller(null)->index();
		$this->assertSame(401, $r->getStatus());
		$this->assertSame('Basic realm="Nextcloud E-Book Reader", charset="UTF-8"', $this->headersOf($r)['WWW-Authenticate']);
		$this->assertFalse($r->isThrottled(), 'the first request without credentials is just the challenge');
	}

	public function testFailedCredentialsAreThrottled(): void {
		$this->headers['Authorization'] = 'Basic Zm9vOmJhcg==';
		$r = $this->controller(null)->all();
		$this->assertSame(401, $r->getStatus());
		$this->assertTrue($r->isThrottled());
		$this->assertSame('ebookreader_opds', $r->getThrottleMetadata()['action']);
	}

	public function testEveryActionRequiresLogin(): void {
		$c = $this->controller('');
		$this->library->expects($this->never())->method('getFileForUser');
		foreach ([
			$c->index(), $c->recent(), $c->reading(), $c->all(), $c->books('author:x'), $c->authors(), $c->series(), $c->genres(),
			$c->tags(), $c->shelves(), $c->search('x'), $c->opensearch(), $c->download(5), $c->cover(5),
		] as $response) {
			$this->assertSame(401, $response->getStatus());
		}
	}

	public function testDisabledCatalogIsForbiddenForEveryAction(): void {
		$this->active(false);
		$this->catalog->expects($this->never())->method($this->anything());
		$this->books->expects($this->never())->method('findByUserAndFile');
		$c = $this->controller();
		foreach ([$c->index(), $c->recent(), $c->books('author:x'), $c->search('x'), $c->opensearch(), $c->download(5), $c->cover(5)] as $response) {
			$this->assertSame(403, $response->getStatus());
			$this->assertStringNotContainsString('<', (string)$response->render());
		}
	}

	public function testFeedHeaders(): void {
		$this->active();
		$this->catalog->method('root')->willReturn('<feed/>');
		$this->catalog->method('recent')->with('alice', 3)->willReturn('<feed/>');
		$r = $this->controller()->index();
		$this->assertSame(200, $r->getStatus());
		$this->assertSame('application/atom+xml;profile=opds-catalog;kind=navigation', $this->headersOf($r)['Content-Type']);
		$this->assertSame('private, no-store', $this->headersOf($r)['Cache-Control']);
		$this->assertSame('application/atom+xml;profile=opds-catalog;kind=acquisition', $this->headersOf($this->controller()->recent(3))['Content-Type']);
	}

	public function testRejectedFilterIsABadRequest(): void {
		$this->active();
		$this->catalog->method('filtered')->willReturn(null);
		$this->assertSame(400, $this->controller()->books('missing:cover')->getStatus());
	}

	private function file(string $content, string $name = 'book.epub'): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturn(strlen($content));
		$file->method('fopen')->willReturnCallback(static function () use ($content) {
			$h = fopen('php://memory', 'w+b');
			fwrite($h, $content);
			rewind($h);
			return $h;
		});
		return $file;
	}

	public function testDownloadStreamsBookOfTheLibrary(): void {
		$this->active();
		$this->books->expects($this->once())->method('findByUserAndFile')->with('alice', 12)->willReturn(new Book());
		$this->library->method('getFileForUser')->with('alice', 12)->willReturn($this->file('0123456789', 'Dune.epub'));
		$this->library->method('canReadContent')->willReturn(true);
		$this->headers['Range'] = 'bytes=2-4';
		$r = $this->controller()->download(12);
		$this->assertInstanceOf(OpdsDownloadResponse::class, $r);
		$this->assertSame(206, $r->getStatus());
		$headers = $r->getOwnHeaders();
		$this->assertSame('application/epub+zip', $headers['Content-Type']);
		$this->assertSame('3', $headers['Content-Length']);
		$this->assertStringContainsString('filename="Dune.epub"', $headers['Content-Disposition']);
	}

	public function testDownloadIgnoresRangeWithIfRange(): void {
		$this->active();
		$this->books->method('findByUserAndFile')->willReturn(new Book());
		$this->library->method('getFileForUser')->willReturn($this->file('0123456789'));
		$this->library->method('canReadContent')->willReturn(true);
		$this->headers['Range'] = 'bytes=2-4';
		$this->headers['If-Range'] = '"abc"';
		$r = $this->controller()->download(12);
		$this->assertSame(200, $r->getStatus());
	}

	public function testDownloadRefusesFilesOfDownloadDisabledShares(): void {
		$this->active();
		$this->books->method('findByUserAndFile')->willReturn(new Book());
		$file = $this->file('secret');
		$file->expects($this->never())->method('fopen');
		$this->library->method('getFileForUser')->willReturn($file);
		$this->library->expects($this->once())->method('canReadContent')->with($file)->willReturn(false);
		$r = $this->controller()->download(12);
		$this->assertInstanceOf(DataDisplayResponse::class, $r);
		$this->assertSame(403, $r->getStatus());
	}

	public function testDownloadOfFileOutsideTheLibraryIs404(): void {
		$this->active();
		$this->books->method('findByUserAndFile')->with('alice', 99)->willThrowException(new DoesNotExistException('x'));
		$this->library->expects($this->never())->method('getFileForUser');
		$this->assertSame(404, $this->controller()->download(99)->getStatus());
	}

	public function testDownloadOfUnreadableFileIs404(): void {
		$this->active();
		$this->books->method('findByUserAndFile')->willReturn(new Book());
		$this->library->method('getFileForUser')->willThrowException(new NotFoundException());
		$this->assertSame(404, $this->controller()->download(12)->getStatus());
	}

	public function testCoverOfForeignBookIs404(): void {
		$this->active();
		$this->books->method('findByUserAndFile')->willThrowException(new DoesNotExistException('x'));
		$this->covers->expects($this->never())->method('getCover');
		$this->assertSame(404, $this->controller()->cover(5)->getStatus());
	}
}
