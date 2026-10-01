<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\Metadata\SafeZip;

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
	/** @var array<string, string> archive path => tool that listed it successfully */
	private array $listedWith = [];

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
				[$code, $out] = $this->run(self::commandFor($tool, 'list', $this->binary($tool), $path));
				if (!self::exitOk($tool, $code)) {
					throw new \RuntimeException($tool . ' exited with ' . $code);
				}
				$names = self::parseList($tool, $out);
				$this->listedWith[$path] = $tool;
				return $names;
			} catch (\RuntimeException $e) {
				$last = $e->getMessage();
			}
		}
		throw new \RuntimeException('Cannot list archive: ' . $last);
	}

	/**
	 * Content of a single entry.
	 *
	 * @throws \RuntimeException
	 */
	public function extract(string $path, string $entry): string {
		if (!self::isSafeEntryName($entry)) {
			throw new \RuntimeException('Unsafe archive entry name');
		}
		$tool = $this->listedWith[$path] ?? null;
		if ($tool === null) {
			$this->list($path);
			$tool = $this->listedWith[$path] ?? null;
		}
		if ($tool === null) {
			throw new \RuntimeException('No archive tool available');
		}
		[$code, $out] = $this->run(self::commandFor($tool, 'extract', $this->binary($tool), $path, $entry));
		if (!self::exitOk($tool, $code)) {
			throw new \RuntimeException('Cannot extract entry (exit ' . $code . ')');
		}
		return $out;
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
	public static function commandFor(string $tool, string $op, string $binary, string $archive, ?string $entry = null): array {
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
		if ($op === 'extract') {
			$cmd[] = $entry;
		}
		return $cmd;
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
	 * Parses `7z l -slt` output (technical listing: "Key = Value" blocks separated by empty lines).
	 *
	 * @return list<string> file entries
	 */
	public static function parseSevenZipList(string $output): array {
		$names = [];
		/** @var array<string, string> $block */
		$block = [];
		foreach (preg_split('/?
/', $output) ?: [] as $line) {
			if ($line === '----------') {
				// everything before is the archive header (without -ba)
				$block = [];
				$names = [];
				continue;
			}
			if (trim($line) === '') {
				$name = self::sevenZipEntry($block);
				if ($name !== null) {
					$names[] = $name;
				}
				$block = [];
				continue;
			}
			$pos = strpos($line, ' = ');
			if ($pos !== false) {
				$block[substr($line, 0, $pos)] = substr($line, $pos + 3);
			}
		}
		$name = self::sevenZipEntry($block);
		if ($name !== null) {
			$names[] = $name;
		}
		return $names;
	}

	/**
	 * @param array<string, string> $block one "Key = Value" block of the technical listing
	 */
	private static function sevenZipEntry(array $block): ?string {
		if (!isset($block['Path'])) {
			return null;
		}
		$attributes = $block['Attributes'] ?? '';
		if (($block['Folder'] ?? '') === '+' || ($attributes !== '' && $attributes[0] === 'D')) {
			return null;
		}
		$name = self::normaliseName($block['Path']);
		return self::isSafeEntryName($name) ? $name : null;
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
		$dirs = $this->searchDirs ?? self::defaultDirs();
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
	 * @return array{0: int, 1: string} exit code and stdout
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
			return [$exit, $data === false ? '' : $data];
		} finally {
			@unlink($outFile);
			@unlink($errFile);
		}
	}
}
