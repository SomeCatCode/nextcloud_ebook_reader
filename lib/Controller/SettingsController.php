<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\Http\AbstractOCSController;
use OCA\EbookReader\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;

/**
 * @psalm-import-type EbookReaderSettings from \OCA\EbookReader\ResponseDefinitions
 * @psalm-suppress MissingDependency IRootFolder references a server-only class
 */
class SettingsController extends AbstractOCSController {
	public const MAX_FOLDERS = 50;
	public const MAX_GENRES = 500;

	public function __construct(
		IRequest $request,
		?string $userId,
		private SettingsService $settings,
		private IRootFolder $rootFolder,
	) {
		parent::__construct($request, $userId);
	}

	/**
	 * Get the settings of the current user
	 *
	 * @return DataResponse<Http::STATUS_OK, EbookReaderSettings, array{}>
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Settings returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/settings')]
	public function get(): DataResponse {
		/** @var EbookReaderSettings $s */
		$s = $this->settings->get($this->uid());
		return new DataResponse($s);
	}

	/**
	 * Update settings (partial update, absent keys stay unchanged)
	 *
	 * @param list<string>|null $libraryFolders Library folders, must be existing folders of the user
	 * @param array<string, mixed>|null $reader Reader preferences
	 * @param string|null $filenamePattern Pattern for renaming, e.g. "{author} - {title}"
	 * @param list<string>|null $genreList Custom genre list, null resets to the default list
	 * @param string|null $metadataWriteMode When metadata edits are written into the book file (targets "file" and "both"): "background" (default) or "immediate"; the legacy value "never" is stored as metadataTarget "library"
	 * @param string|null $metadataTarget Where metadata changes are stored: "sidecar" (default, hidden .<book>.opf file), "file", "both" or "library"
	 * @param string|null $sidecarLocation Where sidecar files are stored: "beside" (default, hidden .<book>.opf next to the book) or "meta" (hidden .meta folder per directory); changing it moves the existing sidecars in the background
	 * @return DataResponse<Http::STATUS_OK, EbookReaderSettings, array{}>
	 * @throws OCSBadRequestException Invalid settings
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Settings stored
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/settings')]
	public function put(?array $libraryFolders = null, ?array $reader = null, ?string $filenamePattern = null, ?array $genreList = null, ?string $metadataWriteMode = null, ?string $metadataTarget = null, ?string $sidecarLocation = null): DataResponse {
		$userId = $this->uid();
		$params = $this->request->getParams();
		$update = [];

		if (array_key_exists('libraryFolders', $params)) {
			if ($libraryFolders === null) {
				throw new OCSBadRequestException('libraryFolders must be a list of paths');
			}
			$update['libraryFolders'] = $this->validateFolders($userId, $libraryFolders);
		}
		if (array_key_exists('reader', $params)) {
			if ($reader === null || is_int(array_key_first($reader))) {
				throw new OCSBadRequestException('reader must be an object');
			}
			if (strlen(json_encode($reader) ?: '') > 8192) {
				throw new OCSBadRequestException('reader settings too large');
			}
			$update['reader'] = $reader;
		}
		if (array_key_exists('filenamePattern', $params)) {
			if ($filenamePattern === null || trim($filenamePattern) === '' || mb_strlen($filenamePattern) > 255) {
				throw new OCSBadRequestException('filenamePattern must be a non-empty string (max 255 characters)');
			}
			$update['filenamePattern'] = $filenamePattern;
		}
		if (array_key_exists('genreList', $params)) {
			$update['genreList'] = $genreList === null ? null : $this->validateGenres($genreList);
		}

		if (array_key_exists('metadataWriteMode', $params)) {
			if ($metadataWriteMode === null || !in_array($metadataWriteMode, [...SettingsService::METADATA_WRITE_MODES, 'never'], true)) {
				throw new OCSBadRequestException('metadataWriteMode must be one of: ' . implode(', ', SettingsService::METADATA_WRITE_MODES));
			}
			$update['metadataWriteMode'] = $metadataWriteMode;
		}
		if (array_key_exists('metadataTarget', $params)) {
			if ($metadataTarget === null || !in_array($metadataTarget, SettingsService::METADATA_TARGETS, true)) {
				throw new OCSBadRequestException('metadataTarget must be one of: ' . implode(', ', SettingsService::METADATA_TARGETS));
			}
			$update['metadataTarget'] = $metadataTarget;
		}
		if (array_key_exists('sidecarLocation', $params)) {
			if ($sidecarLocation === null || !in_array($sidecarLocation, SettingsService::SIDECAR_LOCATIONS, true)) {
				throw new OCSBadRequestException('sidecarLocation must be one of: ' . implode(', ', SettingsService::SIDECAR_LOCATIONS));
			}
			$update['sidecarLocation'] = $sidecarLocation;
		}

		/** @var EbookReaderSettings $result */
		$result = $this->settings->set($userId, $update);
		return new DataResponse($result);
	}

	/**
	 * @param array<array-key, mixed> $folders
	 * @return list<string>
	 * @throws OCSBadRequestException
	 */
	private function validateFolders(string $userId, array $folders): array {
		if (!array_is_list($folders) || count($folders) > self::MAX_FOLDERS) {
			throw new OCSBadRequestException('libraryFolders must be a list of at most ' . self::MAX_FOLDERS . ' paths');
		}
		/** @psalm-suppress MissingDependency */
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$out = [];
		foreach ($folders as $folder) {
			if (!is_string($folder) || $folder === '' || str_contains($folder, "\0")) {
				throw new OCSBadRequestException('Invalid folder path');
			}
			$path = '/' . trim(str_replace('\\', '/', $folder), '/');
			if (in_array('..', explode('/', $path), true)) {
				throw new OCSBadRequestException('Invalid folder path');
			}
			try {
				$node = $path === '/' ? $userFolder : $userFolder->get($path);
			} catch (NotFoundException) {
				throw new OCSBadRequestException('Folder does not exist: ' . $path);
			}
			if (!$node instanceof Folder) {
				throw new OCSBadRequestException('Not a folder: ' . $path);
			}
			if (!in_array($path, $out, true)) {
				$out[] = $path;
			}
		}
		return $out;
	}

	/**
	 * @param array<array-key, mixed> $genres
	 * @return list<string>
	 * @throws OCSBadRequestException
	 */
	private function validateGenres(array $genres): array {
		if (!array_is_list($genres) || count($genres) > self::MAX_GENRES) {
			throw new OCSBadRequestException('genreList must be a list of at most ' . self::MAX_GENRES . ' names');
		}
		foreach ($genres as $g) {
			if (!is_string($g) || mb_strlen($g) > 128) {
				throw new OCSBadRequestException('Invalid genre name');
			}
		}
		return $genres;
	}
}
