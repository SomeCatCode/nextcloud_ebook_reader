<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Editor;

/** Missing write permission: HTTP 403. */
final class EditForbiddenException extends EditorException {
	public function __construct(string $message = 'Not permitted.', ?\Throwable $previous = null) {
		parent::__construct($message, 403, $previous);
	}
}
