<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\AppInfo\Application;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Http\OpdsDownloadResponse;
use OCA\EbookReader\Service\CoverService;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\OpdsCatalog;
use OCA\EbookReader\Service\OpdsFeedBuilder;
use OCA\EbookReader\Service\OpdsSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\NotFoundException;
use OCP\IRequest;

/**
 * OPDS 1.2 catalog (Atom) for e-reader apps. Authentication is HTTP Basic with an app password: Nextcloud core logs the request
 * in before the controller runs. The routes are #[PublicPage] only so that core does not redirect to the login form; every
 * action refuses requests without a logged-in user itself (401 + WWW-Authenticate) and requests of users who did not switch the
 * catalog on (or if the administrator disallowed it).
 */
class OpdsController extends Controller {
	public const REALM = 'Nextcloud E-Book Reader';
	public const BRUTEFORCE_ACTION = 'ebookreader_opds';

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private OpdsSettings $settings,
		private OpdsCatalog $catalog,
		private LibraryService $library,
		private BookMapper $bookMapper,
		private CoverService $covers,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Not logged in -> 401 with a Basic challenge (a failed attempt is registered when credentials were sent), disabled -> 403. */
	private function guard(): ?Response {
		if ($this->userId === null || $this->userId === '') {
			$response = new DataDisplayResponse('Authentication required', Http::STATUS_UNAUTHORIZED, [
				'Content-Type' => 'text/plain; charset=utf-8',
				'WWW-Authenticate' => 'Basic realm="' . self::REALM . '", charset="UTF-8"',
				'Cache-Control' => 'no-store',
			]);
			if (trim($this->request->getHeader('Authorization')) !== '') {
				$response->throttle(['action' => self::BRUTEFORCE_ACTION]);
			}
			return $response;
		}
		if (!$this->settings->isActiveFor($this->userId)) {
			return new DataDisplayResponse('The OPDS catalog is not enabled', Http::STATUS_FORBIDDEN, [
				'Content-Type' => 'text/plain; charset=utf-8',
				'Cache-Control' => 'no-store',
			]);
		}
		return null;
	}

	/** @param callable(string): ?string $build gets the user id, returns the XML (null = bad request) */
	private function feed(callable $build, string $kind): Response {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}
		$xml = $build((string)$this->userId);
		if ($xml === null) {
			return new DataDisplayResponse('Bad request', Http::STATUS_BAD_REQUEST, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
		}
		return new DataDisplayResponse($xml, Http::STATUS_OK, [
			'Content-Type' => OpdsFeedBuilder::contentType($kind),
			'Cache-Control' => 'private, no-store',
			'X-Content-Type-Options' => 'nosniff',
		]);
	}

