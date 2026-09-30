<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Migration;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** Owner: W1. Registers extra e-book MIME types (see Application::MIME_TYPES). */
class RegisterMimeTypes implements IRepairStep {
	public function getName(): string {
		return 'Register e-book MIME types';
	}

	public function run(IOutput $output): void {
		throw new \RuntimeException('Not implemented: W1');
	}
}
