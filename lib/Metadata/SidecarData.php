<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

/**
 * What a sidecar file (".<book>.opf") says. Only the fields present in the file are set in $metadata
 * (null / empty list = not in the sidecar).
 */
final class SidecarData {
	/**
	 * @param bool $labelsAuthoritative the sidecar was written by this app: its genres/tags replace the embedded ones even when empty
	 * @param ?string $uuid value of the package identifier (kept when the sidecar is rewritten)
	 */
	public function __construct(
		public readonly BookMetadata $metadata,
		public readonly bool $labelsAuthoritative = false,
		public readonly ?string $uuid = null,
	) {
	}

	/** Whether the sidecar has anything to say about genres/tags. */
	public function hasLabels(): bool {
		return $this->labelsAuthoritative
			|| $this->metadata->genres !== [] || $this->metadata->tags !== [] || $this->metadata->subjects !== [];
	}
}
