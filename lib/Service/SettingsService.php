<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\AppInfo\Application;
use OCP\IConfig;

/**
 * Per-user settings stored as a JSON string in the user config.
 */
class SettingsService {
	public const KEY = 'settings';

	public const DEFAULT_READER = [
		'theme' => 'auto',
		'fontSize' => 100,
		'fontFamily' => '',
		'lineHeight' => 1.5,
		'margin' => 5,
		'layout' => 'paginated',
		'flow' => 'paginated',
		'maxColumns' => 2,
	];

	/** Reader preference keys the UI (ReaderSettings.vue / ViewSettings) uses; everything else is dropped. */
	private const READER_ENUMS = [
		'theme' => ['auto', 'light', 'dark', 'sepia'],
		'layout' => ['paginated', 'scrolled'],
		'flow' => ['paginated', 'scrolled'],
		'comicSpread' => ['single', 'double'],
		'comicZoom' => ['fit-page', 'fit-width'],
	];
	/** @var array<string, array{0: float, 1: float}> */
	private const READER_NUMBERS = [
		'fontSize' => [8, 500],
		'lineHeight' => [0.5, 5],
		'margin' => [0, 300],
		'maxColumns' => [1, 8],
	];
	private const MAX_FONT_FAMILY = 200;

	/** When writes into the book file happen (only relevant for the targets "file" and "both"). "never" is the legacy value of metadataTarget "library". */
	public const METADATA_WRITE_MODES = ['background', 'immediate'];
	public const DEFAULT_METADATA_WRITE_MODE = 'background';
	/** Where metadata changes are stored: sidecar file (".<book>.opf"), inside the book, both, or only in the library database. */
	public const METADATA_TARGETS = ['sidecar', 'file', 'both', 'library'];
	public const DEFAULT_METADATA_TARGET = 'sidecar';

	public function __construct(
		private IConfig $config,
	) {
	}

	/** @return array{libraryFolders: list<string>, reader: array<string, mixed>, filenamePattern: string, genreList: list<string>|null, metadataWriteMode: string, metadataTarget: string} */
	public function get(string $userId): array {
		$raw = $this->config->getUserValue($userId, Application::APP_ID, self::KEY, '');
		$stored = $raw === '' ? [] : json_decode($raw, true);
		if (!is_array($stored)) {
			$stored = [];
		}
		return $this->normalise($stored, true);
	}

