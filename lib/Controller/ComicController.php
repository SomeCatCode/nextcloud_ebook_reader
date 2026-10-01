<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\AppInfo\Application;
use OCA\EbookReader\Editor\EditorUtil;
use OCA\EbookReader\Metadata\ComicArchive;
use OCA\EbookReader\Service\ArchiveTools;
use OCA\EbookReader\Service\LibraryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Serves comics (CBZ, CBT, and CBR/CB7 when a tool is installed) page by page, so the browser does not have to download and unpack
 * the whole archive. Pages are scaled down to the requested width bucket and cached in app data.
 */
class ComicController extends Controller {
	private const IMAGE_EXT = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
		'webp' => 'image/webp', 'avif' => 'image/avif', 'bmp' => 'image/bmp'];
	/** Requested widths are rounded up to one of these, so the cache is shared between devices */
	private const WIDTHS = [800, 1200, 1600, 2000, 2400];
	private const MAX_PAGE = 60 * 1024 * 1024;
	private const CACHE_FOLDER = 'comic-pages';

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private LibraryService $library,
		private ITempManager $tempManager,
		private IAppData $appData,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
		private ArchiveTools $archiveTools,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Page list of a comic in reading order (natural sort, like the editor and foliate).
	 *
	 * @param int $fileId File id
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/comic/{fileId}/pages', requirements: ['fileId' => '\d+'])]
	public function pages(int $fileId): JSONResponse {
		$file = $this->comicFile($fileId);
		if ($file === null) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		try {
			$pages = $this->pageList($file);
		} catch (\Throwable $e) {
			$this->logger->info('Cannot list comic pages of ' . $fileId . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return new JSONResponse([], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		$response = new JSONResponse(['etag' => (string)$file->getEtag(), 'pages' => $pages]);
		$response->setETag(md5((string)$file->getEtag()));
		$response->addHeader('Cache-Control', 'private, max-age=300');
		return $response;
	}

	/**
	 * One page image, scaled down to width $w (rounded up to a bucket; 0 = original).
	 *
	 * @param int $fileId File id
	 * @param int $index 0-based page index in the list returned by pages()
	 * @param int $w Target width in device pixels
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/comic/{fileId}/page/{index}', requirements: ['fileId' => '\d+', 'index' => '\d+'])]
	public function page(int $fileId, int $index, int $w = 0): DataDisplayResponse|JSONResponse|Response {
		$file = $this->comicFile($fileId);
		if ($file === null) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		$width = $this->bucket($w);
		$etag = md5((string)$file->getEtag() . '|' . $index . '|' . $width);
		if (trim($this->request->getHeader('If-None-Match'), '"') === $etag) {
			$notModified = new Response();
			$notModified->setStatus(Http::STATUS_NOT_MODIFIED);
			return $notModified;
		}
		try {
			$pages = $this->pageList($file);
			if (!isset($pages[$index])) {
				return new JSONResponse([], Http::STATUS_NOT_FOUND);
			}
			$name = $pages[$index]['name'];
			$cacheName = $fileId . '-' . substr(md5((string)$file->getEtag()), 0, 12) . '-' . $index . '-' . $width . '.img';
			$folder = $this->cacheFolder();
			[$data, $mime] = $this->cached($folder, $cacheName) ?? $this->render($file, $name, $width, $folder, $cacheName);
		} catch (\Throwable $e) {
			$this->logger->info('Cannot serve comic page ' . $index . ' of ' . $fileId . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		$response = new DataDisplayResponse($data, Http::STATUS_OK, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff']);
		$response->setETag($etag);
		$response->addHeader('Cache-Control', 'private, max-age=604800, immutable');
		return $response;
	}

	private function comicFile(int $fileId): ?File {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}
		try {
			$file = $this->library->getFileForUser($user->getUID(), $fileId);
		} catch (NotFoundException) {
			return null;
		}
		$format = $this->formatOf($file);
		// CBZ and CBT are read in PHP; CBR/CB7 only if a tool (7z/unrar/bsdtar) is installed. Otherwise 404:
		// the client then downloads the file and unpacks it in the browser.
		return $format !== null && $this->archiveTools->canRead($format) ? $file : null;
	}

	private function formatOf(File $file): ?string {
		$ext = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
		return in_array($ext, ['cbz', 'cbt', 'cbr', 'cb7'], true) ? $ext : null;
	}

	private function bucket(int $w): int {
		if ($w <= 0) {
			return 0;
		}
		foreach (self::WIDTHS as $bucket) {
			if ($w <= $bucket) {
				return $bucket;
			}
		}
		return 0;
	}

	/** @return list<array{name: string, size: int}> */
	private function pageList(File $file): array {
		$cache = $this->cacheFactory->createDistributed('ebookreader-comic');
		$key = $file->getId() . '-' . $file->getEtag();
		$hit = $cache->get($key);
		if (is_array($hit)) {
			/** @var list<array{name: string, size: int}> $hit */
			return $hit;
		}
		[$path, $tmp] = $this->localPath($file);
		try {
			$format = $this->formatOf($file) ?? 'cbz';
			if ($format !== 'cbz') {
				$archive = ComicArchive::open($path, $format, $this->archiveTools);
				try {
					$pages = array_map(static fn (string $name): array => ['name' => $name, 'size' => 0], $archive->pages());
				} finally {
					$archive->close();
				}
				$cache->set($key, $pages, 3600);
				return $pages;
			}
			$zip = EditorUtil::openZip($path);
			$pages = [];
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$stat = $zip->statIndex($i);
				$name = is_array($stat) ? (string)$stat['name'] : '';
				if ($name === '' || str_ends_with($name, '/') || !EditorUtil::isSafeName($name)
					|| str_starts_with($name, '__MACOSX/') || str_starts_with(basename($name), '.')
					|| !isset(self::IMAGE_EXT[strtolower(pathinfo($name, PATHINFO_EXTENSION))])) {
					continue;
				}
				$pages[] = ['name' => $name, 'size' => (int)$stat['size']];
			}
			$zip->close();
		} finally {
			if ($tmp !== null) {
				@unlink($tmp);
			}
		}
		usort($pages, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
		$cache->set($key, $pages, 3600);
		return $pages;
	}

	/** @return array{0: string, 1: string}|null */
	private function cached(ISimpleFolder $folder, string $name): ?array {
		try {
			$f = $folder->getFile($name);
			return [$f->getContent(), $f->getMimeType() !== '' && $f->getMimeType() !== 'application/octet-stream' ? $f->getMimeType() : 'image/jpeg'];
		} catch (NotFoundException) {
			return null;
		}
	}

	/** @return array{0: string, 1: string} */
	private function render(File $file, string $name, int $width, ISimpleFolder $folder, string $cacheName): array {
		[$path, $tmp] = $this->localPath($file);
		try {
			$format = $this->formatOf($file) ?? 'cbz';
			if ($format === 'cbz') {
				$zip = EditorUtil::openZip($path);
				try {
					$data = EditorUtil::readEntry($zip, $name, self::MAX_PAGE);
				} finally {
					$zip->close();
				}
			} else {
				$archive = ComicArchive::open($path, $format, $this->archiveTools);
				try {
					$data = $archive->read($name, self::MAX_PAGE);
				} finally {
					$archive->close();
				}
			}
		} finally {
			if ($tmp !== null) {
				@unlink($tmp);
			}
		}
		if ($data === null) {
			throw new NotFoundException('page not readable');
		}
		$mime = self::IMAGE_EXT[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'image/jpeg';
		if ($width > 0) {
			$scaled = $this->scale($data, $width);
			if ($scaled !== null) {
				[$data, $mime] = [$scaled, 'image/jpeg'];
			}
		}
		try {
			$folder->newFile($cacheName, $data);
		} catch (\Throwable $e) {
			$this->logger->debug('Cannot cache comic page: ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
		return [$data, $mime];
	}

	/** Scaled JPEG, or null when the image is already small enough or cannot be decoded. */
	private function scale(string $data, int $width): ?string {
		$size = @getimagesizefromstring($data);
		if ($size === false || $size[0] <= $width || !function_exists('imagecreatefromstring')) {
			return null;
		}
		$src = @imagecreatefromstring($data);
		if ($src === false) {
			return null;
		}
		$height = max(1, (int)round($size[1] * $width / $size[0]));
		$dst = imagecreatetruecolor($width, $height);
		imagefill($dst, 0, 0, (int)imagecolorallocate($dst, 255, 255, 255));
		imagecopyresampled($dst, $src, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);
		ob_start();
		imagejpeg($dst, null, 85);
		$out = (string)ob_get_clean();
		return $out !== '' ? $out : null;
	}

	private function cacheFolder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::CACHE_FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::CACHE_FOLDER);
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
		$tmp = $this->tempManager->getTemporaryFile('.' . ($this->formatOf($file) ?? 'zip'));
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
