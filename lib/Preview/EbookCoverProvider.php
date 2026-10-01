<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Preview;

use OCA\EbookReader\Metadata\MetadataService;
use OCA\EbookReader\Service\ArchiveTools;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IImage;
use OCP\Image;
use OCP\Preview\IProviderV2;
use Psr\Log\LoggerInterface;

/** Owner: W1. Delivers the extracted cover as preview. */
class EbookCoverProvider implements IProviderV2 {
	/** Includes both the core (application/comicbook+zip) and the contract (vnd.comicbook) comic mime types. */
	public const MIME_REGEX = '/^application\/(epub\+zip|x-mobipocket-ebook|vnd\.amazon\.mobi8-ebook|x-fictionbook\+xml|x-zip-compressed-fb2|(vnd\.)?comicbook[+-](zip|rar)|x-cb7|x-cbt)$/';

	public function __construct(
		private MetadataService $metadata,
		private LoggerInterface $logger,
		private ArchiveTools $archiveTools,
	) {
	}

	#[\Override]
	public function getMimeType(): string {
		return self::MIME_REGEX;
	}

	#[\Override]
	public function isAvailable(FileInfo $file): bool {
		$format = $this->metadata->detectFormat($file->getName(), $file->getMimetype());
		// CBR/CB7 covers need a server tool, otherwise the client uploads them
		return $format !== null && $this->coverReadable($format);
	}

	#[\Override]
	public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage {
		$format = $this->metadata->detectFormat($file->getName(), $file->getMimeType());
		if ($format === null || !$this->coverReadable($format)) {
			return null;
		}
		try {
			$meta = $this->metadata->extract($file, $format);
			if ($meta->coverData === null) {
				return null;
			}
			$image = new Image();
			$image->loadFromData($meta->coverData);
			if (!$image->valid()) {
				return null;
			}
			$image->scaleDownToFit($maxX, $maxY);
			return $image;
		} catch (\Throwable $e) {
			$this->logger->info('Cover preview failed: ' . $e->getMessage(), ['app' => 'ebookreader']);
			return null;
		}
	}

	private function coverReadable(string $format): bool {
		return !in_array($format, ['cbr', 'cb7'], true) || $this->archiveTools->canRead($format);
	}
}
