<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Editor;

interface BookEditorInterface {
	public function supports(string $format): bool;

	/**
	 * @return array<string, mixed> Structure (without fileId/etag/editable)
	 */
	public function readStructure(string $localPath, string $format): array;

	/**
	 * Writes the edited book from $srcPath to $dstPath.
	 * @param ?callable(float, string): void $progress optional progress callback (fraction 0..1, short English step text)
	 * @return array{warnings: list<string>, itemMap: array<string, ?string>} itemMap: old href => new href|null
	 */
	public function write(string $srcPath, string $dstPath, EditRequest $req, ?callable $progress = null): array;
}
