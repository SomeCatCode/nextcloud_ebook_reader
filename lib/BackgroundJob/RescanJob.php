<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\BackgroundJob;

use OCA\EbookReader\AppInfo\Application;
use OCA\EbookReader\Service\LibraryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/** Owner: W1. Periodically re-scans the library folders of all users who use the app. */
class RescanJob extends TimedJob {
	/** Users scanned per run; the rest follows in the next runs (cursor in app config) */
	public const USERS_PER_RUN = 50;
	public const CURSOR_KEY = 'rescan_cursor';

	public function __construct(
		ITimeFactory $time,
		private LibraryService $library,
		private IDBConnection $db,
		private IUserManager $userManager,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(6 * 60 * 60);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		$cursor = $this->appConfig->getValueString(Application::APP_ID, self::CURSOR_KEY, '');
		$ids = $this->userIds();
		sort($ids, SORT_STRING);
		$batch = array_values(array_filter($ids, static fn (string $id): bool => $cursor === '' || strcmp($id, $cursor) > 0));
		$wrapped = count($batch) <= self::USERS_PER_RUN;
		$batch = array_slice($batch, 0, self::USERS_PER_RUN);
		// Store the cursor first: a crash must not make the next run start at the same users again.
		$this->appConfig->setValueString(Application::APP_ID, self::CURSOR_KEY, $wrapped || $batch === [] ? '' : $batch[array_key_last($batch)]);
		foreach ($batch as $userId) {
			if (!$this->userManager->userExists($userId)) {
				continue;
			}
			try {
				// inline budget 0: only queues ScanFileJobs, never indexes in this job
				$this->library->scanUser($userId, false);
			} catch (\Throwable $e) {
				$this->logger->warning('Rescan failed for user ' . $userId . ': ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
			}
		}
	}

	/** @return list<string> users with books or stored settings */
	private function userIds(): array {
		$ids = [];
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')->from('ebookreader_books');
		$res = $qb->executeQuery();
		while (($id = $res->fetchOne()) !== false) {
			$ids[(string)$id] = true;
		}
		$res->closeCursor();

		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('userid')->from('preferences')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('settings')));
		$res = $qb->executeQuery();
		while (($id = $res->fetchOne()) !== false) {
			$ids[(string)$id] = true;
		}
		$res->closeCursor();
		return array_map('strval', array_keys($ids));
	}
}
