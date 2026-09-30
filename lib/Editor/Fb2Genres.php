<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Editor;

/**
 * FB2 genres are a fixed vocabulary of codes. Maps app genre labels to codes and back using
 * resources/fb2-genres.json ({code: {de, en}}; a few tolerated alternative layouts).
 */
final class Fb2Genres {
	/** @var array<string, list<string>> code => labels */
	private array $labels = [];
	/** @var array<string, string> lower-case label => code */
	private array $byLabel = [];

	public function __construct(?string $file = null) {
		$file ??= dirname(__DIR__, 2) . '/resources/fb2-genres.json';
		$raw = is_file($file) ? file_get_contents($file) : false;
		$data = $raw === false ? null : json_decode($raw, true);
		if (is_array($data)) {
			$this->load($data);
		}
		if ($this->labels === []) {
			$this->load([
				'sf_fantasy' => ['de' => 'Fantasy', 'en' => 'Fantasy'],
				'sf' => ['de' => 'Science-Fiction', 'en' => 'Science Fiction'],
				'detective' => ['de' => 'Krimi', 'en' => 'Detective'],
				'thriller' => ['de' => 'Thriller', 'en' => 'Thriller'],
				'prose_classic' => ['de' => 'Klassiker', 'en' => 'Classic Prose'],
				'prose_contemporary' => ['de' => 'Roman', 'en' => 'Contemporary Prose'],
				'love_contemporary' => ['de' => 'Liebesroman', 'en' => 'Contemporary Romance'],
			]);
		}
	}

	/** @param array<array-key, mixed> $data */
	private function load(array $data): void {
		$preferred = [];
		foreach ($data as $key => $val) {
			$code = null;
			$labels = [];
			if (is_string($key) && is_array($val)) {
				$code = $key;
				foreach ($val as $v) {
					if (is_string($v) && $v !== '') {
						$labels[] = $v;
					}
				}
			} elseif (is_string($key) && is_string($val)) {
				$code = $key;
				$labels = [$val];
			} elseif (is_array($val)) {
				$c = $val['code'] ?? $val['id'] ?? $val['key'] ?? null;
				if (is_string($c)) {
					$code = $c;
					foreach ($val as $k => $v) {
						if (is_string($v) && $v !== '' && $k !== 'code' && $k !== 'id' && $k !== 'key') {
							$labels[] = $v;
						}
					}
				}
			}
			if ($code === null) {
				continue;
			}
			$this->labels[$code] = $labels;
			foreach ($labels as $i => $label) {
				$l = mb_strtolower($label);
				$score = ($i === 1 ? 0 : 1) + (str_contains($code, '_') ? 1 : 0) + (str_contains($code, '_') ? 0 : 0);
				// prefer codes without underscore and English-name equality
				if (!isset($preferred[$l]) || $score < $preferred[$l]) {
					$preferred[$l] = $score;
					$this->byLabel[$l] = $code;
				}
			}
		}
		// exact English name equals label: strongest preference
		foreach ($this->labels as $code => $labels) {
			if (isset($labels[1])) {
				$this->byLabel[mb_strtolower($labels[1])] = $code;
			}
		}
	}

	public function isCode(string $code): bool {
		return isset($this->labels[$code]);
	}

	/** Code for an app genre label (or an FB2 code itself); null if unknown. */
	public function codeFor(string $label): ?string {
		$label = trim($label);
		if ($label === '') {
			return null;
		}
		if ($this->isCode($label)) {
			return $label;
		}
		return $this->byLabel[mb_strtolower($label)] ?? null;
	}

	/** Display label for a code (German preferred); the code itself if unknown. */
	public function labelFor(string $code): string {
		return $this->labels[$code][0] ?? $code;
	}
}