	/**
	 * Merges the given (partial) settings into the stored ones and returns the result.
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public function set(string $userId, array $settings): array {
		$current = $this->get($userId);
		$merged = $current;
		if (array_key_exists('libraryFolders', $settings)) {
			$merged['libraryFolders'] = $settings['libraryFolders'];
		}
		if (array_key_exists('reader', $settings) && is_array($settings['reader'])) {
			$merged['reader'] = array_merge($current['reader'], $this->sanitizeReader($settings['reader']));
		}
		if (array_key_exists('filenamePattern', $settings)) {
			$merged['filenamePattern'] = $settings['filenamePattern'];
		}
		if (array_key_exists('genreList', $settings)) {
			$merged['genreList'] = $settings['genreList'];
		}
		if (array_key_exists('metadataWriteMode', $settings)) {
			$merged['metadataWriteMode'] = $settings['metadataWriteMode'];
			if ($settings['metadataWriteMode'] === 'never' && !array_key_exists('metadataTarget', $settings)) {
				// legacy clients: "never" means "library only"
				$merged['metadataTarget'] = 'library';
			}
		}
		if (array_key_exists('metadataTarget', $settings)) {
			$merged['metadataTarget'] = $settings['metadataTarget'];
		}
		$clean = $this->normalise($merged, false);
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		return $this->get($userId);
	}

	/**
	 * @param array<string, mixed> $in
	 * @param bool $resolveGenres if true a missing genreList is replaced by the default list
	 * @return array{libraryFolders: list<string>, reader: array<string, mixed>, filenamePattern: string, genreList: list<string>|null, metadataWriteMode: string, metadataTarget: string}
	 */
	private function normalise(array $in, bool $resolveGenres): array {
		$folders = [];
		if (isset($in['libraryFolders']) && is_array($in['libraryFolders'])) {
			foreach ($in['libraryFolders'] as $f) {
				if (!is_string($f)) {
					continue;
				}
				$f = '/' . trim(str_replace('\\', '/', $f), '/');
				if (!in_array($f, $folders, true)) {
					$folders[] = $f;
				}
			}
		} else {
			$folders = ['/Books'];
		}

		$reader = self::DEFAULT_READER;
		if (isset($in['reader']) && is_array($in['reader'])) {
			$reader = array_merge($reader, $this->sanitizeReader($in['reader']));
		}

		$pattern = isset($in['filenamePattern']) && is_string($in['filenamePattern']) && trim($in['filenamePattern']) !== ''
			? $in['filenamePattern']
			: '{author} - {title}';

		$genres = null;
		if (isset($in['genreList']) && is_array($in['genreList'])) {
			$genres = array_values(array_unique(array_filter(array_map(
				static fn ($g) => is_string($g) ? trim($g) : '',
				$in['genreList']
			), static fn (string $g) => $g !== '')));
		}
		if ($genres === null && $resolveGenres) {
			$genres = $this->defaultGenres();
		}

		$mode = isset($in['metadataWriteMode']) && is_string($in['metadataWriteMode']) && in_array($in['metadataWriteMode'], self::METADATA_WRITE_MODES, true)
			? $in['metadataWriteMode']
			: self::DEFAULT_METADATA_WRITE_MODE;

		$target = isset($in['metadataTarget']) && is_string($in['metadataTarget']) && in_array($in['metadataTarget'], self::METADATA_TARGETS, true)
			? $in['metadataTarget']
			: self::DEFAULT_METADATA_TARGET;
		if (($in['metadataWriteMode'] ?? null) === 'never' && !isset($in['metadataTarget'])) {
			// migration of the legacy write mode "never"
			$target = 'library';
		}

		return [
			'libraryFolders' => $folders,
			'reader' => $reader,
			'filenamePattern' => $pattern,
			'genreList' => $genres,
			'metadataWriteMode' => $mode,
			'metadataTarget' => $target,
		];
	}

	/**
	 * Keeps only known reader keys with a value of the right type and range.
	 * @param array<array-key, mixed> $in
	 * @return array<string, mixed>
	 */
	public function sanitizeReader(array $in): array {
		$out = [];
		foreach ($in as $key => $value) {
			if (!is_string($key)) {
				continue;
			}
			if (isset(self::READER_ENUMS[$key])) {
				if (is_string($value) && in_array($value, self::READER_ENUMS[$key], true)) {
					$out[$key] = $value;
				}
			} elseif (isset(self::READER_NUMBERS[$key])) {
				[$min, $max] = self::READER_NUMBERS[$key];
				if ((is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= $min && $value <= $max) {
					$out[$key] = $key === 'maxColumns' ? (int)$value : $value;
				}
			} elseif ($key === 'fontFamily') {
				if (is_string($value) && strlen($value) <= self::MAX_FONT_FAMILY && preg_match('/^[^<>{};\\\\]*$/', $value) === 1) {
					$out[$key] = $value;
				}
			} elseif ($key === 'comicRtl') {
				if (is_bool($value)) {
					$out[$key] = $value;
				}
			}
		}
		return $out;
	}

	/** @return list<string> */
	public function defaultGenres(): array {
		$file = dirname(__DIR__, 2) . '/resources/genres.json';
		if (!is_file($file)) {
			return [];
		}
		$data = json_decode((string)file_get_contents($file), true);
		if (!is_array($data)) {
			return [];
		}
		$list = [];
		foreach ($data as $entry) {
			if (is_string($entry)) {
				$list[] = $entry;
			} elseif (is_array($entry) && isset($entry['name']) && is_string($entry['name'])) {
				$list[] = $entry['name'];
			} elseif (is_array($entry) && isset($entry['en']) && is_string($entry['en'])) {
				$list[] = $entry['en'];
			}
		}
		return $list;
	}
}
