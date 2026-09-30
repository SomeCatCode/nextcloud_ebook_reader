<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\Db\Tag;
use OCA\EbookReader\Db\TagMapper;

/**
 * Owner: W1. Decides whether a dc:subject-like value is a genre or a tag (PLAN 6.3):
 * 1. already a genre in the user's DB, 2. in the configured/default genre list, 3. otherwise tag.
 */
class GenreClassifier {
	public const MAX_NAME_LENGTH = 128;

	/** @var array<string, array<string, string>> userId => lower name => canonical genre name */
	private array $knownCache = [];
	/** @var ?array<string, string> lower English alias => German default name */
	private ?array $aliases = null;

	public function __construct(
		private ?SettingsService $settings = null,
		private ?TagMapper $tags = null,
	) {
	}

	/**
	 * Splits subjects into known genres and remaining tags.
	 * @param list<string> $subjects
	 * @return array{genres: list<string>, tags: list<string>}
	 */
	public function classify(array $subjects, ?string $userId = null): array {
		$known = $userId === null ? [] : $this->knownGenres($userId);
		$list = $this->genreList($userId);
		$aliases = $this->aliases();
		$genres = [];
		$tags = [];
		$seen = [];
		foreach ($subjects as $subject) {
			$name = self::normalise($subject);
			if ($name === null) {
				continue;
			}
			$key = mb_strtolower($name);
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			if (isset($known[$key])) {
				$genres[] = $known[$key];
			} elseif (isset($list[$key])) {
				$genres[] = $list[$key];
			} elseif (isset($aliases[$key]) && isset($list[mb_strtolower($aliases[$key])])) {
				$genres[] = $list[mb_strtolower($aliases[$key])];
			} else {
				$tags[] = $name;
			}
		}
		return ['genres' => array_values(array_unique($genres)), 'tags' => $tags];
	}

	/** Trims, collapses whitespace and caps the length; null if empty. */
	public static function normalise(string $name): ?string {
		$name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
		if ($name === '') {
			return null;
		}
		return mb_substr($name, 0, self::MAX_NAME_LENGTH);
	}

	/** @return array<string, string> lower name => canonical */
	private function knownGenres(string $userId): array {
		if (!isset($this->knownCache[$userId])) {
			$map = [];
			if ($this->tags !== null) {
				foreach ($this->tags->countByNameForUser($userId, Tag::TYPE_GENRE) as $row) {
					$map[mb_strtolower($row['name'])] = $row['name'];
				}
			}
			$this->knownCache[$userId] = $map;
		}
		return $this->knownCache[$userId];
	}

	/** @return array<string, string> lower name => canonical */
	private function genreList(?string $userId): array {
		$names = [];
		if ($this->settings !== null) {
			if ($userId !== null) {
				$names = $this->settings->get($userId)['genreList'] ?? [];
			} else {
				$names = $this->settings->defaultGenres();
			}
		}
		$map = [];
		foreach ($names as $n) {
			$map[mb_strtolower($n)] = $n;
		}
		return $map;
	}

	/** @return array<string, string> */
	private function aliases(): array {
		if ($this->aliases === null) {
			$this->aliases = [];
			$file = dirname(__DIR__, 2) . '/resources/genres.json';
			$data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
			foreach (is_array($data) ? $data : [] as $entry) {
				if (is_array($entry) && isset($entry['name'], $entry['en']) && is_string($entry['name']) && is_string($entry['en'])) {
					$this->aliases[mb_strtolower($entry['en'])] = $entry['name'];
				}
			}
		}
		return $this->aliases;
	}
}
