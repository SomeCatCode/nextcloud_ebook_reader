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
use OCA\EbookReader\Service\ArchiveCache;
use OCA\EbookReader\Service\LibraryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Serves a raw zip entry (comic page thumbnails etc.) of a CBZ or EPUB for the editor.
 */
class ItemController extends Controller {
	private const MAX_ENTRY = 40 * 1024 * 1024;

	/**
	 * Allowlist of types that keep their Content-Type. Everything else (xhtml, html, xml, opf, ncx, svg, css, unknown) is text/plain,
	 * so nothing in a book can run script or style in our origin.
	 * @var array<string, string>
	 */
	private const MIME = [
		'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
		'webp' => 'image/webp', 'avif' => 'image/avif', 'bmp' => 'image/bmp',
		'ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
		'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4',
	];
	private const FALLBACK_MIME = 'text/plain';

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private LibraryService $library,
		private ArchiveCache $archiveCache,
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
	#[UserRateLimit(limit: 1200, period: 60)]
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
		if (!$this->library->canReadContent($file)) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$ext = self::archiveType($file->getName());
		if ($ext === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$path = null;
		try {
			$path = $this->archiveCache->localPath($file);
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
			$mime = self::mimeFor($name);
			$isImage = str_starts_with($mime, 'image/');
			$disposition = ($isImage ? 'inline' : 'attachment') . '; filename="' . self::safeFilename($name) . '"';
			$response = new DataDisplayResponse($data, Http::STATUS_OK, [
				'Content-Type' => $mime,
				'X-Content-Type-Options' => 'nosniff',
			]);
			// DataDisplayResponse sets 'inline; filename=""' in its constructor, so this has to come after it.
			$response->addHeader('Content-Disposition', $disposition);
			// OCP has no sandbox setter and the security middleware rebuilds the policy object (a subclass override of
			// buildPolicy() would be dropped), but explicitly added headers win over the generated one in Response::getHeaders().
			$response->addHeader('Content-Security-Policy', "default-src 'none'; sandbox");
			$response->setETag(md5((string)$file->getEtag() . '|' . $name));
			$response->addHeader('Cache-Control', 'private, max-age=3600');
			return $response;
		} catch (\Throwable $e) {
			$this->logger->info('Cannot serve item ' . $id . ' of file ' . $fileId . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		} finally {
			if ($path !== null) {
				$this->archiveCache->release($path);
			}
		}
	}

	/** "cbz", "epub" or "fbz" for the archives this endpoint serves entries of (fb2.zip counts as fbz), else null. */
	public static function archiveType(string $fileName): ?string {
		$lower = strtolower($fileName);
		if (str_ends_with($lower, '.fb2.zip')) {
			return 'fbz';
		}
		$ext = pathinfo($lower, PATHINFO_EXTENSION);
		return in_array($ext, ['cbz', 'epub', 'fbz'], true) ? $ext : null;
	}

	public static function mimeFor(string $entryName): string {
		return self::MIME[strtolower(pathinfo($entryName, PATHINFO_EXTENSION))] ?? self::FALLBACK_MIME;
	}

	private static function safeFilename(string $entryName): string {
		$base = basename(str_replace('\\', '/', $entryName));
		$clean = preg_replace('/[^A-Za-z0-9._ -]/', '_', $base) ?? '';
		return $clean !== '' ? $clean : 'item';
	}
}
