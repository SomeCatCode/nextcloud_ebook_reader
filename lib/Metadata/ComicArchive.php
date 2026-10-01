<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

use OCA\EbookReader\Service\ArchiveTools;

/**
 * Uniform read access to comic archives: CBZ (ZipArchive), CBT (own tar reader, no tool needed),
 * CBR and CB7 (external tool via ArchiveTools).
 */
final class ComicArchive {
	public const MAX_ENTRY_SIZE = 60 * 1024 * 1024;

	/**
	 * @param list<string> $names
	 * @param \Closure(string, int): ?string $reader
	 * @param ?\Closure(): void $closer
	 */
	private function __construct(
		private array $names,
		private \Closure $reader,
		private ?\Closure $closer,
	) {
	}

	/**
	 * @throws \RuntimeException|UnsafeArchiveException if the archive cannot be read
	 */
	public static function open(string $path, string $format, ?ArchiveTools $tools = null): self {
		switch ($format) {
			case 'cbz':
				$zip = SafeZip::open($path);
				return new self($zip->names(), static fn (string $n, int $max): ?string => $zip->read($n, $max), static function () use ($zip): void {
					$zip->close();
				});
			case 'cbt':
				$tar = TarArchive::open($path);
				return new self($tar->names(), static fn (string $n, int $max): ?string => $tar->read($n, $max), static function () use ($tar): void {
					$tar->close();
				});
			case 'cbr':
			case 'cb7':
				$tools ??= new ArchiveTools();
				if (!$tools->canRead($format)) {
					throw new \RuntimeException('No archive tool available for ' . $format);
				}
				$names = $tools->list($path, $format);
				return new self($names, static function (string $n, int $max) use ($tools, $path): ?string {
					$data = $tools->extract($path, $n);
					if (strlen($data) > $max) {
						throw new UnsafeArchiveException('Archive entry too large: ' . $n);
					}
					return $data;
				}, null);
			default:
				throw new \InvalidArgumentException('Not a comic format: ' . $format);
		}
	}

	/** @return list<string> all file entries */
	public function names(): array {
		return $this->names;
	}

	/**
	 * Image pages in reading order (natural sort, like the readers); macOS resource forks and
	 * hidden files are skipped.
	 *
	 * @return list<string>
	 */
	public function pages(): array {
		$pages = [];
		foreach ($this->names as $name) {
			if (!self::isIgnored($name) && ImageUtil::isImageName($name)) {
				$pages[] = $name;
			}
		}
		usort($pages, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
		return $pages;
	}

	/** Entry name of ComicInfo.xml, or null. */
	public function comicInfoName(): ?string {
		foreach ($this->names as $name) {
			if (!self::isIgnored($name) && strtolower(basename($name)) === 'comicinfo.xml') {
				return $name;
			}
		}
		return null;
	}

	/** Raw ComicInfo.xml, or null. */
	public function comicInfo(): ?string {
		$name = $this->comicInfoName();
		if ($name === null) {
			return null;
		}
		try {
			return $this->read($name, 5 * 1024 * 1024);
		} catch (\Throwable) {
			return null;
		}
	}

	/** @return ?string null if the entry does not exist or cannot be read */
	public function read(string $name, int $max = self::MAX_ENTRY_SIZE): ?string {
		return ($this->reader)($name, $max);
	}

	public function close(): void {
		if ($this->closer !== null) {
			($this->closer)();
			$this->closer = null;
		}
	}

	private static function isIgnored(string $name): bool {
		return str_contains($name, '__MACOSX/') || str_starts_with(basename($name), '.');
	}
}
