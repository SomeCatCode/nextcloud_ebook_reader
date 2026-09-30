<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

/**
 * MOBI / AZW3 (KF8): PDB header + MOBI header + EXTH records.
 * Metadata sits in record 0, so only that record (and the cover record) are read.
 */
class MobiExtractor implements ExtractorInterface {
	private const MAX_RECORD0 = 2 * 1024 * 1024;
	private const MAX_COVER = 20 * 1024 * 1024;

	#[\Override]
	public function supports(string $format): bool {
		return $format === 'mobi' || $format === 'azw3';
	}

	#[\Override]
	public function extract(string $localPath): BookMetadata {
		$fh = @fopen($localPath, 'rb');
		if ($fh === false) {
			throw new \RuntimeException('Cannot open file');
		}
		try {
			return $this->read($fh);
		} finally {
			fclose($fh);
		}
	}

	/** @param resource $fh */
	private function read($fh): BookMetadata {
		$pdb = (string)fread($fh, 78);
		if (strlen($pdb) < 78) {
			throw new \RuntimeException('Not a PDB file');
		}
		$type = substr($pdb, 60, 8);
		if ($type !== 'BOOKMOBI' && $type !== 'TEXtREAd') {
			throw new \RuntimeException('Not a MOBI file');
		}
		/** @var array{n: int} $u */
		$u = unpack('nn', substr($pdb, 76, 2));
		$numRecords = $u['n'];
		if ($numRecords < 1 || $numRecords > 65535) {
			throw new \RuntimeException('Invalid record count');
		}
		$list = (string)fread($fh, 8 * $numRecords);
		if (strlen($list) < 8 * $numRecords) {
			throw new \RuntimeException('Truncated record list');
		}
		$offsets = [];
		for ($i = 0; $i < $numRecords; $i++) {
			/** @var array{o: int} $o */
			$o = unpack('No', substr($list, $i * 8, 4));
			$offsets[] = $o['o'];
		}
		$fileSize = fstat($fh)["size"] ?? 0;
		$start = $offsets[0];
		$end = $numRecords > 1 ? $offsets[1] : $fileSize;
		if ($end <= $start || $end - $start > self::MAX_RECORD0 || $start < 78) {
			throw new \RuntimeException('Invalid record 0');
		}
		fseek($fh, $start);
		$rec0 = (string)fread($fh, $end - $start);
		if (strlen($rec0) < 132 || substr($rec0, 16, 4) !== 'MOBI') {
			throw new \RuntimeException('No MOBI header');
		}
		/** @var array{enc: int} $e */
		$e = unpack('nenc', substr($rec0, 12, 2));
		if ($e['enc'] !== 0) {
			throw new DrmProtectedException('Book is DRM protected');
		}
		/** @var array{hl: int, encoding: int, ver: int} $h */
		$h = unpack('Nhl/x4/Nencoding/x4/Nver', substr($rec0, 20, 20));
		$headerLen = $h['hl'];
		$encoding = $h['encoding'] ?? 65001;
		$read = fn (string $s): string => $this->toUtf8($s, $encoding);

		/** @var array{o: int, l: int} $fn */
		$fn = unpack('No/Nl', substr($rec0, 84, 8));
		/** @var array{i: int} $im */
		$im = unpack('Ni', substr($rec0, 108, 4));
		$firstImage = $im['i'];

		$fullName = null;
		if ($fn['o'] > 0 && $fn['l'] > 0 && $fn['o'] + $fn['l'] <= strlen($rec0)) {
			$fullName = XmlUtil::clean($read(substr($rec0, $fn['o'], $fn['l'])));
		}

		$exth = [];
		$exthPos = 16 + $headerLen;
		/** @var array{f: int} $fl */
		$fl = unpack('Nf', substr($rec0, 128, 4));
		if (($fl['f'] & 0x40) !== 0 && substr($rec0, $exthPos, 4) === 'EXTH') {
			$exth = $this->parseExth($rec0, $exthPos);
		}

		$first = static fn (int $k): ?string => isset($exth[$k][0]) ? XmlUtil::clean($exth[$k][0]) : null;
		$all = fn (int $k): array => array_values(array_filter(array_map(
			static fn (string $s): ?string => XmlUtil::clean($s),
			array_map($read, $exth[$k] ?? [])
		), static fn (?string $s): bool => $s !== null));

		$title = isset($exth[503][0]) ? XmlUtil::clean($read($exth[503][0])) : null;
		$title ??= $fullName;
		$authors = $all(100);
		$publisher = isset($exth[101][0]) ? XmlUtil::clean($read($exth[101][0])) : null;
		$description = null;
		if (isset($exth[103][0])) {
			$d = HtmlSanitizer::sanitize($read($exth[103][0]));
			$description = $d === '' ? null : $d;
		}
		$isbn = $first(104);
		if ($isbn !== null) {
			$isbn = strtoupper(str_replace(['-', ' '], '', $read($isbn)));
		}
		$subjects = [];
		foreach ($all(105) as $s) {
			foreach (explode(';', $s) as $part) {
				$part = trim($part);
				if ($part !== '' && !in_array($part, $subjects, true)) {
					$subjects[] = $part;
				}
			}
		}
		$publishedAt = null;
		if (isset($exth[106][0]) && preg_match('/^\d{4}(-\d{2}(-\d{2})?)?/', $exth[106][0], $m) === 1) {
			$publishedAt = $m[0];
		}
		$language = isset($exth[524][0]) ? XmlUtil::clean($read($exth[524][0])) : null;

		$coverData = null;
		$coverMime = null;
		if ($firstImage !== 0xFFFFFFFF) {
			foreach ([201, 202] as $k) {
				if (!isset($exth[$k][0]) || strlen($exth[$k][0]) !== 4) {
					continue;
				}
				/** @var array{c: int} $c */
				$c = unpack('Nc', $exth[$k][0]);
				$idx = $firstImage + $c['c'];
				$img = $this->readRecord($fh, $offsets, $idx, $fileSize);
				$mime = $img === null ? null : ImageUtil::mime($img);
				if ($img !== null && $mime !== null) {
					$coverData = $img;
					$coverMime = $mime;
					break;
				}
			}
		}

		return new BookMetadata(
			title: $title,
			authors: $authors,
			description: $description,
			language: $language,
			publisher: $publisher,
			isbn: $isbn === '' ? null : $isbn,
			publishedAt: $publishedAt,
			subjects: $subjects,
			coverData: $coverData,
			coverMime: $coverMime,
		);
	}

