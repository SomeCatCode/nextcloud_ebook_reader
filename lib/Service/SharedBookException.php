<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCP\Files\NotPermittedException;

/** The file belongs to another user and only reached the requesting user through a share. */
class SharedBookException extends NotPermittedException {
	public function __construct(
		private string $owner,
	) {
		parent::__construct('The book belongs to ' . ($owner !== '' ? $owner : 'another user') . '; remove the share instead');
	}

	public function getOwner(): string {
		return $this->owner;
	}
}
