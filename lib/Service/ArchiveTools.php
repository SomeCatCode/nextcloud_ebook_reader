<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\Metadata\SafeZip;
use OCA\EbookReader\Metadata\UnsafeArchiveException;

/**
 * Optional external archive tools (7zz/7z/7za, unrar, bsdtar) used to read CBR and CB7 comics and
 * to write CB7. Everything runs through proc_open with an argument array (no shell), with a
 * timeout and an output size limit. Without any tool the app still works (client-side fallback).
 */
class ArchiveTools {
	public const TOOL_SEVEN_ZIP = 'sevenZip';
	public const TOOL_UNRAR = 'unrar';
	public const TOOL_BSDTAR = 'bsdtar';

	public const TIMEOUT_SECONDS = 30;
	public const MAX_OUTPUT_BYTES = 60 * 1024 * 1024;
	/** Cap on the summed uncompressed size and the entry count of an archive read through a tool */
	public const MAX_TOTAL_BYTES = 2 * 1024 * 1024 * 1024;
	public const MAX_ENTRIES = 5000;

	/** @var array<string, list<string>> tool key => executable names (first match wins) */
	private const NAMES = [
		self::TOOL_SEVEN_ZIP => ['7zz', '7z', '7za'],
		self::TOOL_UNRAR => ['unrar'],
		self::TOOL_BSDTAR => ['bsdtar'],
	];

	/** Tools that can read a format, in order of preference */
	private const READERS = [
		'cbr' => [self::TOOL_UNRAR, self::TOOL_SEVEN_ZIP, self::TOOL_BSDTAR],
		'cb7' => [self::TOOL_SEVEN_ZIP, self::TOOL_BSDTAR],
	];

	/** @var array<string, ?string>|null */
	private ?array $found = null;
	/** @var array<string, string> archive path => tool that listed (or last extracted from) it successfully */
	private array $listedWith = [];
	/** @var array<string, string> archive path => format it was listed as */
	private array $listedAs = [];

	/**
	 * @param list<string>|null $searchDirs directories to search; null = PATH plus the usual bin directories
	 */
	public function __construct(
		private ?array $searchDirs = null,
	) {
	}

	/** @return array{sevenZip: bool, unrar: bool, bsdtar: bool} */
	public function available(): array {
		$f = $this->detect();
		return [
			'sevenZip' => $f[self::TOOL_SEVEN_ZIP] !== null,
			'unrar' => $f[self::TOOL_UNRAR] !== null,
			'bsdtar' => $f[self::TOOL_BSDTAR] !== null,
		];
	}

	/** Whether the archive can be read on the server (CBZ and CBT always; CBR/CB7 need a tool). */
	public function canRead(string $format): bool {
		if ($format === 'cbz' || $format === 'cbt') {
			return true;
		}
		return $this->readTools($format) !== [];
	}

	/** Formats that can be written on the server with a tool (CB7). CBZ/CBT/EPUB are written in PHP. */
	public function canWrite(string $format): bool {
		return match ($format) {
			'cb7' => $this->detect()[self::TOOL_SEVEN_ZIP] !== null,
			'cbz', 'cbt', 'epub' => true,
			default => false,
		};
	}

