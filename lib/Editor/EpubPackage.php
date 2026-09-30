<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Editor;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Parsed OPF package of an EPUB (internal to EpubEditor).
 */
final class EpubPackage {
	/**
	 * @param array<string, array{id: string, href: string, path: string, type: string, props: string, el: DOMElement}> $manifest by id
	 * @param list<array{idref: string, linear: bool, el: DOMElement}> $spine
	 */
	public function __construct(
		public readonly string $opfPath,
		public readonly string $opfDir,
		public readonly DOMDocument $dom,
		public readonly DOMXPath $xp,
		public readonly string $version,
		public array $manifest,
		public array $spine,
		public readonly ?string $navPath,
		public readonly ?string $ncxPath,
	) {
	}

	public function isEpub3(): bool {
		return version_compare($this->version, '3.0', '>=');
	}

	/** @return array<string, string> path => manifest id */
	public function pathIndex(): array {
		$out = [];
		foreach ($this->manifest as $id => $item) {
			$out[$item['path']] = $id;
		}
		return $out;
	}
}
