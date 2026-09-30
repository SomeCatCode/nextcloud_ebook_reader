<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

/**
 * Defensive read-only wrapper around ZipArchive: rejects zip-slip entry names,
 * caps entry sizes and the total uncompressed size (zip bombs).
 */
final class SafeZip {
	public const MAX_ENTRY_SIZE = 50 * 1024 * 1024;
	public const MAX_TOTAL_SIZE = 2 * 1024 * 1024 * 1024;

	/** @var array<string, int> entry name => index (files only) */
	private array $index = [];
	/** @var array<string, string> lower-case name => entry name */
	private array $lower = [];
	private bool $closed = false;

	private function __construct(private \ZipArchive $zip) {
	}

	/** @throws UnsafeArchiveException */
	public static function open(string $path): self {
		if (!class_exists(\ZipArchive::class)) {
			throw new UnsafeArchiveException('PHP zip extension is not available');
		}
		$zip = new \ZipArchive();
		$res = $zip->open($path, \ZipArchive::RDONLY);
		if ($res !== true) {
			throw new UnsafeArchiveException('Cannot open archive (code ' . $res . ')');
		}
		$self = new self($zip);
		$total = 0;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			if ($stat === false) {
				continue;
			}
			$name = $stat['name'];
			if (!self::isSafeName($name)) {
				$self->close();
				throw new UnsafeArchiveException('Unsafe entry name in archive');
			}
			if (str_ends_with($name, '/')) {
				continue;
			}
			$total += (int)$stat['size'];
			if ($total > self::MAX_TOTAL_SIZE) {
				$self->close();
				throw new UnsafeArchiveException('Archive too large when uncompressed');
			}
			$self->index[$name] = $i;
			$self->lower[strtolower($name)] ??= $name;
		}
		return $self;
	}

	/** Whether an archive entry name is acceptable (no traversal, no absolute path, no NUL). */
	public static function isSafeName(string $name): bool {
		if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\')) {
			return false;
		}
		if ($name[0] === '/' || preg_match('#^[A-Za-z]:#', $name) === 1) {
			return false;
		}
		foreach (explode('/', $name) as $part) {
			if ($part === '..') {
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolves an href (possibly URL-encoded, with fragment/query) relative to a base directory
	 * inside the archive. Returns null if it escapes the archive root.
	 */
	public static function resolve(string $baseDir, string $href): ?string {
		$href = preg_replace('/[#?].*$/s', '', $href) ?? $href;
		$href = rawurldecode($href);
		if ($href === '' || str_contains($href, "\0")) {
			return null;
		}
		$href = str_replace('\\', '/', $href);
		$path = $href[0] === '/' ? ltrim($href, '/') : ($baseDir === '' ? '' : rtrim($baseDir, '/') . '/') . $href;
		$out = [];
		foreach (explode('/', $path) as $part) {
			if ($part === '' || $part === '.') {
				continue;
			}
			if ($part === '..') {
				if ($out === []) {
					return null;
				}
				array_pop($out);
				continue;
			}
			$out[] = $part;
		}
		return $out === [] ? null : implode('/', $out);
	}

	/** @return list<string> all file entry names */
	public function names(): array {
		return array_map('strval', array_keys($this->index));
	}

	/** Returns the real entry name (case-insensitive fallback) or null. */
	public function find(string $name): ?string {
		if (isset($this->index[$name])) {
			return $name;
		}
		return $this->lower[strtolower($name)] ?? null;
	}

	public function has(string $name): bool {
		return $this->find($name) !== null;
	}

	public function size(string $name): int {
		$real = $this->find($name);
		if ($real === null) {
			return 0;
		}
		$stat = $this->zip->statIndex($this->index[$real]);
		return $stat === false ? 0 : (int)$stat['size'];
	}

	/**
	 * @throws UnsafeArchiveException if the entry is larger than the limit
	 * @return ?string null if the entry does not exist
	 */
	public function read(string $name, int $maxSize = self::MAX_ENTRY_SIZE): ?string {
		$real = $this->find($name);
		if ($real === null) {
			return null;
		}
		$stat = $this->zip->statIndex($this->index[$real]);
		if ($stat === false || (int)$stat['size'] > $maxSize) {
			throw new UnsafeArchiveException('Archive entry too large: ' . $real);
		}
		$data = $this->zip->getFromIndex($this->index[$real], (int)$stat["size"]);
		if ($data === false) {
			return null;
		}
		if (strlen($data) > $maxSize) {
			throw new UnsafeArchiveException('Archive entry too large: ' . $real);
		}
		return $data;
	}

	public function close(): void {
		if (!$this->closed) {
			$this->closed = true;
			@$this->zip->close();
		}
	}

	public function __destruct() {
		$this->close();
	}
}
