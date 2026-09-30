<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Migration;

use OCA\EbookReader\AppInfo\Application;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IMimeTypeLoader;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Owner: W1. Registers e-book MIME types that Nextcloud core does not know yet.
 *
 * Nextcloud 34 has no app API for MIME types. The supported way is the (admin-editable)
 * config/mimetypemapping.json, which the MimeTypeDetector merges over its built-in mapping.
 * Core already maps epub, mobi, fb2, cbz and cbr; only extensions missing from
 * IMimeTypeDetector::getAllMappings() are added (never overriding existing entries), then the
 * filecache rows of existing files are updated via IMimeTypeLoader::updateFilecache().
 */
class RegisterMimeTypes implements IRepairStep {
	public function __construct(
		private IMimeTypeDetector $detector,
		private IMimeTypeLoader $loader,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Register e-book MIME types';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$known = $this->detector->getAllMappings();
		$missing = [];
		foreach (Application::MIME_TYPES as $ext => $mime) {
			if (!isset($known[$ext])) {
				$missing[$ext] = $mime;
			}
		}
		if ($missing === []) {
			$output->info('All e-book MIME types are already registered');
			return;
		}

		$file = $this->configFile();
		if ($file === null) {
			$output->warning('Could not locate the config directory; e-book MIME types not registered');
			return;
		}
		$custom = [];
		if (is_file($file)) {
			$decoded = json_decode((string)file_get_contents($file), true);
			if (is_array($decoded)) {
				$custom = $decoded;
			} elseif (trim((string)file_get_contents($file)) !== '') {
				$output->warning('config/mimetypemapping.json is not valid JSON; leaving it untouched');
				return;
			}
		}
		$changed = false;
		foreach ($missing as $ext => $mime) {
			if (!isset($custom[$ext])) {
				$custom[$ext] = [$mime];
				$changed = true;
			}
		}
		if ($changed) {
			$json = json_encode($custom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			if ($json === false || @file_put_contents($file, $json . "\n") === false) {
				$output->warning('Cannot write ' . $file . '; add the e-book MIME types manually (see docs)');
				$this->logger->warning('Cannot write ' . $file, ['app' => 'ebookreader']);
				return;
			}
		}
		foreach ($missing as $ext => $mime) {
			try {
				$id = $this->loader->getId($mime);
				$n = $this->loader->updateFilecache($ext, $id);
				$output->info('Registered .' . $ext . ' as ' . $mime . ' (' . $n . ' existing files updated)');
			} catch (\Throwable $e) {
				$this->logger->warning('Updating filecache for .' . $ext . ' failed: ' . $e->getMessage(), ['app' => 'ebookreader']);
			}
		}
	}

	/** @psalm-suppress UndefinedClass, MixedAssignment */
	private function configFile(): ?string {
		$dir = null;
		if (class_exists(\OC::class) && isset(\OC::$configDir)) {
			$dir = \OC::$configDir;
		}
		if (!is_string($dir) || $dir === '' || !is_dir($dir)) {
			return null;
		}
		return rtrim($dir, '/\\') . '/mimetypemapping.json';
	}
}
