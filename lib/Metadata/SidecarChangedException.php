<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

/**
 * The sidecar was changed by somebody else (e.g. a second user of a shared folder) after the state the caller based
 * its metadata on: writing it would overwrite their changes.
 */
class SidecarChangedException extends \RuntimeException {
}
