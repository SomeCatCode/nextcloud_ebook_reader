<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader;

use OCP\Capabilities\ICapability;

class Capabilities implements ICapability {
	/**
	 * @return array{ebookreader: array{apiVersion: int, apiStable: bool, formats: list<string>, editor: bool}}
	 */
	public function getCapabilities(): array {
		return [
			'ebookreader' => [
				'apiVersion' => 1,
				'apiStable' => false,
				'formats' => ['epub', 'mobi', 'azw3', 'fb2', 'fbz', 'cbz', 'cbr'],
				'editor' => true,
			],
		];
	}
}
