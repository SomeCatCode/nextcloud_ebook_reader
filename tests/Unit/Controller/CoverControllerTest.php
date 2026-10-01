<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\CoverController;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Service\CoverService;
use OCA\EbookReader\Service\LibraryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CoverControllerTest extends TestCase {
	/** 1x1 GIF */
	private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

	private LibraryService&MockObject $library;
	private CoverService&MockObject $covers;
	private BookMapper&MockObject $books;
	private IRequest&MockObject $request;
	private string $body = '';

	protected function setUp(): void {
		$this->library = $this->createMock(LibraryService::class);
		$this->covers = $this->createMock(CoverService::class);
		$this->books = $this->createMock(BookMapper::class);
		$this->request = $this->createMock(IRequest::class);
	}

	private function controller(?string $user = 'u'): CoverController {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(\DateTimeImmutable::createFromFormat('U.u', '1800000000.000000'));
		$body = &$this->body;
		return new class($this->request, $user, $this->library, $this->covers, $this->books, $time, $body) extends CoverController {
			public function __construct(
				IRequest $r,
				?string $u,
				LibraryService $l,
				CoverService $c,
				BookMapper $b,
				ITimeFactory $t,
				private string &$fake,
			) {
				parent::__construct($r, $u, $l, $c, $b, $t);
			}

			protected function readBody(): string {
				return $this->fake;
			}
		};
	}

	private function cover(string $etag = 'abc'): ISimpleFile&MockObject {
		$f = $this->createMock(ISimpleFile::class);
		$f->method('getETag')->willReturn($etag);
		$f->method('getName')->willReturn('cover.jpg');
		$f->method('getMTime')->willReturn(1000);
		$f->method('getMimeType')->willReturn('image/jpeg');
		return $f;
	}

	private function writable(): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(true);
		return $file;
	}

	public function testShowReturnsFileWithCacheHeaders(): void {
		$this->covers->method('getCover')->willReturn($this->cover());
		$r = $this->controller()->show(5, 'large');
		$this->assertInstanceOf(FileDisplayResponse::class, $r);
		// getHeaders() needs a running server, read the raw header list instead
		$headers = (new \ReflectionProperty(Http\Response::class, 'headers'))->getValue($r);
		$this->assertSame('private, max-age=86400', $headers['Cache-Control']);
		$this->assertSame('abc', $r->getETag());
	}

	public function testShowNotModified(): void {
		$this->covers->method('getCover')->willReturn($this->cover());
		$this->request->method('getHeader')->with('If-None-Match')->willReturn('"abc"');
		$r = $this->controller()->show(5);
		$this->assertSame(Http::STATUS_NOT_MODIFIED, $r->getStatus());
	}

	public function testShowWithoutAccessIs404(): void {
		$this->library->method('getFileForUser')->willThrowException(new NotFoundException());
		$this->covers->expects($this->never())->method('getCover');
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->show(5)->getStatus());
	}

	public function testShowWithoutCoverIs404(): void {
		$this->covers->method('getCover')->willReturn(null);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->show(5)->getStatus());
	}

	public function testUploadRejectsNonImage(): void {
		$this->library->method('getFileForUser')->willReturn($this->writable());
		$this->books->method('findByUserAndFile')->willReturn(new Book());
		$this->covers->expects($this->never())->method('storeCover');
		$this->body = 'this is not an image';
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->upload(5)->getStatus());
	}

	public function testUploadRejectsTooLargeByHeader(): void {
		$this->library->method('getFileForUser')->willReturn($this->writable());
		$this->books->method('findByUserAndFile')->willReturn(new Book());
		$this->request->method('getHeader')->willReturn((string)(CoverController::MAX_UPLOAD_BYTES + 1));
		$this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $this->controller()->upload(5)->getStatus());
	}

	public function testUploadStoresCoverAndUpdatesBooks(): void {
		$this->library->method('getFileForUser')->willReturn($this->writable());
		$this->books->method('findByUserAndFile')->willReturn(new Book());
		$row = new Book();
		$this->books->method('findByFileId')->willReturn([$row]);
		$this->books->expects($this->once())->method('update');
		$this->covers->expects($this->once())->method('storeCover')->with(5)->willReturn('etag1');
		$this->body = base64_decode(self::GIF);

		$r = $this->controller()->upload(5);
		$this->assertSame(Http::STATUS_OK, $r->getStatus());
		$this->assertSame(['coverEtag' => 'etag1'], $r->getData());
		$this->assertTrue($row->getHasCover());
		$this->assertSame('etag1', $row->getCoverEtag());
	}

	public function testUploadForbiddenWhenCoverExistsAndFileReadOnly(): void {
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(false);
		$this->library->method('getFileForUser')->willReturn($file);
		$book = new Book();
		$book->setHasCover(true);
		$this->books->method('findByUserAndFile')->willReturn($book);
		$this->body = base64_decode(self::GIF);
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->upload(5)->getStatus());
	}

	public function testUploadForbiddenWithoutWritePermissionEvenWithoutExistingCover(): void {
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(false);
		$this->library->method('getFileForUser')->willReturn($file);
		$this->books->method('findByUserAndFile')->willReturn(new Book());
		$this->covers->expects($this->never())->method('storeCover');
		$this->body = base64_decode(self::GIF);
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->upload(5)->getStatus());
	}
}
