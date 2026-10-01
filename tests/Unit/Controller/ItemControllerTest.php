<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\ItemController;
use OCA\EbookReader\Service\ArchiveCache;
use OCA\EbookReader\Service\LibraryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\Files\File;
use OCP\Files\Storage\IStorage;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Regression tests A1 (MIME allowlist, headers) and A2 (view-only shares) for /item. */
class ItemControllerTest extends TestCase {
	private LibraryService&MockObject $library;
	/** @var list<string> */
	private array $tmp = [];

	protected function setUp(): void {
		$this->library = $this->createMock(LibraryService::class);
	}

	protected function tearDown(): void {
		foreach ($this->tmp as $f) {
			@unlink($f);
		}
	}

	/** @return array<string, array{string, string}> */
	public static function mimes(): array {
		return [
			'opf' => ['content.opf', 'text/plain'],
			'ncx' => ['toc.ncx', 'text/plain'],
			'xhtml' => ['ch1.xhtml', 'text/plain'],
			'html' => ['a.html', 'text/plain'],
			'svg' => ['cover.svg', 'text/plain'],
			'xml' => ['a.xml', 'text/plain'],
			'css' => ['a.css', 'text/plain'],
			'unknown' => ['a.bin', 'text/plain'],
			'no extension' => ['mimetype', 'text/plain'],
			'png' => ['p.png', 'image/png'],
			'jpg upper case' => ['p.JPG', 'image/jpeg'],
			'font' => ['f.woff2', 'font/woff2'],
			'audio' => ['a.mp3', 'audio/mpeg'],
		];
	}

	#[DataProvider('mimes')]
	public function testMimeAllowlist(string $entry, string $expected): void {
		$this->assertSame($expected, ItemController::mimeFor($entry));
	}

	private function controller(): ItemController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return new ItemController($this->createMock(IRequest::class), $session, $this->library, new ArchiveCache($this->createMock(ITempManager::class), $this->createMock(IAppConfig::class), $this->createMock(LoggerInterface::class)), $this->createMock(LoggerInterface::class));
	}

	private function epub(string $entry, string $content): File&MockObject {
		$path = tempnam(sys_get_temp_dir(), 'epub');
		$this->tmp[] = $path;
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		$zip->addFromString($entry, $content);
		$zip->close();
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn($path);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('b.epub');
		$file->method('getEtag')->willReturn('e');
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/b.epub');
		return $file;
	}

	/** @return array<string, string> */
	private function rawHeaders(Http\Response $r): array {
		/** @var array<string, string> $h */
		$h = (new \ReflectionProperty(Http\Response::class, 'headers'))->getValue($r);
		return $h;
	}

	public function testActiveContentIsServedAsAttachmentWithSandboxCsp(): void {
		$this->library->method('canReadContent')->willReturn(true);
		$this->library->method('getFileForUser')->willReturn($this->epub('OEBPS/cover.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'));
		$r = $this->controller()->item(7, 'OEBPS/cover.svg');
		$this->assertInstanceOf(DataDisplayResponse::class, $r);
		$h = $this->rawHeaders($r);
		$this->assertSame('text/plain', $h['Content-Type']);
		$this->assertSame('nosniff', $h['X-Content-Type-Options']);
		$this->assertSame('attachment; filename="cover.svg"', $h['Content-Disposition']);
		$this->assertSame("default-src 'none'; sandbox", $h['Content-Security-Policy']);
	}

	public function testImageIsInline(): void {
		$this->library->method('canReadContent')->willReturn(true);
		$this->library->method('getFileForUser')->willReturn($this->epub('img/p.png', 'x'));
		$r = $this->controller()->item(7, 'img/p.png');
		$h = $this->rawHeaders($r);
		$this->assertSame('image/png', $h['Content-Type']);
		$this->assertSame('inline; filename="p.png"', $h['Content-Disposition']);
	}

	public function testViewOnlyShareIsForbidden(): void {
		$this->library->method('canReadContent')->willReturn(false);
		$this->library->method('getFileForUser')->willReturn($this->epub('img/p.png', 'x'));
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->item(7, 'img/p.png')->getStatus());
	}
}
