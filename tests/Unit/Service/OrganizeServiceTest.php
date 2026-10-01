<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\TagMapper;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\OrganizeException;
use OCA\EbookReader\Service\OrganizeService;
use OCA\EbookReader\Service\RenameService;
use OCA\EbookReader\Service\SettingsService;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrganizeServiceTest extends TestCase {
	private OrganizeService $service;
	private LibraryService&MockObject $library;
	private SettingsService&MockObject $settings;
	private IRootFolder&MockObject $rootFolder;

	protected function setUp(): void {
		$validator = $this->createMock(IFilenameValidator::class);
		$validator->method('sanitizeFilename')->willReturnArgument(0);
		$this->library = $this->createMock(LibraryService::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('get')->willReturn(['libraryFolders' => ['/Books'], 'reader' => [], 'filenamePattern' => '', 'genreList' => null]);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->service = new OrganizeService(
			$this->createMock(BookMapper::class),
			$this->createMock(TagMapper::class),
			$this->library,
			new RenameService(),
			$this->settings,
			$this->rootFolder,
			$validator,
			$this->createMock(LoggerInterface::class),
		);
	}

	/** @return array<string, string> */
	private function vars(array $over = []): array {
		return $over + [
			'author' => 'Erika Muster', 'authors' => 'Erika Muster, Max Mustermann', 'title' => 'Der Titel',
			'series' => 'Saga', 'series_index' => '2', 'year' => '2020', 'publisher' => 'Verlag',
			'language' => 'de', 'genre' => 'Fantasy', 'format' => 'epub',
		];
	}

	public function testFoldersAndPaddedIndex(): void {
		$this->assertSame(
			'Erika Muster/Saga/02 - Der Titel.epub',
			$this->service->renderPath('{author}/{series}/{series_index:2} - {title}', $this->vars(), '.epub'),
		);
	}

	public function testPaddingKeepsFractionAndWiderNumbers(): void {
		$p = '{series_index:2} {title}';
		$this->assertSame('01.5 Der Titel.epub', $this->service->renderPath($p, $this->vars(['series_index' => '1.5']), '.epub'));
		$this->assertSame('123 Der Titel.epub', $this->service->renderPath($p, $this->vars(['series_index' => '123']), '.epub'));
		$this->assertSame('2 Der Titel.epub', $this->service->renderPath('{series_index} {title}', $this->vars(), '.epub'));
	}

	public function testEmptySeriesDropsFolderLevelAndSeparator(): void {
		$this->assertSame(
			'Erika Muster/Der Titel.epub',
			$this->service->renderPath('{author}/{series}/{series_index:2} - {title}', $this->vars(['series' => '', 'series_index' => '']), '.epub'),
		);
	}

	public function testEmptyPlaceholderRemovesNeighbouringSeparators(): void {
		$v = $this->vars(['series' => '']);
		$this->assertSame('Erika Muster - Der Titel.epub', $this->service->renderPath('{author} - {series} - {title}', $v, '.epub'));
		$this->assertSame('Der Titel.epub', $this->service->renderPath('{series} - {title}', $v, '.epub'));
		$this->assertSame('Der Titel.epub', $this->service->renderPath('{series}, {title}', $v, '.epub'));
		$this->assertSame('Erika Muster_Der Titel.epub', $this->service->renderPath('{author}_{series}_{title}', $v, '.epub'));
		$this->assertSame('Der Titel.epub', $this->service->renderPath('{title} ({year})', $this->vars(['year' => '']), '.epub'));
		$this->assertSame('Der Titel (2020).epub', $this->service->renderPath('{title} ({year})', $this->vars(), '.epub'));
	}

	public function testEnDashSeparator(): void {
		$this->assertSame(
			"Erika Muster \u{2013} Der Titel.epub",
			$this->service->renderPath("{author} \u{2013} {series} \u{2013} {title}", $this->vars(['series' => '']), '.epub'),
		);
	}

	public function testGermanUmlautsSurvive(): void {
		$v = $this->vars(['author' => 'Jürgen Müller-Größe', 'title' => 'Äpfel & Öl: Süße Übung']);
		$this->assertSame(
			'Jürgen Müller-Größe/Äpfel & Öl_ Süße Übung.epub',
			$this->service->renderPath('{author}/{title}', $v, '.epub'),
		);
	}

	public function testSlashesInValuesDoNotCreateFolders(): void {
		$v = $this->vars(['title' => 'AC/DC: Teil 1/2', 'author' => '../../etc']);
		$path = $this->service->renderPath('{author}/{title}', $v, '.epub');
		$this->assertSame('_.._etc/AC_DC_ Teil 1_2.epub', $path);
		$this->assertSame(1, substr_count($path, '/'));
	}

	public function testDotSegmentsAreDropped(): void {
		$this->assertSame('Der Titel.epub', $this->service->renderPath('../{author}/../{title}', $this->vars(['author' => '..']), '.epub'));
	}

	public function testExtensionIsLowercasedAndAppendedOnce(): void {
		$this->assertSame('Der Titel.cbz', $this->service->renderPath('{title}', $this->vars(), '.CBZ'));
		$this->assertSame('Der Titel', $this->service->renderPath('{title}', $this->vars(), ''));
	}

	public function testEmptyFileNameFallsBackToTitleThenOriginalName(): void {
		$empty = $this->vars(['author' => '', 'title' => '']);
		$this->assertSame('original.epub', $this->service->renderPath('{author}', $empty, '.epub', 'original'));
		$this->assertSame('Book.epub', $this->service->renderPath('{author}', $empty, '.epub'));
		$this->assertSame('Der Titel.epub', $this->service->renderPath('{author}', $this->vars(['author' => '']), '.epub'));
	}

	public function testSegmentsAreCappedAt255Bytes(): void {
		$long = str_repeat('Ü', 300);
		$parts = explode('/', $this->service->renderPath('{title}/{title}', $this->vars(['title' => $long]), '.epub'));
		$this->assertCount(2, $parts);
		foreach ($parts as $part) {
			$this->assertLessThanOrEqual(255, strlen($part));
		}
		$this->assertStringEndsWith('.epub', $parts[1]);
		$this->assertTrue(mb_check_encoding($parts[0], 'UTF-8'));
	}

	public function testUnknownPlaceholderIsEmpty(): void {
		$this->assertSame('Der Titel.epub', $this->service->renderPath('{nope} - {title}', $this->vars(), '.epub'));
	}

	public function testAuthorsAndYearAndGenre(): void {
		$this->assertSame(
			'Fantasy/2020/Erika Muster, Max Mustermann.epub',
			$this->service->renderPath('{genre}/{year}/{authors}', $this->vars(), '.epub'),
		);
	}

	public function testTargetFolderRejectsTraversal(): void {
		$this->expectException(OrganizeException::class);
		$this->service->resolveTargetFolder('u', '/Books/../Secret');
	}

	public function testTargetFolderMustBeInsideLibrary(): void {
		$this->library->method('isPathInLibrary')->willReturn(false);
		$this->expectException(OrganizeException::class);
		$this->service->resolveTargetFolder('u', '/Elsewhere');
	}

	public function testTargetFolderDefaultsToFirstLibraryFolder(): void {
		$this->assertSame('/Books', $this->service->resolveTargetFolder('u', null));
		$this->assertSame('/Books', $this->service->resolveTargetFolder('u', '  '));
	}

	public function testTargetFolderMayBeCreatable(): void {
		$this->library->method('isPathInLibrary')->willReturn(true);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(false);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
		$this->assertSame('/Books/Sorted/New', $this->service->resolveTargetFolder('u', 'Books//Sorted/./New/'));
	}

	public function testSelectionLimits(): void {
		$this->expectException(OrganizeException::class);
		$this->service->preview('u', range(1, 501), '{title}', null);
	}
}
