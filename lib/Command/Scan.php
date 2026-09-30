<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Command;

use OCA\EbookReader\Service\LibraryService;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Owner: W1. occ ebookreader:scan [user_id] [--all] [--queue] */
class Scan extends Command {
	public function __construct(
		private LibraryService $library,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('ebookreader:scan')
			->setDescription('Scan library folders and index e-books')
			->addArgument('user_id', InputArgument::OPTIONAL, 'Only scan this user')
			->addOption('all', null, InputOption::VALUE_NONE, 'Scan all users')
			->addOption('queue', null, InputOption::VALUE_NONE, 'Only queue background jobs instead of indexing right away');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('user_id');
		$userIds = [];
		if (is_string($userId) && $userId !== '') {
			if (!$this->userManager->userExists($userId)) {
				$output->writeln('<error>User ' . $userId . ' does not exist</error>');
				return Command::FAILURE;
			}
			$userIds[] = $userId;
		} elseif ($input->getOption('all')) {
			$this->userManager->callForAllUsers(static function (IUser $user) use (&$userIds): void {
				$userIds[] = $user->getUID();
			});
		} else {
			$output->writeln('<error>Give a user id or --all</error>');
			return Command::INVALID;
		}

		$inline = !$input->getOption('queue');
		$total = 0;
		foreach ($userIds as $uid) {
			$output->writeln('Scanning ' . $uid . ($inline ? '' : ' (queueing jobs)'));
			$bar = new ProgressBar($output);
			$bar->setFormat(' %current% books found [%message%]');
			$bar->setMessage('');
			$bar->start();
			try {
				$n = $this->library->scanUser($uid, $inline, static function ($file, bool $stale) use ($bar, $output): void {
					$bar->setMessage($file->getName());
					$bar->advance();
					if ($stale && $output->isVerbose()) {
						$output->writeln('');
						$output->writeln('  indexing ' . $file->getName());
					}
				});
			} catch (\Throwable $e) {
				$bar->clear();
				$output->writeln('<error>' . $uid . ': ' . $e->getMessage() . '</error>');
				continue;
			}
			$bar->finish();
			$output->writeln('');
			$output->writeln($n . ($inline ? ' books indexed' : ' jobs queued'));
			$total += $n;
		}
		$output->writeln('Done: ' . $total . ($inline ? ' books indexed' : ' jobs queued'));
		return Command::SUCCESS;
	}
}
