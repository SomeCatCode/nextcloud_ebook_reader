<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;

/** Owner: W1 */
class CoverService {
	public const SIZES = ['small' => 200, 'large' => 600];
	private const MAX_PIXELS = 40_000_000;
	private const FOLDER = 'covers';

	private ?IAppData $appData = null;

	public function __construct(
		private IAppDataFactory $appDataFactory,
	) {
	}

	/**
	 * Stores small (200px) and large (600px) JPEGs, returns the etag (md5 of the large file).
	 * @throws \InvalidArgumentException if the data is not a usable image
	 */
	public function storeCover(int $fileId, string $imageData): string {
		if (!function_exists('imagecreatefromstring')) {
			throw new \RuntimeException('PHP GD extension is not available');
		}
		$info = @getimagesizefromstring($imageData);
		if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
			throw new \InvalidArgumentException('Not a usable image');
		}
		$src = @imagecreatefromstring($imageData);
		if ($src === false) {
			throw new \InvalidArgumentException('Image cannot be decoded');
		}
		try {
			$large = $this->scaleToJpeg($src, self::SIZES['large']);
			$small = $this->scaleToJpeg($src, self::SIZES['small']);
		} finally {
			unset($src);
		}
		$folder = $this->folder();
		$this->write($folder, $fileId . '-small.jpg', $small);
		$this->write($folder, $fileId . '-large.jpg', $large);
		return md5($large);
	}

	/** @param string $size small|large */
	public function getCover(int $fileId, string $size): ?ISimpleFile {
		$size = $size === 'small' ? 'small' : 'large';
		try {
			$folder = $this->folder();
			$name = $fileId . '-' . $size . '.jpg';
			return $folder->fileExists($name) ? $folder->getFile($name) : null;
		} catch (NotFoundException) {
			return null;
		}
	}

	public function deleteCover(int $fileId): void {
		try {
			$folder = $this->folder();
			foreach (array_keys(self::SIZES) as $size) {
				$name = $fileId . '-' . $size . '.jpg';
				if ($folder->fileExists($name)) {
					$folder->getFile($name)->delete();
				}
			}
		} catch (NotFoundException) {
		}
	}

	private function write(ISimpleFolder $folder, string $name, string $data): void {
		if ($folder->fileExists($name)) {
			$folder->getFile($name)->putContent($data);
		} else {
			$folder->newFile($name, $data);
		}
	}

	private function folder(): ISimpleFolder {
		$this->appData ??= $this->appDataFactory->get(Application::APP_ID);
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}

	/** Scales to the given width (never upscales), flattens transparency on white, returns JPEG bytes. */
	private function scaleToJpeg(\GdImage $src, int $targetWidth): string {
		$w = imagesx($src);
		$h = imagesy($src);
		$nw = min($w, $targetWidth);
		$nh = max(1, (int)round($h * $nw / $w));
		$dst = imagecreatetruecolor($nw, $nh);
		if ($dst === false) {
			throw new \RuntimeException('Cannot allocate image');
		}
		$white = imagecolorallocate($dst, 255, 255, 255);
		if ($white !== false) {
			imagefill($dst, 0, 0, $white);
		}
		imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
		ob_start();
		imagejpeg($dst, null, 85);
		$out = (string)ob_get_clean();
		unset($dst);
		return $out;
	}
}
