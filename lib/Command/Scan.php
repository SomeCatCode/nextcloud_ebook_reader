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

/** Owner: W1. occ ebookreader:scan [user_id] */
class Scan extends Command {
	protected function configure(): void {
		$this->setName('ebookreader:scan')
			->setDescription('Scan library folders and index e-books')
			->addArgument('user_id', InputArgument::OPTIONAL, 'Only scan this user');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		throw new \RuntimeException('Not implemented: W1');
	}
}
