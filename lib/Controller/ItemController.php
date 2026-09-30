<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\AppInfo\Application;
use OCA\EbookReader\Editor\EditorUtil;
use OCA\EbookReader\Editor\EpubEditor;
use OCA\EbookReader\Service\LibraryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Serves a raw zip entry (comic page thumbnails etc.) of a CBZ or EPUB for the editor.
 */
class ItemController extends Controller {
	private const MAX_ENTRY = 40 * 1024 * 1024;

	/** @var array<string, string> */
	private const MIME = [
		'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
		'webp' => 'image/webp', 'avif' => 'image/avif', 'bmp' => 'image/bmp', 'svg' => 'image/svg+xml',
		'css' => 'text/css', 'xhtml' => 'application/xhtml+xml', 'html' => 'text/html', 'htm' => 'text/html',
		'xml' => 'application/xml', 'ncx' => 'application/x-dtbncx+xml', 'opf' => 'application/oebps-package+xml',
		'ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
		'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4',
	];
	/** Types that could run script in our origin are delivered as plain text. */
	private const ACTIVE = ['image/svg+xml', 'text/html', 'application/xhtml+xml', 'application/xml'];

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private LibraryService $library,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @param int $fileId File id
	 * @param string $id Item id (zip entry name; for EPUB also a manifest id)
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/item/{fileId}', requirements: ['fileId' => '\d+'])]
	public function item(int $fileId, string $id = ''): DataResponse|DataDisplayResponse {
		$user = $this->userSession->getUser();
		if ($user === null || $id === '') {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		try {
			$file = $this->library->getFileForUser($user->getUID(), $fileId);
		} catch (NotFoundException) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$ext = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
		if ($ext !== 'cbz' && $ext !== 'epub') {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$tmp = null;
		try {
			[$path, $tmp] = $this->localPath($file);
			$zip = EditorUtil::openZip($path);
			try {
				$name = $id;
				if (!EditorUtil::isSafeName($name) || $zip->locateName($name) === false) {
					$resolved = $ext === 'epub' ? (new EpubEditor())->pathForId($path, $id) : null;
					if ($resolved === null) {
						return new DataResponse([], Http::STATUS_NOT_FOUND);
					}
					$name = $resolved;
				}
				$data = EditorUtil::readEntry($zip, $name, self::MAX_ENTRY);
			} finally {
				$zip->close();
			}
			if ($data === null) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}
			$mime = self::MIME[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
			if (in_array($mime, self::ACTIVE, true)) {
				$mime = 'text/plain';
			}
			$response = new DataDisplayResponse($data, Http::STATUS_OK, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff']);
			$response->setContentSecurityPolicy(new EmptyContentSecurityPolicy());
			$response->setETag(md5((string)$file->getEtag() . '|' . $name));
			$response->addHeader('Cache-Control', 'private, max-age=3600');
			return $response;
		} catch (\Throwable $e) {
			$this->logger->info('Cannot serve item ' . $id . ' of file ' . $fileId . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		} finally {
			if ($tmp !== null) {
				@unlink($tmp);
			}
		}
	}

	/**
	 * Local path of the file: direct for local storages, otherwise a temporary copy.
	 * @return array{0: string, 1: ?string} path and temp file to delete
	 */
	private function localPath(File $file): array {
		$storage = $file->getStorage();
		if ($storage->isLocal()) {
			$local = $storage->getLocalFile($file->getInternalPath());
			if (is_string($local) && is_file($local)) {
				return [$local, null];
			}
		}
		$tmp = $this->tempManager->getTemporaryFile('.zip');
		if ($tmp === false) {
			throw new \RuntimeException('temp file');
		}
		$in = $file->fopen('r');
		$out = fopen($tmp, 'wb');
		if ($in === false || $out === false) {
			throw new \RuntimeException('cannot read file');
		}
		stream_copy_to_stream($in, $out);
		fclose($in);
		fclose($out);
		return [$tmp, $tmp];
	}
}
