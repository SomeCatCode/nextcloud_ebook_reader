<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Owner: W1. occ ebookreader:inspect <file> prints extracted metadata as JSON */
class Inspect extends Command {
	protected function configure(): void {
		$this->setName('ebookreader:inspect')
			->setDescription('Print the metadata extracted from an e-book file as JSON')
			->addArgument('file', InputArgument::REQUIRED, 'Local path of the e-book');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		throw new \RuntimeException('Not implemented: W1');
	}
}
