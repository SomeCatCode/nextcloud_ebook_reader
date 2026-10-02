<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Controller;

use OCA\EbookReader\Db\Annotation;
use OCA\EbookReader\Db\AnnotationMapper;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Progress;
use OCA\EbookReader\Db\ProgressMapper;
use OCA\EbookReader\Http\AbstractOCSController;
use OCA\EbookReader\Http\BookSerializer;
use OCA\EbookReader\Http\SyncCursor;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;

/**
 * @psalm-import-type EbookReaderSyncResult from \OCA\EbookReader\ResponseDefinitions
 */
class SyncController extends AbstractOCSController {
	public const PAGE_SIZE = 500;

	public function __construct(
		IRequest $request,
		?string $userId,
		private BookMapper $bookMapper,
		private ProgressMapper $progressMapper,
		private BookSerializer $serializer,
		private AnnotationMapper $annotationMapper,
	) {
		parent::__construct($request, $userId);
	}

	/**
	 * Delta sync: books, deletions, progress and annotations (with tombstones) changed since the cursor
	 *
	 * Each list holds at most 500 entries per call. While hasMore is true the client
	 * calls again with the returned cursor.
	 *
	 * @param string $cursor Opaque cursor of the previous call, empty for a full sync
	 * @return DataResponse<Http::STATUS_OK, EbookReaderSyncResult, array{}>
	 * @throws OCSBadRequestException Invalid cursor
	 * @throws OCSForbiddenException Not logged in
	 *
	 * 200: Changes returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/sync')]
	public function sync(string $cursor = ''): DataResponse {
		$userId = $this->uid();
		try {
			$pos = SyncCursor::decode($cursor);
		} catch (\InvalidArgumentException $e) {
			throw new OCSBadRequestException($e->getMessage());
		}

		$bookRows = $this->bookMapper->findChangedSince($userId, $pos->books[0], $pos->books[1], self::PAGE_SIZE + 1);
		$progressRows = $this->progressMapper->findChangedSince($userId, $pos->progress[0], $pos->progress[1], self::PAGE_SIZE + 1);
		$annotationRows = $this->annotationMapper->findChangedSince($userId, $pos->annotations[0], $pos->annotations[1], self::PAGE_SIZE + 1);
		$moreAnnotations = count($annotationRows) > self::PAGE_SIZE;
		$annotationRows = array_slice($annotationRows, 0, self::PAGE_SIZE);
		$moreBooks = count($bookRows) > self::PAGE_SIZE;
		$moreProgress = count($progressRows) > self::PAGE_SIZE;
		$bookRows = array_slice($bookRows, 0, self::PAGE_SIZE);
		$progressRows = array_slice($progressRows, 0, self::PAGE_SIZE);

		$books = [];
		$deleted = [];
		foreach ($bookRows as $row) {
			if ($row->getDeletedAt() !== null) {
				$deleted[] = $row->getFileId();
			} else {
				$books[] = $row;
			}
		}

		$last = $bookRows === [] ? null : $bookRows[array_key_last($bookRows)];
		$lastProgress = $progressRows === [] ? null : $progressRows[array_key_last($progressRows)];
		$lastAnnotation = $annotationRows === [] ? null : $annotationRows[array_key_last($annotationRows)];
		$next = new SyncCursor(
			$last instanceof Book ? [$last->getUpdatedAt(), $last->getId()] : $pos->books,
			$lastProgress instanceof Progress ? [$lastProgress->getUpdatedAt(), $lastProgress->getId()] : $pos->progress,
			$lastAnnotation instanceof Annotation ? [$lastAnnotation->getUpdatedAt(), $lastAnnotation->getId()] : $pos->annotations,
		);

		return new DataResponse([
			'books' => $this->serializer->serializeMany($userId, $books),
			'deleted' => $deleted,
			'progress' => array_map(static fn (Progress $p): array => $p->toApi(), $progressRows),
			'annotations' => array_map(static fn (Annotation $a): array => $a->toApi(), $annotationRows),
			'cursor' => $next->encode(),
			'hasMore' => $moreBooks || $moreProgress || $moreAnnotations,
		]);
	}
}
