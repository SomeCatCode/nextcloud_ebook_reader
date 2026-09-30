<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Editor;

use DOMDocument;
use ZipArchive;

/**
 * Small shared helpers of the editors (private to lib/Editor on purpose, so the editors do not depend on other workers' code).
 */
final class EditorUtil {
	public const MAX_XML_BYTES = 50 * 1024 * 1024;
	public const MAX_ENTRIES = 100000;
	public const MAX_TOTAL_BYTES = 4 * 1024 * 1024 * 1024;

	/** @var array<string, string> mime => extension */
	private const IMAGE_TYPES = [
		'image/jpeg' => 'jpg',
		'image/png' => 'png',
		'image/gif' => 'gif',
		'image/webp' => 'webp',
	];

	public static function openZip(string $path): ZipArchive {
		$zip = new ZipArchive();
		$res = $zip->open($path);
		if ($res !== true) {
			throw new EditorException('The archive cannot be opened (code ' . (string)$res . ').', 422);
		}
		if ($zip->numFiles > self::MAX_ENTRIES) {
			$zip->close();
			throw new EditorException('The archive has too many entries.', 422);
		}
		$total = 0;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$st = $zip->statIndex($i);
			if ($st !== false) {
				$total += (int)$st['size'];
			}
		}
		if ($total > self::MAX_TOTAL_BYTES) {
			$zip->close();
			throw new EditorException('The archive is too large when unpacked.', 413);
		}
		return $zip;
	}

	/** Entry names that must never be copied/served (zip slip, absolute paths). */
	public static function isSafeName(string $name): bool {
		if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('~^[A-Za-z]:~', $name) === 1) {
			return false;
		}
		foreach (explode('/', str_replace('\\', '/', $name)) as $seg) {
			if ($seg === '..') {
				return false;
			}
		}
		return true;
	}

	public static function readEntry(ZipArchive $zip, string $name, int $max = self::MAX_XML_BYTES): ?string {
		if (!self::isSafeName($name)) {
			return null;
		}
		$st = $zip->statName($name);
		if ($st === false || $st['size'] > $max) {
			return null;
		}
		$data = $zip->getFromName($name);
		return $data === false ? null : $data;
	}

	/** @throws EditorException */
	public static function loadXml(string $xml, string $what = 'XML'): DOMDocument {
		$prev = libxml_use_internal_errors(true);
		try {
			$dom = new DOMDocument('1.0', 'UTF-8');
			$dom->preserveWhiteSpace = true;
			$dom->formatOutput = false;
			// no LIBXML_NOENT / DTDLOAD: no entity expansion, no external resources (XXE)
			$ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
			if ($ok === false || $dom->documentElement === null) {
				$err = libxml_get_last_error();
				throw new EditorException($what . ' is not well-formed' . ($err !== false ? ': ' . trim($err->message) : '') . '.', 422);
			}
			return $dom;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($prev);
		}
	}

	public static function isWellFormed(string $xml): bool {
		try {
			self::loadXml($xml);
			return true;
		} catch (EditorException) {
			return false;
		}
	}

	public static function normalizeSpace(string $s): string {
		return trim((string)preg_replace('/\s+/u', ' ', $s));
	}

	/** HTML (sanitized description) to plain text with paragraph breaks. */
	public static function htmlToText(?string $html): string {
		if ($html === null || trim($html) === '') {
			return '';
		}
		$h = (string)preg_replace('~<\s*br\s*/?>~i', "\n", $html);
		$h = (string)preg_replace('~<\s*li[^>]*>~i', "\n\u{2022} ", $h);
		$h = (string)preg_replace('~</\s*(p|div|ul|ol|h[1-6])\s*>~i', "\n\n", $h);
		$h = strip_tags($h);
		$h = html_entity_decode($h, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$h = str_replace(["\r\n", "\r"], "\n", $h);
		$h = self::xmlSafe($h);
		$h = (string)preg_replace("/[ \t]+\n/", "\n", $h);
		$h = (string)preg_replace("/\n{3,}/", "\n\n", $h);
		return trim($h);
	}

	/** Removes characters that are invalid in XML 1.0. */
	public static function xmlSafe(string $s): string {
		return (string)preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
	}

	/** @return ?array{mime: string, ext: string} */
	public static function imageInfo(string $data): ?array {
		$mime = null;
		if (str_starts_with($data, "\xFF\xD8\xFF")) {
			$mime = 'image/jpeg';
		} elseif (str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
			$mime = 'image/png';
		} elseif (str_starts_with($data, 'GIF87a') || str_starts_with($data, 'GIF89a')) {
			$mime = 'image/gif';
		} elseif (strlen($data) > 12 && str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP') {
			$mime = 'image/webp';
		}
		if ($mime === null) {
			return null;
		}
		return ['mime' => $mime, 'ext' => self::IMAGE_TYPES[$mime]];
	}

	/**
	 * @param array<string, mixed> $cover
	 * @return array{data: string, mime: string, ext: string}
	 */
	public static function decodeCoverUpload(array $cover): array {
		$raw = $cover['data'] ?? null;
		if (!is_string($raw) || $raw === '') {
			throw new InvalidEditRequestException('Cover upload without data.');
		}
		if (preg_match('~^data:[^,]*;base64,~i', $raw, $m) === 1) {
			$raw = substr($raw, strlen($m[0]));
		}
		$bin = base64_decode($raw, true);
		if ($bin === false || $bin === '') {
			throw new InvalidEditRequestException('Cover data is not valid base64.');
		}
		if (strlen($bin) > 30 * 1024 * 1024) {
			throw new InvalidEditRequestException('Cover image is too large.');
		}
		$info = self::imageInfo($bin);
		if ($info === null) {
			throw new InvalidEditRequestException('Cover must be a JPEG, PNG, GIF or WebP image.');
		}
		return ['data' => $bin, 'mime' => $info['mime'], 'ext' => $info['ext']];
	}

	public static function resolvePath(string $baseDir, string $ref): string {
		$ref = str_replace('\\', '/', $ref);
		if (str_starts_with($ref, '/')) {
			$full = ltrim($ref, '/');
		} else {
			$full = ($baseDir === '' || $baseDir === '.' ? '' : rtrim($baseDir, '/') . '/') . $ref;
		}
		$out = [];
		foreach (explode('/', $full) as $seg) {
			if ($seg === '' || $seg === '.') {
				continue;
			}
			if ($seg === '..') {
				array_pop($out);
				continue;
			}
			$out[] = $seg;
		}
		return implode('/', $out);
	}

	public static function dirName(string $path): string {
		$d = dirname($path);
		return $d === '.' ? '' : $d;
	}

	/** Relative URL (not encoded) from directory $fromDir to zip path $to. */
	public static function relativePath(string $fromDir, string $to): string {
		$from = $fromDir === '' ? [] : explode('/', $fromDir);
		$dest = explode('/', $to);
		$file = array_pop($dest);
		$i = 0;
		while ($i < count($from) && $i < count($dest) && $from[$i] === $dest[$i]) {
			$i++;
		}
		$up = array_fill(0, count($from) - $i, '..');
		return implode('/', array_merge($up, array_slice($dest, $i), [$file]));
	}

	public static function encodeUrl(string $path): string {
		return implode('/', array_map('rawurlencode', explode('/', $path)));
	}

	/** True if the URL has a scheme (http:, mailto:, data:, ...) or is protocol-relative. */
	public static function isExternalUrl(string $url): bool {
		return preg_match('~^([a-z][a-z0-9+.-]*:|//)~i', $url) === 1;
	}

	/**
	 * Resource references (href/src/xlink:href/url()) of a markup/CSS file, resolved to zip paths.
	 * @return list<string>
	 */
	public static function references(string $path, string $content): array {
		$dir = self::dirName($path);
		$refs = [];
		$found = [];
		if (preg_match_all('~\b(?:href|src|xlink:href|poster)\s*=\s*(["\'])(.*?)\1~is', $content, $m) > 0) {
			array_push($found, ...$m[2]);
		}
		if (preg_match_all('~url\(\s*(["\']?)(.*?)\1\s*\)~is', $content, $m) > 0) {
			array_push($found, ...$m[2]);
		}
		if (preg_match_all('~@import\s+(["\'])(.*?)\1~is', $content, $m) > 0) {
			array_push($found, ...$m[2]);
		}
		foreach ($found as $url) {
			$url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			if ($url === '' || $url[0] === '#' || self::isExternalUrl($url)) {
				continue;
			}
			$url = (string)preg_replace('~[#?].*$~s', '', $url);
			if ($url === '') {
				continue;
			}
			$refs[self::resolvePath($dir, rawurldecode($url))] = true;
		}
		return array_map('strval', array_keys($refs));
	}
}
