<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\Metadata\ComicArchive;
use OCA\EbookReader\Metadata\ImageUtil;

/**
 * Optional, lossy downscaling of comic pages (GD). Pages that are already small enough, formats GD cannot
 * handle safely and results that are not smaller than the original are passed through byte for byte.
 */
class ImageOptimizer {
	/** Allowed values of maxHeight besides 0 (= off) */
	public const HEIGHTS = [2560, 1920];
	public const DEFAULT_QUALITY = 85;
	public const MIN_QUALITY = 70;
	public const MAX_QUALITY = 95;
	/** Pages with more pixels are never decoded (decompression bomb), same limit as the page scaling of ComicController */
	public const MAX_PIXELS = 40_000_000;
	/** Estimate: pages read to measure dimensions (all pages of smaller comics) */
	public const SAMPLE_PAGES = 16;
	/** Estimate: pages that are actually re-encoded to measure the size ratio */
	public const SAMPLE_REENCODE = 3;
	/** Assumed size ratio of changed pages when nothing could be measured */
	public const DEFAULT_RATIO = 0.6;

	/** Whether the server can optimize images (GD with JPEG support). */
	public static function available(): bool {
		if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg') || !function_exists('imagecreatetruecolor')) {
			return false;
		}
		$info = function_exists('gd_info') ? gd_info() : [];
		return (bool)($info['JPEG Support'] ?? false);
	}

	/**
	 * Validates raw request options.
	 *
	 * @param array<array-key, mixed> $raw maxHeight, jpegQuality, pngToJpeg
	 * @return array{maxHeight: int, jpegQuality: int, pngToJpeg: bool}
	 * @throws ConvertException 400 for values outside the allowlist
	 */
	public static function normalise(array $raw): array {
		$height = $raw['maxHeight'] ?? 0;
		if (is_string($height) && ctype_digit($height)) {
			$height = (int)$height;
		}
		if (!is_int($height) || ($height !== 0 && !in_array($height, self::HEIGHTS, true))) {
			throw new ConvertException('Invalid maximum height', 400);
		}
		$quality = $raw['jpegQuality'] ?? self::DEFAULT_QUALITY;
		if (is_string($quality) && ctype_digit($quality)) {
			$quality = (int)$quality;
		}
		if (!is_int($quality) || $quality < self::MIN_QUALITY || $quality > self::MAX_QUALITY) {
			throw new ConvertException('Invalid JPEG quality', 400);
		}
		$png = $raw['pngToJpeg'] ?? false;
		if (is_string($png)) {
			$png = in_array(strtolower($png), ['1', 'true', 'on'], true);
		}
		if (!is_bool($png)) {
			throw new ConvertException('Invalid PNG option', 400);
		}
		return ['maxHeight' => $height, 'jpegQuality' => $quality, 'pngToJpeg' => $png];
	}

	/**
	 * Whether the options change anything at all.
	 *
	 * @param array{maxHeight: int, jpegQuality: int, pngToJpeg: bool} $options
	 */
	public static function isActive(array $options): bool {
		return $options['maxHeight'] > 0 || $options['pngToJpeg'];
	}

	/**
	 * Size after scaling so that the height is at most $maxHeight (aspect ratio kept, never upscaled).
	 *
	 * @return ?array{0: int, 1: int} width, height; null if nothing has to be scaled
	 */
	public static function targetSize(int $width, int $height, int $maxHeight): ?array {
		if ($maxHeight <= 0 || $width < 1 || $height < 1 || $height <= $maxHeight) {
			return null;
		}
		return [max(1, (int)round((float)($width * $maxHeight) / (float)$height)), $maxHeight];
	}

	/** The re-encoded page is only used when it is smaller than the original. */
	public static function keepOriginal(int $originalBytes, int $newBytes): bool {
		return $newBytes <= 0 || $newBytes >= $originalBytes;
	}

	/** File name of a page: zero padded number plus extension (jpeg is written as jpg). */
	public static function pageName(int $index, int $count, string $ext): string {
		$ext = strtolower($ext);
		$ext = $ext === 'jpeg' ? 'jpg' : $ext;
		return sprintf('%0' . max(4, strlen((string)$count)) . 'd.%s', $index + 1, $ext);
	}

	/** Whether decoding fits into the memory that is left (limit -1 = unlimited). */
	public static function fitsMemory(int $width, int $height, int $usage, int $limit): bool {
		if ($limit < 0) {
			return true;
		}
		// decoded source and scaled copy, 4 bytes per pixel each, plus headroom
		$needed = (float)$width * (float)$height * 7.2;
		return (float)$usage + $needed < (float)$limit * 0.9;
	}

	/** memory_limit in bytes, -1 = unlimited. */
	public static function memoryLimit(): int {
		$v = trim((string)ini_get('memory_limit'));
		if ($v === '' || $v === '-1') {
			return -1;
		}
		$n = (int)$v;
		return match (strtolower(substr($v, -1))) {
			'g' => $n * 1024 * 1024 * 1024,
			'm' => $n * 1024 * 1024,
			'k' => $n * 1024,
			default => $n,
		};
	}

	/**
	 * Whether a PNG has transparency (colour type with alpha channel or a tRNS chunk). Pages with alpha stay PNG.
	 */
	public static function pngHasAlpha(string $data): bool {
		if (strlen($data) < 26) {
			return true;
		}
		$type = ord($data[25]);
		if ($type === 4 || $type === 6) {
			return true;
		}
		// tRNS has to precede the first IDAT
		$idat = strpos($data, 'IDAT');
		$trns = strpos($data, 'tRNS');
		return $trns !== false && ($idat === false || $trns < $idat);
	}

	/**
	 * Optimizes one page.
	 *
	 * @param array{maxHeight: int, jpegQuality: int, pngToJpeg: bool} $options
	 * @return array{data: string, ext: ?string, changed: bool} ext is null when the page is unchanged (keep its extension)
	 */
	public function optimizePage(string $data, array $options): array {
		$same = ['data' => $data, 'ext' => null, 'changed' => false];
		$mime = ImageUtil::mime($data);
		if (($mime !== 'image/jpeg' && $mime !== 'image/png') || !self::available()) {
			return $same;
		}
		$size = @getimagesizefromstring($data);
		if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
			return $same;
		}
		[$w, $h] = [$size[0], $size[1]];
		$target = self::targetSize($w, $h, $options['maxHeight']);
		$png = $mime === 'image/png';
		$toJpeg = $png && $options['pngToJpeg'] && !self::pngHasAlpha($data);
		if ($target === null && !$toJpeg) {
			return $same;
		}
		if (!self::fitsMemory($w, $h, memory_get_usage(), self::memoryLimit())) {
			return $same;
		}
		$src = @imagecreatefromstring($data);
		if ($src === false) {
			return $same;
		}
		$asJpeg = !$png || $toJpeg;
		[$nw, $nh] = $target ?? [$w, $h];
		$dst = imagecreatetruecolor($nw, $nh);
		if ($asJpeg) {
			imagefill($dst, 0, 0, (int)imagecolorallocate($dst, 255, 255, 255));
		} else {
			imagealphablending($dst, false);
			imagesavealpha($dst, true);
			imagefill($dst, 0, 0, (int)imagecolorallocatealpha($dst, 0, 0, 0, 127));
		}
		if ($target !== null) {
			imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
		} else {
			imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
		}
		unset($src);
		ob_start();
		if ($asJpeg) {
			imageinterlace($dst, true);
			imagejpeg($dst, null, $options['jpegQuality']);
		} else {
			imagepng($dst, null, 9);
		}
		$out = (string)ob_get_clean();
		unset($dst);
		if (self::keepOriginal(strlen($data), strlen($out))) {
			return $same;
		}
		return ['data' => $out, 'ext' => $asJpeg ? 'jpg' : 'png', 'changed' => true];
	}

	/**
	 * Estimates the result without re-encoding the whole comic: pages are sampled for their dimensions and up to
	 * three pages that would change are really re-encoded to measure the size ratio.
	 *
	 * @param list<string> $pages
	 * @param array{maxHeight: int, jpegQuality: int, pngToJpeg: bool} $options
	 * @return array{pages: int, oversizedPages: int, currentBytes: int, estimatedBytes: int, exact: bool}
	 */
	public function estimate(ComicArchive $archive, array $pages, array $options, int $currentBytes): array {
		$count = count($pages);
		$indices = self::sampleIndices($count, self::SAMPLE_PAGES);
		$samples = [];
		$reencoded = 0;
		foreach ($indices as $i) {
			$data = $archive->read($pages[$i]);
			if ($data === null) {
				continue;
			}
			$bytes = strlen($data);
			$changes = $this->wouldChange($data, $options);
			$newBytes = null;
			if ($changes && $reencoded < self::SAMPLE_REENCODE) {
				$reencoded++;
				$res = $this->optimizePage($data, $options);
				$newBytes = strlen($res['data']);
				// a page that would be kept after all does not count as changed
				$changes = $res['changed'];
			}
			$samples[] = ['bytes' => $bytes, 'changes' => $changes, 'newBytes' => $newBytes];
			unset($data);
		}
		return self::extrapolate($count, $currentBytes, $samples, count($indices) === $count);
	}

	/**
	 * Whether a page is large (or a PNG that can become a JPEG), judged from the image header only.
	 *
	 * @param array{maxHeight: int, jpegQuality: int, pngToJpeg: bool} $options
	 */
	public function wouldChange(string $data, array $options): bool {
		$mime = ImageUtil::mime($data);
		if ($mime !== 'image/jpeg' && $mime !== 'image/png') {
			return false;
		}
		$size = @getimagesizefromstring($data);
		if ($size === false || $size[0] * $size[1] > self::MAX_PIXELS) {
			return false;
		}
		return self::targetSize($size[0], $size[1], $options['maxHeight']) !== null
			|| ($mime === 'image/png' && $options['pngToJpeg'] && !self::pngHasAlpha($data));
	}

	/**
	 * Evenly spread sample positions (all pages when there are at most $max).
	 *
	 * @return list<int>
	 */
	public static function sampleIndices(int $count, int $max): array {
		if ($count <= 0) {
			return [];
		}
		if ($count <= $max) {
			return range(0, $count - 1);
		}
		$out = [];
		for ($k = 0; $k < $max; $k++) {
			$out[] = (int)floor((float)($k * ($count - 1)) / (float)($max - 1));
		}
		return array_values(array_unique($out));
	}

	/**
	 * Scales the sample to the whole comic. The ratio comes from the re-encoded samples; the share of changed pages and
	 * bytes from all samples.
	 *
	 * @param list<array{bytes: int, changes: bool, newBytes: ?int}> $samples
	 * @return array{pages: int, oversizedPages: int, currentBytes: int, estimatedBytes: int, exact: bool}
	 */
	public static function extrapolate(int $pages, int $currentBytes, array $samples, bool $exact = false): array {
		$sampleBytes = 0;
		$changedBytes = 0;
		$changed = 0;
		$measuredOld = 0;
		$measuredNew = 0;
		foreach ($samples as $s) {
			$sampleBytes += $s['bytes'];
			if ($s['changes']) {
				$changed++;
				$changedBytes += $s['bytes'];
				if ($s['newBytes'] !== null) {
					$measuredOld += $s['bytes'];
					$measuredNew += $s['newBytes'];
				}
			}
		}
		$n = count($samples);
		if ($n === 0 || $changed === 0) {
			return ['pages' => $pages, 'oversizedPages' => 0, 'currentBytes' => $currentBytes, 'estimatedBytes' => $currentBytes, 'exact' => $exact && $n > 0];
		}
		$ratio = $measuredOld > 0 ? min(1.0, (float)$measuredNew / (float)$measuredOld) : self::DEFAULT_RATIO;
		$byteShare = $sampleBytes > 0 ? (float)$changedBytes / (float)$sampleBytes : (float)$changed / (float)$n;
		$oversized = $exact ? $changed : min($pages, (int)round((float)$pages * (float)$changed / (float)$n));
		$estimated = (int)round((float)$currentBytes * (1.0 - $byteShare * (1.0 - $ratio)));
		return ['pages' => $pages, 'oversizedPages' => $oversized, 'currentBytes' => $currentBytes, 'estimatedBytes' => max(0, min($currentBytes, $estimated)), 'exact' => $exact];
	}
}
