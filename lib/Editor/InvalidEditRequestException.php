<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Editor;

/** Invalid edit request (unknown item ids, bad order, ...): HTTP 400. */
final class InvalidEditRequestException extends EditorException {
	public function __construct(string $message, ?\Throwable $previous = null) {
		parent::__construct($message, 400, $previous);
	}
}
