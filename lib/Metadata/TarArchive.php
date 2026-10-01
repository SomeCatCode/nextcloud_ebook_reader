<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

/**
 * Minimal tar (ustar/pax/GNU long names) reader and writer for CBT comics. No PHP phar extension
 * and no external tool needed; entries are located once and read by offset.
 */
final class TarArchive {
	public const MAX_ENTRIES = 100000;
	public const MAX_ENTRY_SIZE = 64 * 1024 * 1024;

	/** @var resource|closed-resource */
	private $handle;
	/** @var array<string, array{0: int, 1: int}> name => [data offset, size] */
	private array $index = [];

	/**
	 * @param resource $handle
	 */
	private function __construct($handle) {
		$this->handle = $handle;
	}

	/** @throws UnsafeArchiveException */
	public static function open(string $path): self {
		$h = @fopen($path, 'rb');
		if ($h === false) {
			throw new UnsafeArchiveException('Cannot open tar archive');
		}
		$self = new self($h);
		try {
			$self->scan();
		} catch (\Throwable $e) {
			$self->close();
			throw $e;
		}
		return $self;
	}

	/** @return list<string> regular file entries in archive order */
	public function names(): array {
		return array_map('strval', array_keys($this->index));
	}

	public function size(string $name): int {
		return $this->index[$name][1] ?? 0;
	}

	/**
	 * @throws UnsafeArchiveException if the entry is larger than the limit
	 * @return ?string null if the entry does not exist
	 */
	public function read(string $name, int $max = self::MAX_ENTRY_SIZE): ?string {
		if (!isset($this->index[$name])) {
			return null;
		}
		[$offset, $size] = $this->index[$name];
		if ($size > $max) {
			throw new UnsafeArchiveException('Archive entry too large: ' . $name);
		}
		if ($size === 0) {
			return '';
		}
		if (fseek($this->handle, $offset) !== 0) {
			return null;
		}
		$data = stream_get_contents($this->handle, $size);
		return $data === false || strlen($data) !== $size ? null : $data;
	}

	public function close(): void {
		if (is_resource($this->handle)) {
			fclose($this->handle);
		}
	}

	public function __destruct() {
		$this->close();
	}

	private function scan(): void {
		$pos = 0;
		$longName = null;
		$paxName = null;
		$zeroBlocks = 0;
		while (true) {
			if (fseek($this->handle, $pos) !== 0) {
				break;
			}
			$header = fread($this->handle, 512);
			if ($header === false || strlen($header) < 512) {
				break;
			}
			if (trim($header, "\0") === '') {
				if (++$zeroBlocks >= 2) {
					break;
				}
				$pos += 512;
				continue;
			}
			$zeroBlocks = 0;
			if (!self::checksumOk($header)) {
				throw new UnsafeArchiveException('Not a tar archive (bad header checksum)');
			}
			$size = self::parseSize(substr($header, 124, 12));
			$type = $header[156];
			$dataOffset = $pos + 512;
			$next = $dataOffset + intdiv($size + 511, 512) * 512;

			if ($type === 'L' || $type === 'x') {
				if ($size > 65536) {
					throw new UnsafeArchiveException('Tar extension header too large');
				}
				$raw = $size > 0 ? (string)stream_get_contents($this->handle, $size) : '';
				if ($type === 'L') {
					$longName = rtrim($raw, "\0");
				} else {
					$paxName = self::parsePax($raw) ?? $paxName;
				}
				$pos = $next;
				continue;
			}
			if ($type === 'g' || $type === 'K') {
				$pos = $next;
				continue;
			}

			$name = $paxName ?? $longName ?? self::headerName($header);
			$longName = null;
			$paxName = null;
			if (($type === '0' || $type === "\0" || $type === '7') && !str_ends_with($name, '/')) {
				$name = preg_replace('#^(\./)+#', '', str_replace('\\', '/', $name)) ?? $name;
				if ($name !== '' && SafeZip::isSafeName($name)) {
					if (count($this->index) >= self::MAX_ENTRIES) {
						throw new UnsafeArchiveException('Tar archive has too many entries');
					}
					$this->index[$name] = [$dataOffset, $size];
				}
			}
			$pos = $next;
		}
	}

	private static function headerName(string $header): string {
		$name = rtrim(substr($header, 0, 100), "\0");
		if (substr($header, 257, 5) === 'ustar') {
			$prefix = rtrim(substr($header, 345, 155), "\0");
			if ($prefix !== '') {
				$name = $prefix . '/' . $name;
			}
		}
		return $name;
	}

