<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

/** Thrown when an archive is malformed, contains unsafe entry names or exceeds size limits. */
class UnsafeArchiveException extends \RuntimeException {
}
