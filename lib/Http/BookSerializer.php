<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Http;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\Progress;
use OCA\EbookReader\Db\ProgressMapper;
use OCA\EbookReader\Db\Tag;
use OCA\EbookReader\Db\TagMapper;
use OCA\EbookReader\Service\LibraryService;
use OCP\Files\NotFoundException;

/**
 * Turns Book entities into the API Book JSON (CONTRACTS section 5).
 *
 * @psalm-import-type EbookReaderBook from \OCA\EbookReader\ResponseDefinitions
 */
class BookSerializer {
	public function __construct(
		private LibraryService $library,
		private TagMapper $tags,
		private ProgressMapper $progress,
	) {
	}

	/**
	 * @param list<Tag>|null $tags preloaded tags (else loaded via LibraryService::getTags)
	 * @param Progress|null $progress preloaded progress; null = not read yet
	 * @param bool|null $editable null = determined from the file (isUpdateable)
	 * @return EbookReaderBook
	 */
	public function serialize(string $userId, Book $book, ?array $tags = null, ?Progress $progress = null, ?bool $editable = null): array {
		$tags ??= $this->library->getTags($book->getId());
		$genres = [];
		$plain = [];
		foreach ($tags as $tag) {
			if ($tag->getType() === Tag::TYPE_GENRE) {
				$genres[] = $tag->getName();
			} else {
				$plain[] = $tag->getName();
			}
		}
		[$fileEditable, $downloadable] = $this->fileFlags($userId, $book->getFileId());
		$editable ??= $fileEditable;
		$status = match ($book->getReadStatus()) {
			Book::STATUS_READING => Book::STATUS_READING,
			Book::STATUS_FINISHED => Book::STATUS_FINISHED,
			default => Book::STATUS_UNREAD,
		};

		return [
			'fileId' => $book->getFileId(),
			'format' => $book->getFormat(),
			'path' => $book->getPath(),
			'size' => $book->getSize(),
			'title' => $book->getTitle(),
			'authors' => $book->getAuthorsArray(),
			'series' => $book->getSeries(),
			'seriesIndex' => $book->getSeriesIndex(),
			'description' => $book->getDescription(),
			'language' => $book->getLanguage(),
			'publisher' => $book->getPublisher(),
			'isbn' => $book->getIsbn(),
			'publishedAt' => $book->getPublishedAt(),
			'genres' => $genres,
			'tags' => $plain,
			'rating' => $book->getRating(),
			'readStatus' => $status,
			'hasCover' => $book->getHasCover(),
			'coverEtag' => $book->getCoverEtag(),
			'mtime' => $book->getFileMtime(),
			'addedAt' => $book->getAddedAt(),
			'updatedAt' => $book->getUpdatedAt(),
			'editable' => $editable,
			'downloadable' => $downloadable,
			'overrides' => $book->getOverridesArray(),
			'hasSidecar' => $book->getSidecarEtag() !== null,
			'progress' => $progress?->toApi(),
		];
	}

	/**
	 * Serializes a single book incl. tags and progress.
	 * @return EbookReaderBook
	 */
	public function serializeWithProgress(string $userId, Book $book): array {
		return $this->serializeMany($userId, [$book])[0];
	}

	/**
	 * Batch variant: loads tags and progress with one query each.
	 * @param list<Book> $books
	 * @return list<EbookReaderBook>
	 */
	public function serializeMany(string $userId, array $books): array {
		if ($books === []) {
			return [];
		}
		$tags = $this->tags->findByBooks(array_map(static fn (Book $b): int => $b->getId(), $books));
		$progress = $this->progress->findByUserAndFiles($userId, array_map(static fn (Book $b): int => $b->getFileId(), $books));
		$out = [];
		foreach ($books as $book) {
			$out[] = $this->serialize($userId, $book, $tags[$book->getId()] ?? [], $progress[$book->getFileId()] ?? null);
		}
		return $out;
	}

	/**
	 * @return array{0: bool, 1: bool} editable (isUpdateable) and downloadable (false for view-only shares: the client must not fetch the content)
	 */
	private function fileFlags(string $userId, int $fileId): array {
		try {
			$file = $this->library->getFileForUser($userId, $fileId);
			return [$file->isUpdateable(), $this->library->canReadContent($file)];
		} catch (NotFoundException) {
			return [false, false];
		}
	}
}
