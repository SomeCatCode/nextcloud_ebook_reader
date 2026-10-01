<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Editor;

/**
 * Edit request DTO (see CONTRACTS section 5).
 */
final class EditRequest {
	/**
	 * @param ?array<string, mixed> $metadata
	 * @param ?array<string, mixed> $cover {source:"upload", data} | {source:"item", itemId}
	 * @param ?list<string> $order new item order without removed items
	 * @param ?list<string> $removed
	 * @param ?list<array<string, mixed>> $toc
	 */
	public function __construct(
		public readonly string $etag = '',
		public readonly bool $saveAsCopy = false,
		public readonly ?array $metadata = null,
		public readonly ?array $cover = null,
		public readonly ?array $order = null,
		public readonly ?array $removed = null,
		public readonly ?array $toc = null,
	) {
	}

	/**
	 * True if only metadata is to be changed (no cover, order, removals or toc): editors can then copy every
	 * entry unchanged and replace just the metadata document.
	 */
	public function isMetadataOnly(): bool {
		return $this->metadata !== null
			&& $this->cover === null
			&& $this->order === null
			&& ($this->removed ?? []) === []
			&& $this->toc === null;
	}

	/** @param array<string, mixed> $data */
	public static function fromArray(array $data): self {
		$strList = static function (mixed $v): ?array {
			if (!is_array($v)) {
				return null;
			}
			return array_values(array_map('strval', $v));
		};
		$arr = static fn (mixed $v): ?array => is_array($v) ? $v : null;
		return new self(
			etag: isset($data['etag']) && is_scalar($data['etag']) ? (string)$data['etag'] : '',
			saveAsCopy: (bool)($data['saveAsCopy'] ?? false),
			metadata: $arr($data['metadata'] ?? null),
			cover: $arr($data['cover'] ?? null),
			order: $strList($data['order'] ?? null),
			removed: $strList($data['removed'] ?? null),
			toc: isset($data['toc']) && is_array($data['toc']) ? array_values($data['toc']) : null,
		);
	}
}