	/** @return array<int, list<string>> record type => raw values */
	private function parseExth(string $rec0, int $pos): array {
		/** @var array{len: int, n: int} $u */
		$u = unpack('Nlen/Nn', substr($rec0, $pos + 4, 8));
		$p = $pos + 12;
		$out = [];
		$n = min($u['n'], 1000);
		for ($i = 0; $i < $n; $i++) {
			if ($p + 8 > strlen($rec0)) {
				break;
			}
			/** @var array{t: int, l: int} $r */
			$r = unpack('Nt/Nl', substr($rec0, $p, 8));
			if ($r['l'] < 8 || $p + $r['l'] > strlen($rec0)) {
				break;
			}
			$out[$r['t']][] = substr($rec0, $p + 8, $r['l'] - 8);
			$p += $r['l'];
		}
		return $out;
	}

	/**
	 * @param resource $fh
	 * @param list<int> $offsets
	 */
	private function readRecord($fh, array $offsets, int $idx, int $fileSize): ?string {
		if ($idx < 0 || $idx >= count($offsets)) {
			return null;
		}
		$start = $offsets[$idx];
		$end = $idx + 1 < count($offsets) ? $offsets[$idx + 1] : $fileSize;
		if ($end <= $start || $end - $start > self::MAX_COVER) {
			return null;
		}
		fseek($fh, $start);
		$d = fread($fh, $end - $start);
		return $d === false ? null : $d;
	}

	private function toUtf8(string $s, int $encoding): string {
		if ($encoding === 65001) {
			return $s;
		}
		return mb_convert_encoding($s, "UTF-8", "Windows-1252") ?: $s;
	}
}
