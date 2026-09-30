<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Editor;

/** The file changed since the client loaded the structure: HTTP 409. */
final class EditConflictException extends EditorException {
	public function __construct(string $message = 'The file was changed in the meantime.', ?\Throwable $previous = null) {
		parent::__construct($message, 409, $previous);
	}
}