	/**
	 * File entries of the archive (directories and unsafe names removed).
	 *
	 * @return list<string>
	 * @throws \RuntimeException if no tool can read the archive
	 */
	public function list(string $path, ?string $format = null): array {
		$tools = $format !== null ? $this->readTools($format) : $this->readTools('cbr');
		if ($tools === []) {
			throw new \RuntimeException('No archive tool available');
		}
		$last = 'unknown error';
		foreach ($tools as $tool) {
			try {
				$type = $tool === self::TOOL_SEVEN_ZIP ? self::sevenZipType($path) : null;
				[$code, $out, $err] = $this->run(self::commandFor($tool, 'list', $this->binary($tool), $path, null, $type));
				if (!self::exitOk($tool, $code)) {
					throw new \RuntimeException($tool . ' exited with ' . $code . self::errorDetail($err));
				}
				$entries = self::parseEntries($tool, $out);
				self::checkLimits($entries);
				$this->listedWith[$path] = $tool;
				$this->listedAs[$path] = $format ?? 'cbr';
				return array_map(static fn (array $e): string => $e['name'], $entries);
			} catch (UnsafeArchiveException $e) {
				throw $e;
			} catch (\RuntimeException $e) {
				$last = $e->getMessage();
			}
		}
		$hint = '';
		if (($format ?? 'cbr') === 'cbr' && !in_array(self::TOOL_UNRAR, $tools, true) && !in_array(self::TOOL_BSDTAR, $tools, true)) {
			// several 7-Zip builds (e.g. Alpine's 7zip package) cannot read RAR
			$hint = ' (install bsdtar from libarchive-tools or unrar for RAR support)';
		}
		throw new \RuntimeException('Cannot list archive: ' . $last . $hint);
	}

	/**
	 * Content of a single entry. Tried with the tool that listed the archive first, then with the other
	 * installed tools for the format: a tool may list an archive it cannot unpack (p7zip or Debian's
	 * 7zip without the non-free RAR codec answer "Unsupported Method").
	 *
	 * @throws \RuntimeException
	 */
	public function extract(string $path, string $entry): string {
		if (!self::isSafeEntryName($entry)) {
			throw new \RuntimeException('Unsafe archive entry name');
		}
		if (!isset($this->listedWith[$path])) {
			$this->list($path);
		}
		$first = $this->listedWith[$path] ?? null;
		if ($first === null) {
			throw new \RuntimeException('No archive tool available');
		}
		$format = $this->listedAs[$path] ?? 'cbr';
		$errors = [];
		foreach (array_values(array_unique([$first, ...$this->readTools($format)])) as $tool) {
			try {
				$type = $tool === self::TOOL_SEVEN_ZIP ? self::sevenZipType($path) : null;
				[$code, $out, $err] = $this->run(self::commandFor($tool, 'extract', $this->binary($tool), $path, $entry, $type));
			} catch (\RuntimeException $e) {
				$errors[] = $tool . ': ' . $e->getMessage();
				continue;
			}
			if (self::exitOk($tool, $code)) {
				// the following entries of this archive go straight to the tool that worked
				$this->listedWith[$path] = $tool;
				return $out;
			}
			$errors[] = $tool . ' exit ' . $code . self::errorDetail($err);
		}
		$hint = $format === 'cbr' ? ' (no installed tool can unpack this RAR: install unrar or bsdtar from libarchive-tools)' : '';
		throw new \RuntimeException('Cannot extract entry (' . implode('; ', $errors) . ')' . $hint);
	}

	/**
	 * Packs everything below $sourceDir into a 7z archive (stored, no compression: comic pages are
	 * compressed images already).
	 *
	 * @throws \RuntimeException
	 */
	public function createSevenZip(string $dstPath, string $sourceDir): void {
		if (!$this->canWrite('cb7')) {
			throw new \RuntimeException('7z is not available');
		}
		@unlink($dstPath);
		[$code] = $this->run([$this->binary(self::TOOL_SEVEN_ZIP), 'a', '-t7z', '-mx=0', '-bd', '-y', '--', $dstPath, '*'], $sourceDir, 600);
		if (!self::exitOk(self::TOOL_SEVEN_ZIP, $code) || !is_file($dstPath)) {
			@unlink($dstPath);
			throw new \RuntimeException('7z failed to create the archive (exit ' . $code . ')');
		}
	}

	// ---- pure helpers (unit tested) -----------------------------------------------------------

