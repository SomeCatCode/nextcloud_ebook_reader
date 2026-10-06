<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

/**
 * Domain error of the shelf service; the controller maps `reason` to an OCS status.
 */
class ShelfException extends \RuntimeException {
	public const NOT_FOUND = 'not_found';
	public const INVALID = 'invalid';
	public const EXISTS = 'exists';
	public const LIMIT = 'limit';
	public const FORBIDDEN = 'forbidden';

	public function __construct(
		string $message,
		public readonly string $reason,
	) {
		parent::__construct($message);
	}
}
