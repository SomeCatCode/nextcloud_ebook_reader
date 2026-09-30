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
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/** Owner: W1. Periodically re-scans the library folders of all users who use the app. */
class RescanJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private LibraryService $library,
		private IDBConnection $db,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(6 * 60 * 60);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		foreach ($this->userIds() as $userId) {
			if (!$this->userManager->userExists($userId)) {
				continue;
			}
			try {
				$this->library->scanUser($userId);
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