	/**
	 * @param 'list'|'extract' $op
	 * @return list<string> argv
	 */
	public static function commandFor(string $tool, string $op, string $binary, string $archive, ?string $entry = null, ?string $type = null): array {
		if ($op === 'extract' && $entry === null) {
			throw new \InvalidArgumentException('entry required');
		}
		$cmd = match ($tool) {
			self::TOOL_SEVEN_ZIP => $op === 'list'
				? [$binary, 'l', '-slt', '-ba', '-bd', '-y', '--', $archive]
				: [$binary, 'e', '-so', '-bd', '-y', '--', $archive],
			self::TOOL_UNRAR => $op === 'list'
				? [$binary, 'lb', '-p-', '--', $archive]
				: [$binary, 'p', '-inul', '-p-', '--', $archive],
			self::TOOL_BSDTAR => $op === 'list'
				? [$binary, '-tf', $archive]
				: [$binary, '-xOf', $archive, '--'],
			default => throw new \InvalidArgumentException('Unknown tool ' . $tool),
		};
		if ($tool === self::TOOL_SEVEN_ZIP && $type !== null) {
			// force the container type (no format sniffing by extension/content): goes before `-- <archive>`
			array_splice($cmd, count($cmd) - 2, 0, ['-t' . $type]);
		}
		if ($op === 'extract') {
			$cmd[] = $entry;
		}
		return $cmd;
	}

	/**
	 * 7z container type by magic bytes (7z / rar / zip); throws if the file is none of these.
	 *
	 * @throws \RuntimeException
	 */
	public static function sevenZipType(string $path): string {
		$fh = @fopen($path, 'rb');
		$head = $fh === false ? false : fread($fh, 8);
		if ($fh !== false) {
			fclose($fh);
		}
		if (!is_string($head)) {
			throw new \RuntimeException('Cannot read archive header');
		}
		return self::typeFromMagic($head) ?? throw new \RuntimeException('Unsupported archive type');
	}

	public static function typeFromMagic(string $head): ?string {
		if (str_starts_with($head, "7z\xBC\xAF\x27\x1C")) {
			return '7z';
		}
		if (str_starts_with($head, "Rar!\x1A\x07")) {
			return 'rar';
		}
		if (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06")) {
			return 'zip';
		}
		return null;
	}

	/**
	 * Refuses archives with too many entries or too much uncompressed data. Entries whose size is
	 * unknown (unrar/bsdtar listings carry none) only count towards the entry limit; the extraction
	 * side caps what is actually written.
	 *
	 * @param list<array{name: string, size: ?int}> $entries
	 * @throws UnsafeArchiveException
	 */
	public static function checkLimits(array $entries, int $maxEntries = self::MAX_ENTRIES, int $maxTotal = self::MAX_TOTAL_BYTES): void {
		if (count($entries) > $maxEntries) {
			throw new UnsafeArchiveException('Archive has too many entries');
		}
		$total = 0;
		foreach ($entries as $e) {
			$total += max(0, $e['size'] ?? 0);
			if ($total > $maxTotal) {
				throw new UnsafeArchiveException('Archive too large when uncompressed');
			}
		}
	}

	/** @return list<array{name: string, size: ?int}> */
	public static function parseEntries(string $tool, string $output): array {
		return match ($tool) {
			self::TOOL_SEVEN_ZIP => self::parseSevenZipEntries($output),
			self::TOOL_UNRAR, self::TOOL_BSDTAR => array_map(static fn (string $n): array => ['name' => $n, 'size' => null], self::parseLineList($output)),
			default => [],
		};
	}

	/** @return list<string> */
	public static function parseList(string $tool, string $output): array {
		return match ($tool) {
			self::TOOL_SEVEN_ZIP => self::parseSevenZipList($output),
			self::TOOL_UNRAR => self::parseLineList($output),
			self::TOOL_BSDTAR => self::parseLineList($output),
			default => [],
		};
	}

	/**
	 * Names only of a `7z l -slt` listing.
	 *
	 * @return list<string> file entries
	 */
	public static function parseSevenZipList(string $output): array {
		return array_map(static fn (array $e): string => $e['name'], self::parseSevenZipEntries($output));
	}

