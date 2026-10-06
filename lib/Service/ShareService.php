<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\BackgroundJob\ScanFileJob;
use OCA\EbookReader\BackgroundJob\SyncShelfShareJob;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\FileShare;
use OCA\EbookReader\Db\FileShareMapper;
use OCA\EbookReader\Db\Shelf;
use OCA\EbookReader\Db\ShelfBookMapper;
use OCA\EbookReader\Db\ShelfMapper;
use OCA\EbookReader\Db\ShelfShare;
use OCA\EbookReader\Db\ShelfShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Constants;
use OCP\DB\Exception as DbException;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IUserManager;
use OCP\Share\Exceptions\AlreadySharedException;
use OCP\Share\Exceptions\GenericShareException;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Sharing books and shelves with other users of the instance.
 *
 * Every shared book is a real, read-only Nextcloud user share of the book file, created through the share manager, so all
 * admin sharing policies (sharing disabled, excluded groups, "share only with group members", resharing, the file's own
 * share permission) apply. The app remembers why it shares a file (FileShare rows: directly or through a shelf share) and
 * deletes a Nextcloud share only when it created it itself and no reason is left. Shares the user made in Files are never
 * deleted.
 *
 * Shared shelves are live: the recipient sees the owner's shelf read-only; the files on it are shared and unshared as the
 * shelf changes (manual shelves right away, smart shelves on every sync: shelf/query edits, SyncSharedShelvesJob every
 * 15 minutes). Recipients keep their own book rows (own progress, rating, status, annotations); they never see the
 * owner's.
 *
 * @psalm-import-type EbookReaderShare from \OCA\EbookReader\ResponseDefinitions
 */
class ShareService {
	/** Max. number of books shared through one shelf share (the rest is skipped) */
	public const MAX_SHELF_BOOKS = 1000;
	/** Max. number of recipients of one shelf */
	public const MAX_RECIPIENTS = 50;
	/** New file shares created inline in a request; more are created by a queued SyncShelfShareJob */
	public const INLINE_NEW_SHARES = 100;
	/** Incoming books indexed inline per request when they are missing in the recipient's library */
	public const INLINE_INDEX = 25;

