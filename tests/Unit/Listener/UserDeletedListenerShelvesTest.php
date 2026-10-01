<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Listener;

use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\ProgressMapper;
use OCA\EbookReader\Db\ShelfBookMapper;
use OCA\EbookReader\Db\ShelfMapper;
use OCA\EbookReader\Db\TagMapper;
use OCA\EbookReader\Db\TaskMapper;
use OCA\EbookReader\Listener\UserDeletedListener;
use OCA\EbookReader\Service\CoverService;
use OCP\Files\IAppData;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserDeletedListenerShelvesTest extends TestCase {
	private function listener(ShelfMapper $shelves, ShelfBookMapper $shelfBooks, ?LoggerInterface $logger = null): UserDeletedListener {
		$books = $this->createMock(BookMapper::class);
		$books->method('findDistinctFileIdsByUser')->willReturn([]);
		$books->method('findAllByUser')->willReturn([]);
		return new UserDeletedListener(
			$books,
			$this->createMock(TagMapper::class),
			$this->createMock(ProgressMapper::class),
			$this->createMock(TaskMapper::class),
			$this->createMock(CoverService::class),
			$this->createMock(IAppData::class),
			$logger ?? $this->createMock(LoggerInterface::class),
			$shelves,
			$shelfBooks,
		);
	}

	private function event(string $uid): UserDeletedEvent {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return new UserDeletedEvent($user);
	}

	public function testDeletesShelvesAndAssignmentsOfTheUser(): void {
		$shelves = $this->createMock(ShelfMapper::class);
		$shelves->method('findIdsByUser')->with('gone')->willReturn([3, 4]);
		$shelves->expects($this->once())->method('deleteByUser')->with('gone');
		$shelfBooks = $this->createMock(ShelfBookMapper::class);
		$shelfBooks->expects($this->once())->method('deleteByShelves')->with([3, 4]);
		$this->listener($shelves, $shelfBooks)->handle($this->event('gone'));
	}

	public function testUserWithoutShelvesOnlyDeletesRows(): void {
		$shelves = $this->createMock(ShelfMapper::class);
		$shelves->method('findIdsByUser')->willReturn([]);
		$shelves->expects($this->once())->method('deleteByUser');
		$shelfBooks = $this->createMock(ShelfBookMapper::class);
		$shelfBooks->expects($this->never())->method('deleteByShelves');
		$this->listener($shelves, $shelfBooks)->handle($this->event('gone'));
	}

	public function testShelfFailureIsLoggedAndDoesNotStopTheCleanup(): void {
		$shelves = $this->createMock(ShelfMapper::class);
		$shelves->method('findIdsByUser')->willThrowException(new \RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$progress = $this->createMock(ProgressMapper::class);
		$progress->expects($this->once())->method('deleteByUser');
		$books = $this->createMock(BookMapper::class);
		$books->method('findDistinctFileIdsByUser')->willReturn([]);
		$books->method('findAllByUser')->willReturn([]);
		$listener = new UserDeletedListener(
			$books,
			$this->createMock(TagMapper::class),
			$progress,
			$this->createMock(TaskMapper::class),
			$this->createMock(CoverService::class),
			$this->createMock(IAppData::class),
			$logger,
			$shelves,
			$this->createMock(ShelfBookMapper::class),
		);
		$listener->handle($this->event('gone'));
	}
}
