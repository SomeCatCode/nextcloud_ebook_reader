<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Editor;

use ZipArchive;

/**
 * Builds a new zip from scratch. Large entries are spooled to temp files so that memory stays bounded.
 */
final class ZipWriter {
	private const INLINE_LIMIT = 262144;
	private const STORE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'zip', 'mp3', 'mp4', 'm4a', 'ogg', 'woff', 'woff2', 'jp2'];

	private ZipArchive $zip;
	/** @var list<string> */
	private array $tmp = [];
	private bool $closed = false;

	public function __construct(
		private string $dst,
	) {
		if (is_file($dst)) {
			@unlink($dst);
		}
		$this->zip = new ZipArchive();
		$res = $this->zip->open($dst, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		if ($res !== true) {
			throw new EditorException('The output archive cannot be created (code ' . (string)$res . ').', 500);
		}
	}

	public function addString(string $name, string $content, ?bool $store = null): void {
		if (!$this->zip->addFromString($name, $content)) {
			throw new EditorException('Cannot add entry ' . $name, 500);
		}
		$this->setCompression($name, $store);
	}

	public function copyFrom(ZipArchive $src, string $name): void {
		$this->copyFromAs($src, $name, $name);
	}

	public function copyFromAs(ZipArchive $src, string $name, string $newName): void {
		$st = $src->statName($name);
		if ($st === false) {
			return;
		}
		if ($st['size'] <= self::INLINE_LIMIT) {
			$data = $src->getFromName($name);
			if ($data === false) {
				throw new EditorException('Cannot read entry ' . $name, 422);
			}
			$this->addString($newName, $data);
			return;
		}
		$stream = $src->getStream($name);
		if ($stream === false) {
			throw new EditorException('Cannot read entry ' . $name, 422);
		}
		$tmp = tempnam(sys_get_temp_dir(), 'ebrz');
		if ($tmp === false) {
			fclose($stream);
			throw new EditorException('Cannot create temporary file.', 500);
		}
		$this->tmp[] = $tmp;
		$out = fopen($tmp, 'wb');
		if ($out === false) {
			fclose($stream);
			throw new EditorException('Cannot create temporary file.', 500);
		}
		stream_copy_to_stream($stream, $out);
		fclose($stream);
		fclose($out);
		if (!$this->zip->addFile($tmp, $newName)) {
			throw new EditorException('Cannot add entry ' . $newName, 500);
		}
		$this->setCompression($newName, null);
	}

	private function setCompression(string $name, ?bool $store): void {
		if ($store === null) {
			$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
			$store = in_array($ext, self::STORE_EXT, true);
		}
		$this->zip->setCompressionName($name, $store ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE);
	}

	public function close(): void {
		if ($this->closed) {
			return;
		}
		$this->closed = true;
		$ok = $this->zip->close();
		foreach ($this->tmp as $t) {
			@unlink($t);
		}
		$this->tmp = [];
		if (!$ok) {
			throw new EditorException('The output archive could not be written.', 500);
		}
	}

	public function abort(): void {
		if ($this->closed) {
			return;
		}
		$this->closed = true;
		@$this->zip->unchangeAll();
		@$this->zip->close();
		foreach ($this->tmp as $t) {
			@unlink($t);
		}
		@unlink($this->dst);
	}
}
