<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Controller;

use OCA\EbookReader\Controller\PublicReaderController;
use OCA\EbookReader\Http\OpdsDownloadResponse;
use OCA\EbookReader\Metadata\MetadataService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\ISession;
use OCP\L10N\IFactory;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IAttributes;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PublicReaderControllerTest extends TestCase {
	/** @var array<string, mixed> */
	private array $state = [];

	private function file(string $path): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn($path);
		$file->method('getName')->willReturn(basename($path));
		$file->method('getMimeType')->willReturn('application/epub+zip');
		$file->method('getSize')->willReturn(3);
		$file->method('getEtag')->willReturn('e1');
		$file->method('isReadable')->willReturn(true);
		$file->method('fopen')->willReturnCallback(static function () {
			$h = fopen('php://memory', 'r+');
			fwrite($h, 'abc');
			rewind($h);
			return $h;
		});
		return $file;
	}

	private function share(Node $node, bool $hideDownload = false, ?bool $downloadAttribute = null, int $permissions = Constants::PERMISSION_READ): IShare&MockObject {
		$share = $this->createMock(IShare::class);
		$share->method('getNode')->willReturn($node);
		$share->method('getPermissions')->willReturn($permissions);
		$share->method('getHideDownload')->willReturn($hideDownload);
		$share->method('getPassword')->willReturn(null);
		$share->method('canSeeContent')->willReturn(true);
		$attributes = $this->createMock(IAttributes::class);
		$attributes->method('getAttribute')->willReturn($downloadAttribute);
		$share->method('getAttributes')->willReturn($attributes);
		return $share;
	}

	private function controller(?IShare $share): PublicReaderController {
		$manager = $this->createMock(IManager::class);
		if ($share === null) {
			$manager->method('getShareByToken')->willThrowException(new ShareNotFound());
		} else {
			$manager->method('getShareByToken')->willReturn($share);
		}
		$metadata = $this->createMock(MetadataService::class);
		$metadata->method('detectFormat')->willReturnCallback(static fn (string $name): ?string => str_ends_with($name, '.epub') ? 'epub' : null);
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(function (string $key, mixed $value): void {
			$this->state[$key] = $value;
		});
		$l10n = $this->createMock(IFactory::class);
		$l10n->method('findLanguage')->willReturn('de');
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$controller = new class($request, $this->createMock(ISession::class), $manager, $metadata, $initialState, $l10n) extends PublicReaderController {
			protected function addAssets(): void {
			}
		};
		$controller->setToken('tok');
		return $controller;
	}

	public function testUnknownTokenAndShareWithoutReadPermissionAreInvalid(): void {
		$this->assertFalse($this->controller(null)->isValidToken());
		$this->assertFalse($this->controller($this->share($this->file('/u/files/b.epub'), false, null, Constants::PERMISSION_CREATE))->isValidToken());
	}

	public function testSharedFileIsServed(): void {
		$controller = $this->controller($this->share($this->file('/u/files/b.epub')));
		$this->assertTrue($controller->isValidToken());

		$page = $controller->show('tok');
		$this->assertInstanceOf(TemplateResponse::class, $page);
		$this->assertSame(Http::STATUS_OK, $page->getStatus());
		$this->assertSame(['token' => 'tok', 'path' => '', 'name' => 'b.epub', 'format' => 'epub', 'size' => 3, 'etag' => 'e1', 'downloadable' => true], $this->state['public-book']);

		$file = $controller->file('tok');
		$this->assertInstanceOf(OpdsDownloadResponse::class, $file);
		$this->assertSame(3, $file->getLength());
	}

	public function testBookInsideSharedFolderIsFoundByPath(): void {
		$book = $this->file('/u/files/Shared/sub/b.epub');
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/u/files/Shared');
		$folder->method('get')->willReturnCallback(static fn (string $p): Node => $p === 'sub/b.epub' ? $book : throw new NotFoundException());
		$controller = $this->controller($this->share($folder));
		$controller->isValidToken();

		$this->assertInstanceOf(OpdsDownloadResponse::class, $controller->file('tok', '/sub/b.epub'));
		$controller->show('tok', '/sub/b.epub');
		$this->assertSame('/sub/b.epub', $this->state['public-book']['path']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->file('tok', '/sub/../../other/b.epub')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->file('tok', '/missing.epub')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->file('tok', '')->getStatus());
	}

	public function testFileOutsideTheSharedFolderIsRefused(): void {
		// a node that is not below the share root (e.g. reached through a mount) is never served
		$outside = $this->file('/u/files/Other/b.epub');
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/u/files/Shared');
		$folder->method('get')->willReturn($outside);
		$controller = $this->controller($this->share($folder));
		$controller->isValidToken();
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->file('tok', '/b.epub')->getStatus());
	}

	public function testViewOnlySharesDoNotHandOutTheFile(): void {
		foreach ([[true, null], [false, false]] as [$hide, $download]) {
			$controller = $this->controller($this->share($this->file('/u/files/b.epub'), $hide, $download));
			$controller->isValidToken();
			$this->assertSame(Http::STATUS_FORBIDDEN, $controller->file('tok')->getStatus());
			$controller->show('tok');
			$this->assertFalse($this->state['public-book']['downloadable']);
		}
	}

	public function testNonBookIsNotFound(): void {
		$controller = $this->controller($this->share($this->file('/u/files/notes.txt')));
		$controller->isValidToken();
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->show('tok')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->file('tok')->getStatus());
	}

	public function testSegmentsRejectEscapes(): void {
		$this->assertSame(['a', 'b.epub'], PublicReaderController::segments('/a//./b.epub'));
		$this->assertNull(PublicReaderController::segments('/a/../b.epub'));
		$this->assertNull(PublicReaderController::segments('/a\\b.epub'));
	}
}
