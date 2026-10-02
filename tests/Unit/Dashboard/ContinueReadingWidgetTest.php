<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Dashboard;

use OCA\EbookReader\Dashboard\ContinueReadingWidget;
use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\Progress;
use OCA\EbookReader\Db\ProgressMapper;
use OCA\EbookReader\Service\BookQuery;
use OCA\EbookReader\Service\LibraryService;
use OCP\Dashboard\Model\WidgetButton;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ContinueReadingWidgetTest extends TestCase {
	private LibraryService&MockObject $library;
	private ProgressMapper&MockObject $progress;

	private function widget(): ContinueReadingWidget {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $r, array $p = []): string => 'https://nc/' . $r . '?' . http_build_query($p));
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $img): string => '/apps/' . $app . '/img/' . $img);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $u): string => 'https://nc' . $u);
		$this->library = $this->createMock(LibraryService::class);
		$this->progress = $this->createMock(ProgressMapper::class);
		return new ContinueReadingWidget($l10n, $urls, $this->library, $this->progress);
	}

	/** @param list<string> $authors */
	private function book(int $fileId, ?string $title, array $authors, bool $cover): Book {
		$b = new Book();
		$b->setId($fileId);
		$b->setFileId($fileId);
		$b->setPath('/Books/file-' . $fileId . '.epub');
		$b->setTitle($title);
		$b->setAuthors(json_encode($authors));
		$b->setHasCover($cover);
		return $b;
	}

	private function progress(int $fileId, float $pct): Progress {
		$p = new Progress();
		$p->setFileId($fileId);
		$p->setPercentage($pct);
		return $p;
	}

	public function testItemsAreBuiltFromBooksAndProgress(): void {
		$w = $this->widget();
		$items = $w->buildItems(
			[$this->book(7, 'Dune', ['Frank Herbert'], true), $this->book(8, null, [], false)],
			[7 => $this->progress(7, 0.424), 8 => $this->progress(8, 0.5)],
		);
		$this->assertCount(2, $items);
		$this->assertSame('Dune', $items[0]->getTitle());
		$this->assertSame('Frank Herbert · 42 %', $items[0]->getSubtitle());
		$this->assertSame('https://nc/ebookreader.page.read?fileId=7', $items[0]->getLink());
		$this->assertSame('https://nc/ebookreader.cover.show?fileId=7&size=small', $items[0]->getIconUrl());
		$this->assertSame('7', $items[0]->getSinceId());
		// no title: file name, no author: only the percentage, no cover: app icon
		$this->assertSame('file-8', $items[1]->getTitle());
		$this->assertSame('50 %', $items[1]->getSubtitle());
		$this->assertSame('https://nc/apps/ebookreader/img/app-dark.svg', $items[1]->getIconUrl());
	}

	public function testSubtitleHandlesMissingAndOutOfRangeValues(): void {
		$this->assertSame('', ContinueReadingWidget::subtitleOf($this->book(1, 'T', [], false), null));
		$this->assertSame('A, B · 100 %', ContinueReadingWidget::subtitleOf($this->book(1, 'T', ['A', 'B', 'C'], false), 1.7));
		$this->assertSame('0 %', ContinueReadingWidget::subtitleOf($this->book(1, 'T', [], false), -3.0));
	}

	public function testGetItemsQueriesBooksInProgressMostRecentFirst(): void {
		$w = $this->widget();
		$book = $this->book(3, 'Emma', ['Jane Austen'], false);
		$this->library->expects($this->once())->method('findBooks')
			->with('alice', $this->callback(static fn (BookQuery $q): bool => $q->status === Book::STATUS_READING && $q->sort === 'read' && $q->order === 'desc' && $q->limit === 5))
			->willReturn(['books' => [$book], 'total' => 1]);
		$this->progress->expects($this->once())->method('findByUserAndFiles')->with('alice', [3])->willReturn([3 => $this->progress(3, 0.1)]);

		$result = $w->getItemsV2('alice', null, 5);
		$this->assertCount(1, $result->getItems());
		$this->assertSame('Jane Austen · 10 %', $result->getItems()[0]->getSubtitle());
		$this->assertSame('No books in progress', $result->getEmptyContentMessage());
	}

	public function testEmptyStateSkipsProgressQuery(): void {
		$w = $this->widget();
		$this->library->method('findBooks')->willReturn(['books' => [], 'total' => 0]);
		$this->progress->expects($this->never())->method('findByUserAndFiles');
		$result = $w->getItemsV2('alice');
		$this->assertSame([], $result->getItems());
		$this->assertSame('No books in progress', $result->getEmptyContentMessage());
	}

	public function testButtonOpensLibrary(): void {
		$buttons = $this->widget()->getWidgetButtons('alice');
		$this->assertCount(1, $buttons);
		$this->assertSame(WidgetButton::TYPE_MORE, $buttons[0]->getType());
		$this->assertSame('https://nc/ebookreader.page.index?', $buttons[0]->getLink());
		$this->assertSame('Open library', $buttons[0]->getText());
	}
}
