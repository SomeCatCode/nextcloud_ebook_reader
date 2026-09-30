<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Command;

use OCA\EbookReader\Metadata\BookMetadata;
use OCA\EbookReader\Metadata\MetadataService;
use OCA\EbookReader\Service\LibraryService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Owner: W1. occ ebookreader:inspect <file> | --user U --file-id N prints extracted metadata as JSON */
class Inspect extends Command {
	public function __construct(
		private MetadataService $metadata,
		private ?LibraryService $library = null,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('ebookreader:inspect')
			->setDescription('Print the metadata extracted from an e-book file as JSON')
			->addArgument('file', InputArgument::OPTIONAL, 'Local path of the e-book')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'User id (with --file-id)')
			->addOption('file-id', null, InputOption::VALUE_REQUIRED, 'Nextcloud file id (with --user)');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$path = $input->getArgument('file');
		$user = $input->getOption('user');
		$fileId = $input->getOption('file-id');
		try {
			if (is_string($path) && $path !== '') {
				if (!is_file($path)) {
					$output->writeln('<error>File not found: ' . $path . '</error>');
					return Command::FAILURE;
				}
				$format = $this->metadata->detectFormat(basename($path), '');
				if ($format === null) {
					$output->writeln('<error>Unsupported file type</error>');
					return Command::FAILURE;
				}
				$meta = $this->metadata->extractLocal($path, $format, basename($path));
			} elseif (is_string($user) && $user !== '' && is_numeric($fileId) && $this->library !== null) {
				$file = $this->library->getFileForUser($user, (int)$fileId);
				$format = $this->metadata->detectFormat($file->getName(), $file->getMimeType());
				if ($format === null) {
					$output->writeln('<error>Unsupported file type</error>');
					return Command::FAILURE;
				}
				$meta = $this->metadata->extract($file, $format);
			} else {
				$output->writeln('<error>Give a local file path or --user and --file-id</error>');
				return Command::INVALID;
			}
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}
		$output->writeln((string)json_encode(
			$this->toArray($meta, $format),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		));
		return Command::SUCCESS;
	}

	/** @return array<string, mixed> */
	private function toArray(BookMetadata $m, string $format): array {
		return [
			'format' => $format,
			'title' => $m->title,
			'authors' => $m->authors,
			'series' => $m->series,
			'seriesIndex' => $m->seriesIndex,
			'description' => $m->description,
			'language' => $m->language,
			'publisher' => $m->publisher,
			'isbn' => $m->isbn,
			'publishedAt' => $m->publishedAt,
			'genres' => $m->genres,
			'tags' => $m->tags,
			'subjects' => $m->subjects,
			'cover' => $m->coverData === null ? null : ['mime' => $m->coverMime, 'bytes' => strlen($m->coverData)],
		];
	}
}
