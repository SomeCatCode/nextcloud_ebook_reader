<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\AppInfo\Application;
use OCA\EbookReader\Editor\EditorUtil;
use OCA\EbookReader\Service\ArchiveCache;
use OCA\EbookReader\Service\LibraryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Entry list (names and sizes) of a ZIP based book (EPUB, CBZ, FBZ). The reader uses it to fetch single entries
 * through /item/{fileId} instead of downloading the whole file.
 */
class ArchiveController extends Controller {
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
	 * Entry names and (unpacked) sizes of an EPUB, CBZ or FBZ file.
	 *
	 * @param int $fileId File id
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/archive/{fileId}/entries', requirements: ['fileId' => '\d+'])]
	public function entries(int $fileId): JSONResponse|Response {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		try {
			$file = $this->library->getFileForUser($user->getUID(), $fileId);
		} catch (NotFoundException) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		if (!$this->library->canReadContent($file)) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}
		if (ItemController::archiveType($file->getName()) === null) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		$etag = (string)$file->getEtag();
		$httpEtag = md5('entries|' . $etag);
		if (trim($this->request->getHeader('If-None-Match'), '"') === $httpEtag) {
			$notModified = new Response();
			$notModified->setStatus(Http::STATUS_NOT_MODIFIED);
			return $notModified;
		}

		$path = null;
		try {
			$path = $this->archiveCache->localPath($file);
			$zip = EditorUtil::openZip($path);
			$entries = [];
			try {
				for ($i = 0; $i < $zip->numFiles; $i++) {
					$stat = $zip->statIndex($i);
					$name = is_array($stat) ? (string)$stat['name'] : '';
					if ($name === '' || str_ends_with($name, '/') || !EditorUtil::isSafeName($name)) {
						continue;
					}
					$entries[] = ['name' => $name, 'size' => (int)$stat['size']];
				}
			} finally {
				$zip->close();
			}
		} catch (\Throwable $e) {
			$this->logger->info('Cannot list the entries of file ' . $fileId . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return new JSONResponse([], Http::STATUS_UNPROCESSABLE_ENTITY);
		} finally {
			if ($path !== null) {
				$this->archiveCache->release($path);
			}
		}
		$response = new JSONResponse(['etag' => $etag, 'entries' => $entries]);
		$response->setETag($httpEtag);
		$response->addHeader('Cache-Control', 'private, max-age=300');
		return $response;
	}
}