	private static function checksumOk(string $header): bool {
		$field = trim(substr($header, 148, 8), " \0");
		if (preg_match('/^[0-7]+$/', $field) !== 1) {
			return false;
		}
		$stored = octdec($field);
		$sum = 0;
		for ($i = 0; $i < 512; $i++) {
			$sum += ($i >= 148 && $i < 156) ? 32 : ord($header[$i]);
		}
		return $sum === (int)$stored;
	}

	private static function parseSize(string $field): int {
		if ((ord($field[0]) & 0x80) !== 0) {
			// base-256 encoding (GNU)
			$n = ord($field[0]) & 0x7F;
			for ($i = 1; $i < strlen($field); $i++) {
				$n = ($n << 8) | ord($field[$i]);
				if ($n > PHP_INT_MAX >> 9) {
					throw new UnsafeArchiveException('Tar entry too large');
				}
			}
			return $n;
		}
		$octal = trim($field, " \0");
		if ($octal === '') {
			return 0;
		}
		if (preg_match('/^[0-7]+$/', $octal) !== 1) {
			throw new UnsafeArchiveException('Invalid tar entry size');
		}
		return (int)octdec($octal);
	}

	private static function parsePax(string $raw): ?string {
		$path = null;
		$offset = 0;
		while ($offset < strlen($raw)) {
			$space = strpos($raw, ' ', $offset);
			if ($space === false) {
				break;
			}
			$len = (int)substr($raw, $offset, $space - $offset);
			if ($len <= 0) {
				break;
			}
			$record = substr($raw, $space + 1, $len - ($space - $offset) - 2);
			if (str_starts_with($record, 'path=')) {
				$path = substr($record, 5);
			}
			$offset += $len;
		}
		return $path;
	}

	/**
	 * Writes a tar from files on disk (streamed, no whole-archive memory use).
	 *
	 * @param array<string, string> $files entry name => local file path
	 */
	public static function write(string $dstPath, array $files): void {
		$out = fopen($dstPath, 'wb');
		if ($out === false) {
			throw new \RuntimeException('Cannot write tar file');
		}
		try {
			foreach ($files as $name => $local) {
				$size = filesize($local);
				if ($size === false) {
					throw new \RuntimeException('Cannot read ' . $name);
				}
				$name = (string)$name;
				if (strlen($name) > 100) {
					$rec = 'path=' . $name . "\n";
					$len = strlen($rec) + 2;
					while (strlen($len . ' ' . $rec) !== $len) {
						$len = strlen($len . ' ' . $rec);
					}
					$pax = $len . ' ' . $rec;
					fwrite($out, self::header('PaxHeader/' . substr(md5($name), 0, 8), strlen($pax), 'x'));
					fwrite($out, $pax . str_repeat("\0", (512 - strlen($pax) % 512) % 512));
				}
				fwrite($out, self::header($name, $size, '0'));
				$in = fopen($local, 'rb');
				if ($in === false) {
					throw new \RuntimeException('Cannot read ' . $name);
				}
				stream_copy_to_stream($in, $out);
				fclose($in);
				fwrite($out, str_repeat("\0", (512 - $size % 512) % 512));
			}
			fwrite($out, str_repeat("\0", 1024));
		} finally {
			fclose($out);
		}
	}

	private static function header(string $name, int $size, string $type): string {
		$h = str_pad(substr($name, 0, 100), 100, "\0")
			. sprintf('%07o', 0644) . "\0"
			. sprintf('%07o', 0) . "\0"
			. sprintf('%07o', 0) . "\0"
			. sprintf('%011o', $size) . "\0"
			. sprintf('%011o', 0) . "\0"
			. '        '
			. $type
			. str_repeat("\0", 100)
			. "ustar\0" . '00'
			. str_repeat("\0", 32) . str_repeat("\0", 32)
			. str_repeat("\0", 8) . str_repeat("\0", 8)
			. str_repeat("\0", 155);
		$h = str_pad($h, 512, "\0");
		$sum = 0;
		for ($i = 0; $i < 512; $i++) {
			$sum += ord($h[$i]);
		}
		return substr($h, 0, 148) . sprintf('%06o', $sum) . "\0 " . substr($h, 156);
	}
}
