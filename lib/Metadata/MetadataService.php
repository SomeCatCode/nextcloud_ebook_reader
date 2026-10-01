<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

use OCA\EbookReader\Service\ArchiveTools;
use OCP\Files\File;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/** Owner: W1. Format detection and metadata extraction (with filename fallback). */
class MetadataService {
	private const EXTENSIONS = [
		'epub' => 'epub',
		'mobi' => 'mobi',
		'azw3' => 'azw3',
		'fb2' => 'fb2',
		'fbz' => 'fbz',
		'cbz' => 'cbz',
		'cbr' => 'cbr',
		'cb7' => 'cb7',
		'cbt' => 'cbt',
	];

	private const MIMES = [
		'application/epub+zip' => 'epub',
		'application/x-mobipocket-ebook' => 'mobi',
		'application/vnd.amazon.mobi8-ebook' => 'azw3',
		'application/x-fictionbook+xml' => 'fb2',
		'application/x-zip-compressed-fb2' => 'fbz',
		'application/vnd.comicbook+zip' => 'cbz',
		'application/comicbook+zip' => 'cbz',
		'application/x-cbz' => 'cbz',
		'application/vnd.comicbook-rar' => 'cbr',
		'application/comicbook+rar' => 'cbr',
		'application/x-cbr' => 'cbr',
		'application/x-cb7' => 'cb7',
		'application/x-cbt' => 'cbt',
	];

	/** @var list<ExtractorInterface> */
	private array $extractors;
	private FilenameExtractor $filenameExtractor;

	public function __construct(
		private ?ITempManager $tempManager = null,
		private ?LoggerInterface $logger = null,
		?ArchiveTools $archiveTools = null,
	) {
		$this->extractors = [
			new EpubExtractor(),
			new MobiExtractor(),
			new Fb2Extractor(),
			new CbzExtractor(),
			new CbrExtractor($archiveTools),
		];
		$this->filenameExtractor = new FilenameExtractor();
	}

	/** @return ?string null = not an e-book */
	public function detectFormat(string $filename, string $mime): ?string {
		$lower = strtolower($filename);
		if (str_ends_with($lower, '.fb2.zip')) {
			return 'fbz';
		}
		$ext = strtolower(pathinfo($lower, PATHINFO_EXTENSION));
		if (isset(self::EXTENSIONS[$ext])) {
			return self::EXTENSIONS[$ext];
		}
		return self::MIMES[strtolower($mime)] ?? null;
	}

	public function extract(File $file, string $format): BookMetadata {
		$tmp = $this->copyToTemp($file);
		try {
			return $this->extractLocal($tmp, $format, $file->getName());
		} finally {
			@unlink($tmp);
		}
	}

	/**
	 * Extracts metadata from a local file. Never throws for unreadable books: falls back to the
	 * file name (a DRM protected or broken book still shows up in the library).
	 */
	public function extractLocal(string $localPath, string $format, ?string $displayName = null): BookMetadata {
		$fromName = $this->filenameExtractor->fromFilename($displayName ?? basename($localPath));
		$meta = null;
		foreach ($this->extractors as $extractor) {
			if (!$extractor->supports($format)) {
				continue;
			}
			try {
				$meta = $extractor instanceof CbrExtractor ? $extractor->extract($localPath, $format) : $extractor->extract($localPath);
			} catch (DrmProtectedException $e) {
				$this->logger?->info('E-book is DRM protected, using file name only: ' . ($displayName ?? ''), ['app' => 'ebookreader']);
			} catch (\Throwable $e) {
				$this->logger?->warning('Metadata extraction failed for ' . ($displayName ?? '') . ': ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
			}
			break;
		}
		if ($meta === null) {
			return $fromName;
		}
		$changes = [];
		if ($meta->title === null || $meta->title === '') {
			$changes['title'] = $fromName->title;
			if ($meta->authors === []) {
				$changes['authors'] = $fromName->authors;
			}
		}
		return $changes === [] ? $meta : $meta->with($changes);
	}

	/** Cover only (used by the preview provider). */
	public function extractCover(File $file, string $format): ?BookMetadata {
		$meta = $this->extract($file, $format);
		return $meta->coverData === null ? null : $meta;
	}

	private function copyToTemp(File $file): string {
		$tmp = $this->tempManager !== null
			? $this->tempManager->getTemporaryFile('.' . $this->safeExt($file->getName()))
			: tempnam(sys_get_temp_dir(), 'ebr');
		if ($tmp === false) {
			throw new \RuntimeException('Cannot create temporary file');
		}
		$in = $file->fopen('r');
		$out = fopen($tmp, 'wb');
		if ($in === false || $out === false) {
			throw new \RuntimeException('Cannot read file');
		}
		try {
			stream_copy_to_stream($in, $out);
		} finally {
			fclose($in);
			fclose($out);
		}
		return $tmp;
	}

	private function safeExt(string $name): string {
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		return preg_match('/^[a-z0-9]{1,5}$/', $ext) === 1 ? $ext : 'tmp';
	}
}
