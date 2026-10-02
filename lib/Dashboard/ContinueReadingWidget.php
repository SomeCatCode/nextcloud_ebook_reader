<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Dashboard;

use OCA\EbookReader\AppInfo\Application;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\Progress;
use OCA\EbookReader\Db\ProgressMapper;
use OCA\EbookReader\Service\BookQuery;
use OCA\EbookReader\Service\LibraryService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * "Continue reading" dashboard widget: the books in progress, most recently read first. One query for the books (the library
 * query sorted by last read) and one for their progress rows; no file access.
 */
class ContinueReadingWidget implements IAPIWidgetV2, IIconWidget, IButtonWidget, IReloadableWidget {
	public const ID = 'ebookreader_continue_reading';
	public const RELOAD_SECONDS = 120;

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private LibraryService $library,
		private ProgressMapper $progress,
	) {
	}

	#[\Override]
	public function getId(): string {
		return self::ID;
	}

	#[\Override]
	public function getTitle(): string {
		return $this->l10n->t('Continue reading');
	}

	#[\Override]
	public function getOrder(): int {
		return 40;
	}

	#[\Override]
	public function getIconClass(): string {
		return 'icon-ebookreader';
	}

	#[\Override]
	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	#[\Override]
	public function getUrl(): ?string {
		return $this->urlGenerator->linkToRouteAbsolute('ebookreader.page.index');
	}

	#[\Override]
	public function load(): void {
	}

	#[\Override]
	public function getReloadInterval(): int {
		return self::RELOAD_SECONDS;
	}

	#[\Override]
	public function getWidgetButtons(string $userId): array {
		return [
			new WidgetButton(WidgetButton::TYPE_MORE, $this->urlGenerator->linkToRouteAbsolute('ebookreader.page.index'), $this->l10n->t('Open library')),
		];
	}

	#[\Override]
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$limit = max(1, min(20, $limit));
		$result = $this->library->findBooks($userId, new BookQuery(status: Book::STATUS_READING, sort: 'read', order: 'desc', limit: $limit));
		$books = $result['books'];
		$progress = $books === [] ? [] : $this->progress->findByUserAndFiles($userId, array_map(static fn (Book $b): int => $b->getFileId(), $books));
		return new WidgetItems($this->buildItems($books, $progress), $this->l10n->t('No books in progress'));
	}

	/**
	 * @param list<Book> $books
	 * @param array<int, Progress> $progress by file id
	 * @return list<WidgetItem>
	 */
	public function buildItems(array $books, array $progress): array {
		$items = [];
		foreach ($books as $book) {
			$fileId = $book->getFileId();
			$items[] = new WidgetItem(
				self::titleOf($book),
				self::subtitleOf($book, isset($progress[$fileId]) ? $progress[$fileId]->getPercentage() : null),
				$this->urlGenerator->linkToRouteAbsolute('ebookreader.page.read', ['fileId' => $fileId]),
				$book->getHasCover()
					? $this->urlGenerator->linkToRouteAbsolute('ebookreader.cover.show', ['fileId' => $fileId, 'size' => 'small'])
					: $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')),
				(string)$fileId,
			);
		}
		return $items;
	}

	public static function titleOf(Book $book): string {
		$title = trim((string)$book->getTitle());
		return $title !== '' ? $title : pathinfo($book->getPath(), PATHINFO_FILENAME);
	}

	/** "Author · 42 %" (just one of the parts when the other is unknown). $fraction is 0..1 */
	public static function subtitleOf(Book $book, ?float $fraction): string {
		$parts = [];
		$authors = $book->getAuthorsArray();
		if ($authors !== []) {
			$parts[] = implode(', ', array_slice($authors, 0, 2));
		}
		if ($fraction !== null) {
			$parts[] = (int)round(max(0.0, min(1.0, $fraction)) * 100.0) . ' %';
		}
		return implode(' · ', $parts);
	}
}