	/** Catalog root (navigation feed). */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::BRUTEFORCE_ACTION)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/opds')]
	public function index(): Response {
		return $this->feed(fn (): string => $this->catalog->root(), OpdsFeedBuilder::KIND_NAVIGATION);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::BRUTEFORCE_ACTION)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/opds/recent')]
	public function recent(int $page = 1): Response {
		return $this->feed(fn (string $u): string => $this->catalog->recent($u, $page), OpdsFeedBuilder::KIND_ACQUISITION);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::BRUTEFORCE_ACTION)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/opds/reading')]
	public function reading(int $page = 1): Response {
		return $this->feed(fn (string $u): string => $this->catalog->reading($u, $page), OpdsFeedBuilder::KIND_ACQUISITION);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::BRUTEFORCE_ACTION)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/opds/all')]
	public function all(int $page = 1): Response {
		return $this->feed(fn (string $u): string => $this->catalog->all($u, $page), OpdsFeedBuilder::KIND_ACQUISITION);
	}

	/**
	 * Books of one filter term, e.g. filter=author:Name, series:Name, genre:Name, tag:Name, shelf:<id>.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::BRUTEFORCE_ACTION)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/opds/books')]
	public function books(string $filter = '', string $sort = '', string $order = 'asc', int $page = 1): Response {
		return $this->feed(fn (string $u): ?string => $this->catalog->filtered($u, $filter, $sort, $order, $page), OpdsFeedBuilder::KIND_ACQUISITION);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::BRUTEFORCE_ACTION)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/opds/authors')]
	public function authors(int $page = 1): Response {
		return $this->feed(fn (string $u): string => $this->catalog->navigation($u, 'authors', $page), OpdsFeedBuilder::KIND_NAVIGATION);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::BRUTEFORCE_ACTION)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/opds/series')]
	public function series(int $page = 1): Response {
		return $this->feed(fn (string $u): string => $this->catalog->navigation($u, 'series', $page), OpdsFeedBuilder::KIND_NAVIGATION);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::BRUTEFORCE_ACTION)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/opds/genres')]
	public function genres(int $page = 1): Response {
		return $this->feed(fn (string $u): string => $this->catalog->navigation($u, 'genres', $page), OpdsFeedBuilder::KIND_NAVIGATION);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::BRUTEFORCE_ACTION)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/opds/tags')]
	public function tags(int $page = 1): Response {
		return $this->feed(fn (string $u): string => $this->catalog->navigation($u, 'tags', $page), OpdsFeedBuilder::KIND_NAVIGATION);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::BRUTEFORCE_ACTION)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/opds/shelves')]
	public function shelves(int $page = 1): Response {
		return $this->feed(fn (string $u): string => $this->catalog->navigation($u, 'shelves', $page), OpdsFeedBuilder::KIND_NAVIGATION);
	}

	/** Search results (OpenSearch template /opds/search?q={searchTerms}). */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::BRUTEFORCE_ACTION)]
	#[UserRateLimit(limit: 120, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/opds/search')]
	public function search(string $q = '', int $page = 1): Response {
		return $this->feed(fn (string $u): string => $this->catalog->search($u, $q, $page), OpdsFeedBuilder::KIND_ACQUISITION);
	}

	/** OpenSearch description document. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::BRUTEFORCE_ACTION)]
	#[UserRateLimit(limit: 120, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/opds/opensearch.xml')]
	public function opensearch(): Response {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}
		return new DataDisplayResponse($this->catalog->openSearch(), Http::STATUS_OK, [
			'Content-Type' => 'application/opensearchdescription+xml',
			'Cache-Control' => 'private, no-store',
		]);
	}

	/**
	 * Download of a book of the user's library. Refuses files from shares without download permission.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::BRUTEFORCE_ACTION)]
	#[UserRateLimit(limit: 120, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/opds/download/{fileId}', requirements: ['fileId' => '\d+'])]
	public function download(int $fileId): Response {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}
		$userId = (string)$this->userId;
		try {
			// only books indexed in the library of this user, no arbitrary file ids
			$this->bookMapper->findByUserAndFile($userId, $fileId);
			$file = $this->library->getFileForUser($userId, $fileId);
		} catch (DoesNotExistException|NotFoundException) {
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
		}
		if (!$this->library->canReadContent($file)) {
			return new DataDisplayResponse('Download not permitted', Http::STATUS_FORBIDDEN, ['Content-Type' => 'text/plain; charset=utf-8']);
		}
		$handle = $file->fopen('rb');
		if (!is_resource($handle)) {
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
		}
		$ifRange = trim($this->request->getHeader('If-Range'));
		$range = $ifRange === '' ? $this->request->getHeader('Range') : '';
		return new OpdsDownloadResponse(
			$handle,
			(int)$file->getSize(),
			$file->getName(),
			OpdsFeedBuilder::mimeFor(strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION))),
			$range === '' ? null : $range,
		);
	}

	/**
	 * Cover image reachable with Basic auth.
	 *
	 * @param string $size small|large
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::BRUTEFORCE_ACTION)]
	#[UserRateLimit(limit: 600, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/opds/cover/{fileId}', requirements: ['fileId' => '\d+'])]
	public function cover(int $fileId, string $size = 'small'): Response {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}
		try {
			$this->bookMapper->findByUserAndFile((string)$this->userId, $fileId);
		} catch (DoesNotExistException) {
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
		}
		$cover = $this->covers->getCover($fileId, $size === 'large' ? 'large' : 'small');
		if ($cover === null) {
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
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
}