	public function __construct(
		private IManager $shareManager,
		private IUserManager $users,
		private LibraryService $library,
		private BookMapper $books,
		private ShelfMapper $shelves,
		private ShelfBookMapper $shelfBooks,
		private ShelfShareMapper $shelfShares,
		private FileShareMapper $fileShares,
		private IJobList $jobList,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	private function nowMs(): int {
		return (int)$this->time->now()->format('Uv');
	}

	// ---- books ----------------------------------------------------------------------------------------------------

	/**
	 * Shares a book of the owner's library with a user (read-only). Sharing it again is a no-op.
	 * @return EbookReaderShare
	 * @throws ShareException
	 */
	public function shareBook(string $owner, int $fileId, string $recipient): array {
		$this->assertCanShare($owner);
		$recipient = $this->validateRecipient($owner, $recipient);
		$book = $this->ownBook($owner, $fileId);
		$file = $this->ownerFile($owner, $fileId);
		$this->assertShareable($file);
		$row = $this->findDirect($owner, $recipient, $fileId);
		if ($row === null) {
			$shareId = $this->ensureFileShare($owner, $recipient, $file);
			$row = $this->insertRow($owner, $recipient, $fileId, FileShare::DIRECT, $shareId);
			$this->queueIndex($recipient, [$fileId]);
		}
		return $this->bookShareToApi($row, $book->getTitle());
	}

	/**
	 * Owner removes the direct share of a book with a user (a shelf share that still needs the file keeps it shared).
	 * @throws ShareException
	 */
	public function unshareBook(string $owner, int $fileId, string $recipient): void {
		$row = $this->findDirect($owner, trim($recipient), $fileId);
		if ($row === null) {
			throw new ShareException('Share not found', ShareException::NOT_FOUND);
		}
		$this->removeRows([$row]);
	}

	/**
	 * Recipient removes a book shared with them directly (from $sharedBy, or from everybody).
	 * @return int number of removed shares
	 * @throws ShareException
	 */
	public function leaveBook(string $recipient, int $fileId, ?string $sharedBy = null): int {
		$rows = array_values(array_filter(
			$this->fileShares->findDirectByRecipient($recipient),
			static fn (FileShare $r): bool => $r->getFileId() === $fileId && ($sharedBy === null || $sharedBy === '' || $r->getOwnerId() === $sharedBy),
		));
		if ($rows === []) {
			throw new ShareException('Share not found', ShareException::NOT_FOUND);
		}
		$this->removeRows($rows);
		return count($rows);
	}

	// ---- shelves --------------------------------------------------------------------------------------------------

	/**
	 * Shares a shelf live with a user and shares its current books.
	 * @return array{share: EbookReaderShare, skipped: int} skipped = books that could not be shared (no share permission, limit)
	 * @throws ShareException
	 */
	public function shareShelf(string $owner, int $shelfId, string $recipient): array {
		$shelf = $this->ownShelf($owner, $shelfId);
		$this->assertCanShare($owner);
		$recipient = $this->validateRecipient($owner, $recipient);
		try {
			$share = $this->shelfShares->findByShelfAndRecipient($shelfId, $recipient);
		} catch (DoesNotExistException) {
			if (count($this->shelfShares->findByShelf($shelfId)) >= self::MAX_RECIPIENTS) {
				throw new ShareException('A shelf can be shared with at most ' . self::MAX_RECIPIENTS . ' users', ShareException::INVALID);
			}
			$share = new ShelfShare();
			$share->setShelfId($shelfId);
			$share->setOwnerId($owner);
			$share->setRecipientId($recipient);
			$share->setCreatedAt($this->nowMs());
			$share->setSyncedAt(0);
			try {
				$share = $this->shelfShares->insert($share);
			} catch (DbException $e) {
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				$share = $this->shelfShares->findByShelfAndRecipient($shelfId, $recipient);
			}
		}
		$result = $this->syncShelfShare($share, $shelf, self::INLINE_NEW_SHARES);
		return ['share' => $this->shelfShareToApi($share, $shelf, null), 'skipped' => $result['skipped']];
	}

	/**
	 * Owner stops sharing a shelf with a user; the files shared for it are unshared unless another app share needs them.
	 * @throws ShareException
	 */
	public function unshareShelf(string $owner, int $shelfId, string $recipient): void {
		$this->ownShelf($owner, $shelfId);
		try {
			$share = $this->shelfShares->findByShelfAndRecipient($shelfId, trim($recipient));
		} catch (DoesNotExistException) {
			throw new ShareException('Share not found', ShareException::NOT_FOUND);
		}
		$this->removeShelfShare($share);
	}

	/**
	 * Recipient removes a shelf shared with them.
	 * @throws ShareException
	 */
	public function leaveShelf(string $recipient, int $shelfId): void {
		try {
			$share = $this->shelfShares->findByShelfAndRecipient($shelfId, $recipient);
		} catch (DoesNotExistException) {
			throw new ShareException('Share not found', ShareException::NOT_FOUND);
		}
		$this->removeShelfShare($share);
	}

	/** Removes all shares of a shelf (the shelf is being deleted). */
	public function removeShelf(int $shelfId): void {
		foreach ($this->shelfShares->findByShelf($shelfId) as $share) {
			$this->removeShelfShare($share);
		}
	}

	/** Brings the file shares of every share of a shelf in line with its current books (after a change of the shelf). */
	public function syncShelf(int $shelfId): void {
		foreach ($this->shelfShares->findByShelf($shelfId) as $share) {
			try {
				$this->syncShelfShare($share, null, self::INLINE_NEW_SHARES);
			} catch (\Throwable $e) {
				$this->logger->warning('Shelf share sync failed for share ' . $share->getId() . ': ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
			}
		}
	}

	/**
	 * Shares the books that are on the shelf now and unshares those that left it.
	 * @param ?Shelf $shelf the shelf if already loaded
	 * @param ?int $maxNew create at most this many new file shares now, queue a SyncShelfShareJob for the rest (null = all)
	 * @return array{added: int, removed: int, skipped: int}
	 */
	public function syncShelfShare(ShelfShare $share, ?Shelf $shelf = null, ?int $maxNew = null): array {
		$owner = $share->getOwnerId();
		$recipient = $share->getRecipientId();
		if ($shelf === null) {
			try {
				$shelf = $this->shelves->findByUserAndId($owner, $share->getShelfId());
			} catch (DoesNotExistException) {
				$this->removeShelfShare($share);
				return ['added' => 0, 'removed' => 0, 'skipped' => 0];
			}
		}
		if (!$this->users->userExists($recipient) || !$this->users->userExists($owner)) {
			$this->removeShelfShare($share);
			return ['added' => 0, 'removed' => 0, 'skipped' => 0];
		}

		[$desired, $overLimit] = $this->desiredFileIds($owner, $shelf);
		$current = [];
		foreach ($this->fileShares->findByShelfShare($share->getId()) as $row) {
			$current[$row->getFileId()] = $row;
		}
		$wanted = array_flip($desired);
		$gone = array_values(array_filter($current, static fn (FileShare $r): bool => !isset($wanted[$r->getFileId()])));
		$this->removeRows($gone);

		$allowed = $this->shareManager->shareApiEnabled() && !$this->shareManager->sharingDisabledForUser($owner);
		$added = 0;
		$skipped = $overLimit;
		$new = [];
		$pending = false;
		foreach ($desired as $fileId) {
			if (isset($current[$fileId])) {
				continue;
			}
			if (!$allowed) {
				$skipped++;
				continue;
			}
			if ($maxNew !== null && $added >= $maxNew) {
				$pending = true;
				break;
			}
			try {
				$file = $this->ownerFile($owner, $fileId);
				$this->assertShareable($file);
				$shareId = $this->ensureFileShare($owner, $recipient, $file);
				$this->insertRow($owner, $recipient, $fileId, $share->getId(), $shareId);
				$new[] = $fileId;
				$added++;
			} catch (ShareException $e) {
				$this->logger->info('Book ' . $fileId . ' of shelf ' . $shelf->getId() . ' not shared: ' . $e->getMessage(), ['app' => 'ebookreader']);
				$skipped++;
			}
		}
		$this->queueIndex($recipient, $new);
		if ($pending) {
			$this->jobList->add(SyncShelfShareJob::class, ['shelfShareId' => $share->getId()]);
		}
		$share->setSyncedAt($this->nowMs());
		$this->shelfShares->update($share);
		return ['added' => $added, 'removed' => count($gone), 'skipped' => $skipped];
	}

	/**
	 * Syncs all shelf shares (background job); returns the number of shares processed.
	 */
	public function syncAll(int $pageSize = 200): int {
		$afterId = 0;
		$count = 0;
		do {
			$page = $this->shelfShares->findPage($afterId, $pageSize);
			foreach ($page as $share) {
				$afterId = $share->getId();
				try {
					$this->syncShelfShare($share);
				} catch (\Throwable $e) {
					$this->logger->warning('Shelf share sync failed for share ' . $share->getId() . ': ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
				}
				$count++;
			}
		} while (count($page) >= $pageSize);
		return $count;
	}

	public function syncById(int $shelfShareId): void {
		try {
			$share = $this->shelfShares->findById($shelfShareId);
		} catch (DoesNotExistException) {
			return;
		}
		$this->syncShelfShare($share);
	}

	/**
	 * File ids that a shelf share should share now: the owner's (non-deleted) books on a manual shelf in shelf order, or the
	 * current hits of a smart shelf; at most MAX_SHELF_BOOKS.
	 * @return array{0: list<int>, 1: int} file ids and the number of books left out because of the limit
	 */
	public function desiredFileIds(string $owner, Shelf $shelf): array {
		if (!$shelf->isSmart()) {
			$ids = $this->shelfBooks->findFileIds($shelf->getId());
			$own = [];
			foreach ($this->books->findByUserAndFiles($owner, $ids) as $book) {
				$own[$book->getFileId()] = true;
			}
			$ids = array_values(array_filter($ids, static fn (int $id): bool => isset($own[$id])));
			return [array_slice($ids, 0, self::MAX_SHELF_BOOKS), max(0, count($ids) - self::MAX_SHELF_BOOKS)];
		}
		$ids = [];
		$total = 0;
		$offset = 0;
		do {
			$res = $this->library->findBooks($owner, ShelfService::toBookQuery($shelf->getQueryArray() ?? [], BookQuery::MAX_LIMIT, false, $offset));
			$total = $res['total'];
			foreach ($res['books'] as $book) {
				$ids[] = $book->getFileId();
			}
			$offset += BookQuery::MAX_LIMIT;
		} while (count($res['books']) >= BookQuery::MAX_LIMIT && count($ids) < self::MAX_SHELF_BOOKS);
		$ids = array_values(array_unique($ids));
		return [array_slice($ids, 0, self::MAX_SHELF_BOOKS), max(0, $total - self::MAX_SHELF_BOOKS)];
	}

	// ---- recipient side -------------------------------------------------------------------------------------------

	/**
	 * Shelves shared with a user, with the owner's shelf.
	 * @return list<array{share: ShelfShare, shelf: Shelf}>
	 */
	public function incomingShelves(string $recipient): array {
		$out = [];
		foreach ($this->shelfShares->findByRecipient($recipient) as $share) {
			try {
				$out[] = ['share' => $share, 'shelf' => $this->shelves->findByUserAndId($share->getOwnerId(), $share->getShelfId())];
			} catch (DoesNotExistException) {
				// shelf vanished without cleanup: drop the share
				$this->removeShelfShare($share);
			}
		}
		return $out;
	}

	public function isIncomingShelf(string $recipient, int $shelfId): bool {
		try {
			$this->shelfShares->findByShelfAndRecipient($shelfId, $recipient);
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}

	/**
	 * Indexes books shared with the user that are not in their library yet (the background job may not have run).
	 * @return int number of books indexed
	 */
	public function ensureIncomingIndexed(string $recipient, int $max = self::INLINE_INDEX, float $budgetSeconds = 5.0): int {
		$ids = $this->fileShares->findIncomingFileIds($recipient);
		if ($ids === []) {
			return 0;
		}
		$have = [];
		foreach ($this->books->findByUserAndFiles($recipient, $ids) as $book) {
			$have[$book->getFileId()] = true;
		}
		$deadline = microtime(true) + $budgetSeconds;
		$indexed = 0;
		foreach ($ids as $fileId) {
			if (isset($have[$fileId])) {
				continue;
			}
			if ($indexed >= $max || microtime(true) > $deadline) {
				break;
			}
			try {
				$file = $this->library->getFileForUser($recipient, $fileId);
				if ($file->getSize() > LibraryService::INTERACTIVE_MAX_BYTES) {
					$this->jobList->add(ScanFileJob::class, ['userId' => $recipient, 'fileId' => $fileId]);
					continue;
				}
				if ($this->library->indexFile($recipient, $file) !== null) {
					$indexed++;
				}
			} catch (NotFoundException) {
				// share not accepted yet or gone
			} catch (\Throwable $e) {
				$this->logger->info('Indexing shared book ' . $fileId . ' failed: ' . $e->getMessage(), ['app' => 'ebookreader']);
			}
		}
		return $indexed;
	}

	// ---- overview -------------------------------------------------------------------------------------------------

	/**
	 * What the user shares with whom and what is shared with them.
	 * @return array{outgoing: list<EbookReaderShare>, incoming: list<EbookReaderShare>}
	 */
	public function overview(string $userId): array {
		$outgoing = [];
		$direct = $this->fileShares->findDirectByOwner($userId);
		$titles = $this->titles($userId, array_map(static fn (FileShare $r): int => $r->getFileId(), $direct));
		$stale = [];
		foreach ($direct as $row) {
			if (!array_key_exists($row->getFileId(), $titles)) {
				// the book left the owner's library: the share goes as well
				$stale[] = $row;
				continue;
			}
			$outgoing[] = $this->bookShareToApi($row, $titles[$row->getFileId()]);
		}
		if ($stale !== []) {
			$this->removeRows($stale);
		}
		$ownShares = $this->shelfShares->findByOwner($userId);
		$counts = $this->fileShares->countsByShelfShares(array_map(static fn (ShelfShare $s): int => $s->getId(), $ownShares));
		$shelves = [];
		foreach ($this->shelves->findByUser($userId) as $shelf) {
			$shelves[$shelf->getId()] = $shelf;
		}
		foreach ($ownShares as $share) {
			$shelf = $shelves[$share->getShelfId()] ?? null;
			if ($shelf !== null) {
				$outgoing[] = $this->shelfShareToApi($share, $shelf, $counts[$share->getId()] ?? 0);
			}
		}

		$incoming = [];
		$direct = $this->fileShares->findDirectByRecipient($userId);
		$titles = $this->titles($userId, array_map(static fn (FileShare $r): int => $r->getFileId(), $direct));
		foreach ($direct as $row) {
			$title = $titles[$row->getFileId()] ?? $this->ownerTitle($row->getOwnerId(), $row->getFileId());
			$incoming[] = $this->bookShareToApi($row, $title);
		}
		$in = $this->incomingShelves($userId);
		$counts = $this->fileShares->countsByShelfShares(array_map(static fn (array $e): int => $e['share']->getId(), $in));
		foreach ($in as $entry) {
			$incoming[] = $this->shelfShareToApi($entry['share'], $entry['shelf'], $counts[$entry['share']->getId()] ?? 0);
		}
		return ['outgoing' => $outgoing, 'incoming' => $incoming];
	}

	/**
	 * Number of recipients per shelf of an owner.
	 * @return array<int, int>
	 */
	public function shelfShareCounts(string $owner): array {
		return $this->shelfShares->countsByOwner($owner);
	}

	public function displayName(string $userId): string {
		$name = $this->users->getDisplayName($userId);
		return $name !== null && $name !== '' ? $name : $userId;
	}

	// ---- Nextcloud share events -----------------------------------------------------------------------------------

	/**
	 * A Nextcloud share was deleted (in Files, by the recipient leaving it, ...). Direct book shares based on it are gone;
	 * shelf reasons stay as "handled" without a share, so the sync does not create it again against the user's decision.
	 */
	public function onShareDeleted(IShare $share): void {
		$id = self::fullId($share);
		if ($id === null) {
			return;
		}
		$rows = $this->fileShares->findByShareId($id);
		if ($rows === []) {
			return;
		}
		$recipients = [];
		foreach ($rows as $row) {
			$recipients[$row->getRecipientId()][] = $row->getFileId();
			if ($row->isDirect()) {
				$this->fileShares->delete($row);
			}
		}
		$this->fileShares->detachShare($id);
		foreach ($recipients as $recipient => $fileIds) {
			$this->queueIndex((string)$recipient, $fileIds);
		}
	}

	/** The recipient accepted a pending share (share acceptance enabled): index the book now. */
	public function onShareAccepted(IShare $share): void {
		$id = self::fullId($share);
		if ($id === null) {
			return;
		}
		foreach ($this->fileShares->findByShareId($id) as $row) {
			$this->queueIndex($row->getRecipientId(), [$row->getFileId()]);
			return;
		}
	}

	/** Removes the share records of a deleted user (Nextcloud deletes the user's shares itself). */
	public function deleteAllForUser(string $userId): void {
		foreach ($this->fileShares->findByUser($userId) as $row) {
			$this->fileShares->delete($row);
		}
		foreach ([...$this->shelfShares->findByOwner($userId), ...$this->shelfShares->findByRecipient($userId)] as $share) {
			$this->shelfShares->delete($share);
		}
	}

	// ---- internals ------------------------------------------------------------------------------------------------

	private function removeShelfShare(ShelfShare $share): void {
		$this->removeRows($this->fileShares->findByShelfShare($share->getId()));
		$this->fileShares->deleteByShelfShare($share->getId());
		try {
			$this->shelfShares->delete($share);
		} catch (\Throwable $e) {
			$this->logger->debug('Shelf share already gone: ' . $e->getMessage(), ['app' => 'ebookreader']);
		}
	}

	/**
	 * Deletes the reasons; a Nextcloud share created by the app is deleted when no reason refers to it any more.
	 * The recipients' libraries are updated by ScanFileJobs (the book is tombstoned when the file is no longer reachable).
	 * @param list<FileShare> $rows
	 */
	private function removeRows(array $rows): void {
		if ($rows === []) {
			return;
		}
		$shareIds = [];
		$byRecipient = [];
		foreach ($rows as $row) {
			$this->fileShares->delete($row);
			if ($row->getShareId() !== null) {
				$shareIds[$row->getShareId()] = true;
			}
			$byRecipient[$row->getRecipientId()][] = $row->getFileId();
		}
		foreach (array_keys($shareIds) as $shareId) {
			if ($this->fileShares->findByShareId((string)$shareId) === []) {
				$this->deleteNextcloudShare((string)$shareId);
			}
		}
		foreach ($byRecipient as $recipient => $fileIds) {
			$this->queueIndex((string)$recipient, $fileIds);
		}
	}

	private function deleteNextcloudShare(string $shareId): void {
		try {
			$this->shareManager->deleteShare($this->shareManager->getShareById($shareId));
		} catch (ShareNotFound) {
			// already gone
		} catch (\Throwable $e) {
			$this->logger->warning('Could not delete share ' . $shareId . ': ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
		}
	}

	/**
	 * Makes sure the recipient can read the file: reuses the share the app created for another reason, accepts an existing
	 * share of the owner (returns null: not ours, never deleted) or creates a read-only user share.
	 * @return ?string full id of the app's share, null if the user's own share is used
	 * @throws ShareException the share manager refused (policy, permissions)
	 */
	private function ensureFileShare(string $owner, string $recipient, File $file): ?string {
		foreach ($this->fileShares->findByTriple($owner, $recipient, $file->getId()) as $row) {
			$id = $row->getShareId();
			if ($id === null) {
				continue;
			}
			try {
				$this->shareManager->getShareById($id);
				return $id;
			} catch (ShareNotFound) {
				$this->fileShares->detachShare($id);
			}
		}
		try {
			foreach ($this->shareManager->getSharesBy($owner, IShare::TYPE_USER, $file, false, -1) as $existing) {
				if ($existing->getSharedWith() === $recipient) {
					return null;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Listing existing shares failed: ' . $e->getMessage(), ['app' => 'ebookreader']);
		}

		$share = $this->shareManager->newShare();
		$share->setNode($file);
		$share->setShareType(IShare::TYPE_USER);
		$share->setSharedWith($recipient);
		$share->setSharedBy($owner);
		$share->setPermissions(Constants::PERMISSION_READ);
		$share->setMailSend(false);
		try {
			$created = $this->shareManager->createShare($share);
		} catch (AlreadySharedException) {
			return null;
		} catch (GenericShareException $e) {
			$hint = $e->getHint();
			throw new ShareException($hint !== '' ? $hint : $e->getMessage(), ShareException::FORBIDDEN);
		} catch (\Exception $e) {
			throw new ShareException($e->getMessage() !== '' ? $e->getMessage() : 'Sharing is not allowed', ShareException::FORBIDDEN);
		}
		return self::fullId($created);
	}

	private static function fullId(IShare $share): ?string {
		try {
			$id = $share->getFullId();
		} catch (\Throwable) {
			return null;
		}
		return $id !== '' ? $id : null;
	}

	private function insertRow(string $owner, string $recipient, int $fileId, int $shelfShareId, ?string $shareId): FileShare {
		$row = new FileShare();
		$row->setOwnerId($owner);
		$row->setRecipientId($recipient);
		$row->setFileId($fileId);
		$row->setShelfShareId($shelfShareId);
		$row->setShareId($shareId);
		$row->setCreatedAt($this->nowMs());
		try {
			return $this->fileShares->insert($row);
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			foreach ($this->fileShares->findByTriple($owner, $recipient, $fileId) as $other) {
				if ($other->getShelfShareId() === $shelfShareId) {
					return $other;
				}
			}
			throw $e;
		}
	}

	private function findDirect(string $owner, string $recipient, int $fileId): ?FileShare {
		foreach ($this->fileShares->findByTriple($owner, $recipient, $fileId) as $row) {
			if ($row->isDirect()) {
				return $row;
			}
		}
		return null;
	}

	/** @param list<int> $fileIds */
	private function queueIndex(string $recipient, array $fileIds): void {
		foreach (array_unique($fileIds) as $fileId) {
			$this->jobList->add(ScanFileJob::class, ['userId' => $recipient, 'fileId' => $fileId]);
		}
	}

	/** @throws ShareException */
	private function assertCanShare(string $owner): void {
		if (!$this->shareManager->shareApiEnabled()) {
			throw new ShareException('Sharing is disabled on this server', ShareException::FORBIDDEN);
		}
		if ($this->shareManager->sharingDisabledForUser($owner)) {
			throw new ShareException('You are not allowed to share', ShareException::FORBIDDEN);
		}
	}

	/** @throws ShareException */
	private function validateRecipient(string $owner, string $recipient): string {
		$recipient = trim($recipient);
		if ($recipient === '') {
			throw new ShareException('shareWith is required', ShareException::INVALID);
		}
		if ($recipient === $owner) {
			throw new ShareException('You cannot share with yourself', ShareException::INVALID);
		}
		if (!$this->users->userExists($recipient)) {
			throw new ShareException('Unknown user', ShareException::INVALID);
		}
		return $recipient;
	}

	/** @throws ShareException */
	private function ownBook(string $owner, int $fileId): Book {
		try {
			return $this->books->findByUserAndFile($owner, $fileId);
		} catch (DoesNotExistException) {
			throw new ShareException('Book not found', ShareException::NOT_FOUND);
		}
	}

	/** @throws ShareException */
	private function ownShelf(string $owner, int $shelfId): Shelf {
		try {
			return $this->shelves->findByUserAndId($owner, $shelfId);
		} catch (DoesNotExistException) {
			throw new ShareException('Shelf not found', ShareException::NOT_FOUND);
		}
	}

	/** @throws ShareException */
	private function ownerFile(string $owner, int $fileId): File {
		try {
			return $this->library->getFileForUser($owner, $fileId);
		} catch (NotFoundException) {
			throw new ShareException('Book not found', ShareException::NOT_FOUND);
		}
	}

	/** @throws ShareException */
	private function assertShareable(File $file): void {
		if (!$this->library->canReadContent($file)) {
			throw new ShareException('Books from view-only shares cannot be shared', ShareException::FORBIDDEN);
		}
		if (!$file->isShareable() || ($file->getPermissions() & Constants::PERMISSION_SHARE) === 0) {
			throw new ShareException('You are not allowed to share this book (no share permission, resharing may be disabled)', ShareException::FORBIDDEN);
		}
	}

	/**
	 * Titles of the user's (non-deleted) books; books without a title map to their file name.
	 * @param list<int> $fileIds
	 * @return array<int, string>
	 */
	private function titles(string $userId, array $fileIds): array {
		$out = [];
		foreach ($this->books->findByUserAndFiles($userId, array_values(array_unique($fileIds))) as $book) {
			$out[$book->getFileId()] = self::titleOf($book);
		}
		return $out;
	}

	private function ownerTitle(string $owner, int $fileId): string {
		try {
			return self::titleOf($this->books->findByUserAndFile($owner, $fileId));
		} catch (DoesNotExistException) {
			return '#' . $fileId;
		}
	}

	private static function titleOf(Book $book): string {
		$title = $book->getTitle();
		if ($title !== null && trim($title) !== '') {
			return $title;
		}
		return basename($book->getPath());
	}

	/** @return EbookReaderShare */
	private function bookShareToApi(FileShare $row, ?string $title): array {
		return [
			'type' => 'book',
			'fileId' => $row->getFileId(),
			'shelfId' => null,
			'name' => $title ?? ('#' . $row->getFileId()),
			'owner' => $row->getOwnerId(),
			'ownerDisplayName' => $this->displayName($row->getOwnerId()),
			'recipient' => $row->getRecipientId(),
			'recipientDisplayName' => $this->displayName($row->getRecipientId()),
			'createdAt' => $row->getCreatedAt(),
			'bookCount' => 1,
		];
	}

	/**
	 * @param ?int $count shared books (null = counted now)
	 * @return EbookReaderShare
	 */
	private function shelfShareToApi(ShelfShare $share, Shelf $shelf, ?int $count): array {
		$count ??= $this->fileShares->countsByShelfShares([$share->getId()])[$share->getId()] ?? 0;
		return [
			'type' => 'shelf',
			'fileId' => null,
			'shelfId' => $share->getShelfId(),
			'name' => $shelf->getName(),
			'owner' => $share->getOwnerId(),
			'ownerDisplayName' => $this->displayName($share->getOwnerId()),
			'recipient' => $share->getRecipientId(),
			'recipientDisplayName' => $this->displayName($share->getRecipientId()),
			'createdAt' => $share->getCreatedAt(),
			'bookCount' => $count,
		];
	}
}
