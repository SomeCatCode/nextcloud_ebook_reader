<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\AppInfo\Application;
use OCA\EbookReader\Http\OpdsDownloadResponse;
use OCA\EbookReader\Metadata\MetadataService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\PublicShareController;
use OCP\AppFramework\Services\IInitialState;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\ISession;
use OCP\L10N\IFactory;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\Util;

/**
 * Reader for books in a public link share (/s/<token>), without login. Read only: no library, no progress, no annotations on
 * the server (the browser keeps the position). The file is only handed out when the share allows downloads; a password
 * protected share needs the password entered on the share page first (same session as Nextcloud's public WebDAV).
 */
class PublicReaderController extends PublicShareController {
	private const RTL_LANGUAGES = ['ar', 'fa', 'he', 'ps', 'ug', 'ur'];

	private ?IShare $share = null;

	public function __construct(
		IRequest $request,
		ISession $session,
		private IManager $shareManager,
		private MetadataService $metadata,
		private IInitialState $initialState,
		private IFactory $l10nFactory,
	) {
		parent::__construct(Application::APP_ID, $request, $session);
	}

	#[\Override]
	public function isValidToken(): bool {
		$this->share = null;
		try {
			$share = $this->shareManager->getShareByToken($this->getToken());
		} catch (ShareNotFound) {
			return false;
		}
		if (($share->getPermissions() & Constants::PERMISSION_READ) === 0) {
			return false;
		}
		$this->share = $share;
		return true;
	}

	#[\Override]
	protected function getPasswordHash(): ?string {
		return $this->share?->getPassword();
	}

	#[\Override]
	protected function isPasswordProtected(): bool {
		return $this->share?->getPassword() !== null;
	}

	/**
	 * Reader page for a book in the share.
	 *
	 * @param string $token Share token
	 * @param string $path Path of the book inside a shared folder (ignored for a shared file)
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/s/{token}')]
	public function show(string $token, string $path = ''): TemplateResponse {
		$file = $this->resolve($path);
		$format = $file === null ? null : $this->metadata->detectFormat($file->getName(), $file->getMimeType());
		if ($file === null || $format === null) {
			return new TemplateResponse('core', '404', [], TemplateResponse::RENDER_AS_GUEST, Http::STATUS_NOT_FOUND);
		}
		$this->initialState->provideInitialState('public-book', [
			'token' => $token,
			'path' => $this->relativePath($file),
			'name' => $file->getName(),
			'format' => $format,
			'size' => (int)$file->getSize(),
			'etag' => (string)$file->getEtag(),
			'downloadable' => $this->downloadable(),
		]);
		$language = $this->l10nFactory->findLanguage(Application::APP_ID);
		$this->initialState->provideInitialState('l10n', [
			'language' => $language,
			'rtl' => in_array(strtolower(explode('_', $language)[0]), self::RTL_LANGUAGES, true),
		]);
		$this->addAssets();
		// base layout: the page is shown inside the Viewer on the share page, or on its own without the Nextcloud header
		return new TemplateResponse(Application::APP_ID, 'main', [], TemplateResponse::RENDER_AS_BASE);
	}

	/**
	 * The book file (single HTTP byte ranges are supported).
	 *
	 * @param string $token Share token
	 * @param string $path Path of the book inside a shared folder (ignored for a shared file)
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/s/{token}/file')]
	public function file(string $token, string $path = ''): Response {
		$file = $this->resolve($path);
		if ($file === null || $this->metadata->detectFormat($file->getName(), $file->getMimeType()) === null) {
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
		}
		if (!$this->downloadable()) {
			return new DataDisplayResponse('Download disabled', Http::STATUS_FORBIDDEN, ['Content-Type' => 'text/plain; charset=utf-8']);
		}
		try {
			$handle = $file->fopen('rb');
		} catch (\Throwable) {
			$handle = false;
		}
		if (!is_resource($handle)) {
			return new DataDisplayResponse('Not readable', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
		}
		$ifRange = trim($this->request->getHeader('If-Range'));
		$range = $ifRange === '' ? $this->request->getHeader('Range') : '';
		return new OpdsDownloadResponse($handle, (int)$file->getSize(), $file->getName(), 'application/octet-stream', $range !== '' ? $range : null);
	}

	/** Translations, script and style of the reader (a separate method, so tests can run without a server). */
	protected function addAssets(): void {
		Util::addTranslations(Application::APP_ID);
		Util::addScript(Application::APP_ID, 'ebookreader-main');
		Util::addStyle(Application::APP_ID, 'ebookreader-main');
	}

	/**
	 * A share with "hide download" or downloads disabled is view-only: the reader would hand out the whole book, so it
	 * is not offered (same rule as for shares to users, see LibraryService::canReadContent).
	 */
	private function downloadable(): bool {
		$share = $this->share;
		if ($share === null || $share->getHideDownload()) {
			return false;
		}
		if ($share->getAttributes()?->getAttribute('permissions', 'download') === false) {
			return false;
		}
		return $share->canSeeContent();
	}

	/** The shared file, or the file at $path inside the shared folder; null when there is none or it lies outside. */
	private function resolve(string $path): ?File {
		$share = $this->share;
		if ($share === null) {
			return null;
		}
		try {
			$root = $share->getNode();
		} catch (NotFoundException) {
			return null;
		}
		if ($root instanceof File) {
			return $root->isReadable() ? $root : null;
		}
		if (!$root instanceof Folder) {
			return null;
		}
		$segments = self::segments($path);
		if ($segments === null || $segments === []) {
			return null;
		}
		try {
			$node = $root->get(implode('/', $segments));
		} catch (NotFoundException) {
			return null;
		}
		$rootPath = rtrim($root->getPath(), '/') . '/';
		if (!$node instanceof File || !str_starts_with($node->getPath(), $rootPath) || !$node->isReadable()) {
			return null;
		}
		return $node;
	}

	/**
	 * Path segments, or null for a path that tries to leave the share (.., NUL, backslash).
	 *
	 * @return list<string>|null
	 */
	public static function segments(string $path): ?array {
		$out = [];
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..' || str_contains($segment, "\0") || str_contains($segment, '\\')) {
				return null;
			}
			$out[] = $segment;
		}
		return $out;
	}

	private function relativePath(File $file): string {
		try {
			$root = $this->share?->getNode();
		} catch (NotFoundException) {
			return '';
		}
		if ($root === null || $root instanceof File) {
			return '';
		}
		return substr($file->getPath(), strlen(rtrim($root->getPath(), '/')));
	}
}
