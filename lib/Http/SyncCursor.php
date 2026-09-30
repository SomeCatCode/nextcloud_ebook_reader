<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Http;

/**
 * Opaque sync cursor: base64url(JSON {"b":[updatedAt,id],"p":[updatedAt,id]}).
 * b = books table position, p = progress table position. An empty cursor means "from the start".
 */
final class SyncCursor {
	/**
	 * @param array{0: int, 1: int} $books
	 * @param array{0: int, 1: int} $progress
	 */
	public function __construct(
		public readonly array $books = [0, 0],
		public readonly array $progress = [0, 0],
	) {
	}

	public function encode(): string {
		$json = json_encode(['b' => $this->books, 'p' => $this->progress], JSON_THROW_ON_ERROR);
		return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
	}

	/** @throws \InvalidArgumentException on malformed input */
	public static function decode(?string $cursor): self {
		if ($cursor === null || $cursor === '') {
			return new self();
		}
		if (strlen($cursor) > 256 || preg_match('/^[A-Za-z0-9_-]+$/', $cursor) !== 1) {
			throw new \InvalidArgumentException('Invalid cursor');
		}
		$raw = base64_decode(strtr($cursor, '-_', '+/'), true);
		if ($raw === false) {
			throw new \InvalidArgumentException('Invalid cursor');
		}
		$data = json_decode($raw, true);
		if (!is_array($data)) {
			throw new \InvalidArgumentException('Invalid cursor');
		}
		return new self(self::pair($data['b'] ?? null), self::pair($data['p'] ?? null));
	}

	/** @return array{0: int, 1: int} */
	private static function pair(mixed $v): array {
		if (!is_array($v) || count($v) !== 2 || !isset($v[0], $v[1]) || !is_int($v[0]) || !is_int($v[1]) || $v[0] < 0 || $v[1] < 0) {
			throw new \InvalidArgumentException('Invalid cursor');
		}
		return [$v[0], $v[1]];
	}
}
