<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\AppInfo\Application;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Service\CoverService;
use OCA\EbookReader\Service\LibraryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\NotFoundException;
use OCP\IRequest;

/**
 * Cover images are served by a normal controller (no OCS envelope).
 */
class CoverController extends Controller {
	public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;
	private const IMAGE_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private LibraryService $library,
		private CoverService $covers,
		private BookMapper $bookMapper,
		private ITimeFactory $time,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @param string $size small|large
	 * @return FileDisplayResponse<Http::STATUS_OK, array<string, mixed>>|Response
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/cover/{fileId}', requirements: ['fileId' => '\d+'])]
	public function show(int $fileId, string $size = 'small'): Response {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		if ($size !== 'small' && $size !== 'large') {
			$size = 'small';
		}
		try {
			$this->library->getFileForUser($this->userId, $fileId);
		} catch (NotFoundException) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$cover = $this->covers->getCover($fileId, $size);
		if ($cover === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$etag = $cover->getETag();
		$headers = ['Cache-Control' => 'private, max-age=86400'];
		$ifNoneMatch = trim($this->request->getHeader('If-None-Match'));
		if ($ifNoneMatch !== '' && trim(preg_replace('#^W/#', '', $ifNoneMatch) ?? '', '"') === $etag) {
			$response = new Response(Http::STATUS_NOT_MODIFIED, $headers);
			$response->setETag($etag);
			return $response;
		}
		$response = new FileDisplayResponse($cover, Http::STATUS_OK, $headers);
		$response->addHeader('Content-Type', $cover->getMimeType());
		return $response;
	}

	/**
	 * Upload a client generated cover (raw image body, max 10 MB), e.g. for CBR files
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/cover/{fileId}', requirements: ['fileId' => '\d+'])]
	public function upload(int $fileId): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['message' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$file = $this->library->getFileForUser($this->userId, $fileId);
			$book = $this->bookMapper->findByUserAndFile($this->userId, $fileId);
		} catch (NotFoundException|DoesNotExistException) {
			return new JSONResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		if ($book->getHasCover() && !$file->isUpdateable()) {
			return new JSONResponse(['message' => 'Cover cannot be replaced'], Http::STATUS_FORBIDDEN);
		}

		$length = (int)$this->request->getHeader('Content-Length');
		if ($length > self::MAX_UPLOAD_BYTES) {
			return new JSONResponse(['message' => 'Image too large'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		}
		$data = $this->readBody();
		if ($data === '') {
			return new JSONResponse(['message' => 'Empty body'], Http::STATUS_BAD_REQUEST);
		}
		if (strlen($data) > self::MAX_UPLOAD_BYTES) {
			return new JSONResponse(['message' => 'Image too large'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		}
		$info = @getimagesizefromstring($data);
		if ($info === false || !in_array($info[2], self::IMAGE_TYPES, true)) {
			return new JSONResponse(['message' => 'Not a supported image'], Http::STATUS_BAD_REQUEST);
		}

		$etag = $this->covers->storeCover($fileId, $data);
		$now = (int)$this->time->now()->format('Uv');
		foreach ($this->bookMapper->findByFileId($fileId) as $row) {
			$row->setHasCover(true);
			$row->setCoverEtag($etag);
			$row->setUpdatedAt($now);
			$this->bookMapper->update($row);
		}
		return new JSONResponse(['coverEtag' => $etag]);
	}

	/** Reads at most MAX_UPLOAD_BYTES + 1 bytes of the raw request body. */
	protected function readBody(): string {
		$in = fopen('php://input', 'rb');
		if ($in === false) {
			return '';
		}
		$data = stream_get_contents($in, self::MAX_UPLOAD_BYTES + 1);
		fclose($in);
		return $data === false ? '' : $data;
	}
}
