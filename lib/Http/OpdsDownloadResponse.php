<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/**
 * Streams a file from an open handle in chunks (never loads it into memory) with Content-Length and single HTTP byte ranges.
 *
 * @template-extends Response<Http::STATUS_*, array<string, mixed>>
 */
class OpdsDownloadResponse extends Response implements ICallbackResponse {
	private const CHUNK = 1024 * 1024;

	private int $start = 0;
	private int $length;
	/** @var array<string, string> */
	private array $own = [];

	/**
	 * @param resource $handle readable handle, closed after streaming
	 * @param int $size file size in bytes
	 * @param string|null $range value of the Range request header
	 */
	public function __construct(
		private $handle,
		int $size,
		string $filename,
		string $mime,
		?string $range = null,
	) {
		parent::__construct();
		$this->length = $size;
		$this->put('Content-Type', $mime);
		$this->put('Content-Disposition', self::contentDisposition($filename));
		$this->put('Accept-Ranges', 'bytes');
		$this->put('X-Content-Type-Options', 'nosniff');
		$this->put('Cache-Control', 'private, no-cache');

		$parsed = self::parseRange($range, $size);
		if ($parsed === false) {
			$this->setStatus(416);
			$this->put('Content-Range', 'bytes */' . $size);
			$this->put('Content-Length', '0');
			$this->length = 0;
			return;
		}
		if ($parsed !== null) {
			[$this->start, $end] = $parsed;
			$this->length = $end - $this->start + 1;
			$this->setStatus(Http::STATUS_PARTIAL_CONTENT);
			$this->put('Content-Range', 'bytes ' . $this->start . '-' . $end . '/' . $size);
		}
		$this->put('Content-Length', (string)$this->length);
	}

	private function put(string $name, string $value): void {
		$this->own[$name] = $value;
		$this->addHeader($name, $value);
	}

	/**
	 * The headers set by this response (getHeaders() also merges server defaults).
	 * @return array<string, string>
	 */
	public function getOwnHeaders(): array {
		return $this->own;
	}

	public function getStart(): int {
		return $this->start;
	}

	public function getLength(): int {
		return $this->length;
	}

	/**
	 * Single range only. Null = serve the whole file (no/unsupported/malformed header), false = not satisfiable.
	 * @return array{0: int, 1: int}|false|null inclusive start and end offsets
	 */
	public static function parseRange(?string $header, int $size): array|false|null {
		if ($header === null || preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $header, $m) !== 1) {
			return null;
		}
		[$from, $to] = [$m[1], $m[2]];
		if ($from === '' && $to === '') {
			return null;
		}
		if ($size <= 0) {
			return $from === '' ? null : false;
		}
		if ($from === '') {
			// suffix: the last N bytes
			$n = (int)$to;
			if ($n <= 0) {
				return false;
			}
			return [max(0, $size - $n), $size - 1];
		}
		$start = (int)$from;
		if ($start >= $size) {
			return false;
		}
		$end = $to === '' ? $size - 1 : min((int)$to, $size - 1);
		return $end < $start ? null : [$start, $end];
	}

	/** attachment header with an ASCII fallback and the RFC 5987 UTF-8 name */
	public static function contentDisposition(string $filename): string {
		$filename = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]/', '', $filename) ?? '');
		if ($filename === '') {
			$filename = 'book';
		}
		$ascii = preg_replace('/[^\x20-\x7E]/u', '_', $filename) ?? 'book';
		$ascii = str_replace(['"', '%'], ['_', '_'], $ascii);
		return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
	}

	#[\Override]
	public function callback(IOutput $output) {
		$handle = $this->handle;
		try {
			if ($this->length <= 0) {
				return;
			}
			if ($this->start > 0 && @fseek($handle, $this->start) !== 0) {
				// not seekable: read and discard
				$skip = $this->start;
				while ($skip > 0 && !feof($handle)) {
					$data = fread($handle, min(self::CHUNK, $skip));
					if ($data === false || $data === '') {
						return;
					}
					$skip -= strlen($data);
				}
			}
			$left = $this->length;
			while ($left > 0 && !feof($handle)) {
				$data = fread($handle, min(self::CHUNK, $left));
				if ($data === false || $data === '') {
					break;
				}
				$output->setOutput($data);
				$left -= strlen($data);
			}
		} finally {
			if (is_resource($handle)) {
				fclose($handle);
			}
		}
	}
}
