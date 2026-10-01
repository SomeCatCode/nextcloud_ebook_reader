<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

/** Conversion error; the code is the suggested HTTP status. */
class ConvertException extends \RuntimeException {
	public function __construct(string $message, int $status = 422, ?\Throwable $previous = null) {
		parent::__construct($message, $status, $previous);
	}

	public function getStatus(): int {
		return $this->getCode();
	}
}