	/**
	 * Parses `7z l -slt` output (technical listing: "Key = Value" blocks separated by empty lines).
	 *
	 * @return list<array{name: string, size: ?int}> file entries with their uncompressed size
	 */
	public static function parseSevenZipEntries(string $output): array {
		$entries = [];
		/** @var array<string, string> $block */
		$block = [];
		foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
			if ($line === '----------') {
				// everything before is the archive header (without -ba)
				$block = [];
				$entries = [];
				continue;
			}
			if (trim($line) === '') {
				$entry = self::sevenZipEntry($block);
				if ($entry !== null) {
					$entries[] = $entry;
				}
				$block = [];
				continue;
			}
			$pos = strpos($line, ' = ');
			if ($pos !== false) {
				$block[substr($line, 0, $pos)] = rtrim(substr($line, $pos + 3), "\r");
			}
		}
		$entry = self::sevenZipEntry($block);
		if ($entry !== null) {
			$entries[] = $entry;
		}
		return $entries;
	}

	/**
	 * @param array<string, string> $block one "Key = Value" block of the technical listing
	 * @return ?array{name: string, size: ?int}
	 */
	private static function sevenZipEntry(array $block): ?array {
		if (!isset($block['Path'])) {
			return null;
		}
		$attributes = $block['Attributes'] ?? '';
		if (($block['Folder'] ?? '') === '+' || ($attributes !== '' && $attributes[0] === 'D')) {
			return null;
		}
		$name = self::normaliseName($block['Path']);
		if (!self::isSafeEntryName($name)) {
			return null;
		}
		$size = $block['Size'] ?? '';
		return ['name' => $name, 'size' => preg_match('/^\d{1,15}$/', $size) === 1 ? (int)$size : null];
	}

	/**
	 * Parses a plain one-name-per-line listing (`unrar lb`, `bsdtar -tf`).
	 *
	 * @return list<string> file entries
	 */
	public static function parseLineList(string $output): array {
		$names = [];
		foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
			if ($line === '' || str_ends_with($line, '/') || str_ends_with($line, '\\')) {
				continue;
			}
			$name = self::normaliseName($line);
			if (self::isSafeEntryName($name)) {
				$names[] = $name;
			}
		}
		return $names;
	}

	private static function normaliseName(string $name): string {
		$name = str_replace('\\', '/', $name);
		return preg_replace('#^(\./)+#', '', $name) ?? $name;
	}

	/**
	 * Entry names that may be passed to a tool: no traversal/absolute paths and none of the
	 * characters the tools interpret (wildcards, @listfile).
	 */
	public static function isSafeEntryName(string $name): bool {
		if ($name === '' || strlen($name) > 1024 || !SafeZip::isSafeName($name)) {
			return false;
		}
		return preg_match('/[*?\[\]\r\n]/', $name) !== 1 && $name[0] !== '@';
	}

	/** First non-empty line of a tool's error output for messages (": ERROR: Unsupported Method"), or ''. */
	public static function errorDetail(string $stderr): string {
		foreach (preg_split('/\r?\n/', $stderr) ?: [] as $line) {
			$line = trim($line);
			if ($line !== '') {
				return ': ' . mb_strcut($line, 0, 200, 'UTF-8');
			}
		}
		return '';
	}

	private static function exitOk(string $tool, int $code): bool {
		// 7z: 1 = warning (e.g. a file could not be opened), the result is usable
		return $code === 0 || ($tool === self::TOOL_SEVEN_ZIP && $code === 1);
	}

	// ---- process handling -----------------------------------------------------------------------

	/** @return list<string> tool keys that can read the format and are installed */
	private function readTools(string $format): array {
		$found = $this->detect();
		$out = [];
		foreach (self::READERS[$format] ?? [] as $tool) {
			if ($found[$tool] !== null) {
				$out[] = $tool;
			}
		}
		return $out;
	}

	private function binary(string $tool): string {
		return $this->detect()[$tool] ?? throw new \RuntimeException('Tool not available: ' . $tool);
	}

	/** @return array<string, ?string> */
	private function detect(): array {
		if ($this->found !== null) {
			return $this->found;
		}
		$dirs = self::absoluteDirs($this->searchDirs ?? self::defaultDirs());
		$found = [];
		foreach (self::NAMES as $tool => $names) {
			$found[$tool] = null;
			foreach ($names as $name) {
				$path = self::locate($name, $dirs);
				if ($path !== null) {
					$found[$tool] = $path;
					break;
				}
			}
		}
		return $this->found = $found;
	}

	/**
	 * Only absolute directories are searched: an empty or relative PATH entry (".") would pick up a
	 * planted binary from the current directory.
	 *
	 * @param list<string> $dirs
	 * @return list<string>
	 */
	public static function absoluteDirs(array $dirs): array {
		return array_values(array_filter($dirs, static fn (string $d): bool => $d !== ''
			&& ($d[0] === '/' || $d[0] === '\\' || preg_match('#^[A-Za-z]:[\\\\/]#', $d) === 1)));
	}

	/** @return list<string> */
	private static function defaultDirs(): array {
		$path = getenv('PATH');
		$dirs = is_string($path) && $path !== '' ? explode(PATH_SEPARATOR, $path) : [];
		$dirs = array_merge($dirs, ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin', '/snap/bin']);
		if (PHP_OS_FAMILY === 'Windows') {
			$dirs[] = 'C:\\Program Files\\7-Zip';
		}
		return array_values(array_unique(array_filter($dirs, static fn (string $d): bool => $d !== '')));
	}

	/** @param list<string> $dirs */
	private static function locate(string $name, array $dirs): ?string {
		$candidates = PHP_OS_FAMILY === 'Windows' ? [$name . '.exe'] : [$name];
		foreach ($dirs as $dir) {
			foreach ($candidates as $c) {
				$p = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $c;
				if (@is_file($p) && (PHP_OS_FAMILY === 'Windows' || @is_executable($p))) {
					return $p;
				}
			}
		}
		return null;
	}

	/**
	 * Runs a command without a shell. Output goes to a temp file (portable, no pipe deadlocks) which
	 * is watched for the size limit while the process runs.
	 *
	 * @param list<string> $cmd
	 * @return array{0: int, 1: string, 2: string} exit code, stdout and (the start of) stderr
	 * @throws \RuntimeException on timeout, size limit or start failure
	 */
	private function run(array $cmd, ?string $cwd = null, int $timeout = self::TIMEOUT_SECONDS): array {
		$outFile = tempnam(sys_get_temp_dir(), 'ebra');
		$errFile = tempnam(sys_get_temp_dir(), 'ebre');
		if ($outFile === false || $errFile === false) {
			throw new \RuntimeException('Cannot create temporary file');
		}
		try {
			$proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']], $pipes, $cwd);
			if (!is_resource($proc)) {
				throw new \RuntimeException('Cannot start archive tool');
			}
			fclose($pipes[0]);
			$deadline = microtime(true) + (float)$timeout;
			$exit = -1;
			while (true) {
				$status = proc_get_status($proc);
				if (!$status['running']) {
					$exit = $status['exitcode'];
					break;
				}
				clearstatcache(true, $outFile);
				if ((int)@filesize($outFile) > self::MAX_OUTPUT_BYTES) {
					proc_terminate($proc, 9);
					proc_close($proc);
					throw new \RuntimeException('Archive tool output too large');
				}
				if (microtime(true) > $deadline) {
					proc_terminate($proc, 9);
					proc_close($proc);
					throw new \RuntimeException('Archive tool timed out');
				}
				usleep(5000);
			}
			proc_close($proc);
			clearstatcache(true, $outFile);
			if ((int)@filesize($outFile) > self::MAX_OUTPUT_BYTES) {
				throw new \RuntimeException('Archive tool output too large');
			}
			$data = file_get_contents($outFile);
			$err = @file_get_contents($errFile, false, null, 0, 4096);
			return [$exit, $data === false ? '' : $data, $err === false ? '' : $err];
		} finally {
			@unlink($outFile);
			@unlink($errFile);
		}
	}
}
