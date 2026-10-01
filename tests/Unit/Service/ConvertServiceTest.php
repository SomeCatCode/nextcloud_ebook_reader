<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Db\Book;
use OCA\EbookReader\Db\BookMapper;
use OCA\EbookReader\Db\Progress;
use OCA\EbookReader\Db\Tag;
use OCA\EbookReader\Metadata\ComicArchive;
use OCA\EbookReader\Service\ArchiveTools;
use OCA\EbookReader\Service\ConvertException;
use OCA\EbookReader\Service\ConvertService;
use OCA\EbookReader\Service\LibraryService;
use OCA\EbookReader\Service\ProgressService;
use OCA\EbookReader\Tests\Unit\Metadata\Fixtures;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Storage\IStorage;
use OCP\ITempManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/OcHooksEmitterStub.php';
require_once __DIR__ . '/../Metadata/Fixtures.php';

class ConvertServiceTest extends TestCase {
	private LibraryService&MockObject $library;
	private ProgressService&MockObject $progress;
	private BookMapper&MockObject $books;
	private Folder&MockObject $folder;
	private ConvertService $service;
	/** @var array<string, string> target file name => captured local path */
	private array $written = [];
	/** @var array<string, bool> names that already exist in the folder */
	private array $existing = [];
	/** @var list<Tag> */
	private array $tags = [];
	/** @var list<array{int, array<string, mixed>}> */
	private array $putProgress = [];
	private ?Progress $storedProgress = null;
	private ?Book $lastBook = null;
	private ITempManager&MockObject $temp;

	private function serviceWith(ArchiveTools $tools): ConvertService {
		return new ConvertService($this->library, $tools, $this->progress, $this->books, $this->temp, $this->createMock(LoggerInterface::class));
	}
	private string $tmpRoot;

	protected function setUp(): void {
		$this->tmpRoot = sys_get_temp_dir() . '/ebr-convert-' . bin2hex(random_bytes(4));
		mkdir($this->tmpRoot);
		$this->library = $this->createMock(LibraryService::class);
		$this->progress = $this->createMock(ProgressService::class);
		$this->books = $this->createMock(BookMapper::class);
		$this->folder = $this->createMock(Folder::class);
		$this->folder->method('isCreatable')->willReturn(true);
		$this->folder->method('nodeExists')->willReturnCallback(fn (string $n): bool => isset($this->existing[$n]) || isset($this->written[$n]));
		$this->folder->method('newFile')->willReturnCallback(function (string $name, $content): File {
			$path = $this->tmpRoot . '/out-' . $name;
			file_put_contents($path, $content);
			$this->written[$name] = $path;
			$this->lastBook = $this->book(100 + count($this->written), pathinfo($name, PATHINFO_EXTENSION), $name);
			return $this->fileMock($path, $name, 100 + count($this->written));
		});
		$this->library->method('indexFile')->willReturnCallback(fn (): ?Book => $this->lastBook);
		$this->books->method('findByUserAndFile')->willReturnCallback(fn (): Book => $this->lastBook ?? throw new \LogicException('no book'));
		$this->books->method('update')->willReturnArgument(0);
		$this->library->method('getTags')->willReturnCallback(fn (): array => $this->tags);
		$this->progress->method('get')->willReturnCallback(fn (): ?Progress => $this->storedProgress);
		$this->progress->method('put')->willReturnCallback(function (string $u, int $id, array $locator): array {
			$this->putProgress[] = [$id, $locator];
			return ['status' => 'ok'];
		});
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFolder')->willReturnCallback(function (): string {
			$d = $this->tmpRoot . '/stage-' . bin2hex(random_bytes(3));
			mkdir($d);
			return $d;
		});
		$temp->method('getTemporaryFile')->willReturnCallback(fn (string $post = ''): string => $this->tmpRoot . '/tmp-' . bin2hex(random_bytes(3)) . $post);
		$this->temp = $temp;
		$this->service = $this->serviceWith(new ArchiveTools([]));
	}

	protected function tearDown(): void {
		foreach (glob($this->tmpRoot . '/*') ?: [] as $f) {
			is_dir($f) ? @rmdir($f) : @unlink($f);
		}
		@rmdir($this->tmpRoot);
	}

	private function fileMock(string $localPath, string $name, int $id): File&MockObject {
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn($localPath);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn($id);
		$file->method('getSize')->willReturn((int)filesize($localPath));
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/' . $name);
		$file->method('getParent')->willReturn($this->folder);
		$file->method('isDeletable')->willReturn(true);
		return $file;
	}

	private function book(int $fileId, string $format, string $name): Book {
		$b = new Book();
		$b->setId($fileId);
		$b->setUserId('u');
		$b->setFileId($fileId);
		$b->setFormat($format);
		$b->setPath('/Comics/' . $name);
		$b->setTitle('Comic Title');
		$b->setAuthorsArray(['Alice Writer', 'Bob & Co']);
		$b->setSeries('Comic Series');
		$b->setSeriesIndex(12.0);
		$b->setLanguage('en');
		$b->setPublisher('Pub');
		$b->setPublishedAt('2018-09-03');
		$b->setDescription('<p>Summary &amp; more</p>');
		$b->setRating(4);
		$b->setReadStatus(Book::STATUS_READING);
		$b->setReadStatusManual(false);
		return $b;
	}

	/**
	 * Registers the source and converts. Returns the captured result file path.
	 */
	private function convert(string $localPath, string $sourceFormat, string $target, bool $delete = false, int $fileId = 1): string {
		$name = 'Comic Title.' . $sourceFormat;
		$file = $this->fileMock($localPath, $name, $fileId);
		$book = $this->book($fileId, $sourceFormat, $name);
		$this->libraryLookups[$fileId] = [$book, $file];
		$result = $this->service->convert('u', $fileId, $target, $delete);
		$this->assertSame($target, $result['book']->getFormat());
		return $this->written['Comic Title.' . $target];
	}

	/** @var array<int, array{Book, File}> */
	private array $libraryLookups = [];

	/** Wires getBook/getFileForUser for the ids registered in $libraryLookups. */
	private function wireLibrary(): void {
		$this->library->method('getBook')->willReturnCallback(fn (string $u, int $id): Book => $this->libraryLookups[$id][0]);
		$this->library->method('getFileForUser')->willReturnCallback(fn (string $u, int $id): File => $this->libraryLookups[$id][1]);
	}

	/** @return list<string> page contents in reading order */
	private function pageContents(string $path, string $format): array {
		$a = ComicArchive::open($path, $format);
		$out = [];
		foreach ($a->pages() as $p) {
			$out[] = (string)$a->read($p);
		}
		$a->close();
		return $out;
	}

	/**
	 * A CBZ with 12 pages added in scrambled order (plain string sorting would put page10 before page2).
	 *
	 * @return array{0: string, 1: list<string>} path and page contents in natural reading order
	 */
	private function scrambledCbz(): array {
		$path = Fixtures::temp('.cbz');
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::CREATE);
		$order = [7, 12, 1, 10, 3, 9, 2, 11, 5, 8, 4, 6];
		foreach ($order as $n) {
			$zip->addFromString('page' . $n . '.png', 'content of page ' . $n);
		}
		$zip->addFromString('readme.txt', 'ignored');
		$zip->close();
		$expected = [];
		for ($n = 1; $n <= 12; $n++) {
			$expected[] = 'content of page ' . $n;
		}
		return [$path, $expected];
	}

	public function testCbzToCbtToCbzKeepsOrderAndComicInfo(): void {
		$this->wireLibrary();
		[$cbz, $original] = $this->scrambledCbz();
		$this->assertSame($original, $this->pageContents($cbz, 'cbz'));

		$cbt = $this->convert($cbz, 'cbz', 'cbt');
		$this->assertSame($original, $this->pageContents($cbt, 'cbt'), 'cbt keeps page order');
		$a = ComicArchive::open($cbt, 'cbt');
		$this->assertSame(sprintf('%04d.png', 1), $a->pages()[0]);
		$this->assertCount(12, $a->pages());
		$info = $a->comicInfo();
		$a->close();
		$this->assertNotNull($info, 'ComicInfo is generated from the book when the source had none');
		$this->assertStringContainsString('<Title>Comic Title</Title>', (string)$info);
		$this->assertStringContainsString('Bob &amp; Co', (string)$info);

		$back = $this->convert($cbt, 'cbt', 'cbz', false, 2);
		$this->assertSame($original, $this->pageContents($back, 'cbz'), 'roundtrip keeps page order');
		$b = ComicArchive::open($back, 'cbz');
		$this->assertSame($info, $b->comicInfo(), 'ComicInfo survives the roundtrip');
		$b->close();
	}

	public function testComicInfoOfSourceIsKeptAndImagesAreStored(): void {
		$this->wireLibrary();
		$cbz = Fixtures::path('comic.cbz');
		$orig = ComicArchive::open($cbz, 'cbz');
		$origInfo = $orig->comicInfo();
		$orig->close();
		$out = $this->convert($cbz, 'cbz', 'cbt');
		$a = ComicArchive::open($out, 'cbt');
		$this->assertSame($origInfo, $a->comicInfo());
		$a->close();
		$zipOut = $this->convert($out, 'cbt', 'cbz', false, 5);
		$zip = new \ZipArchive();
		$zip->open($zipOut);
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$st = $zip->statIndex($i);
			if (preg_match('/\.png$/', (string)$st['name']) === 1) {
				$this->assertSame(\ZipArchive::CM_STORE, $st['comp_method'], 'images are stored, not recompressed');
			}
		}
		$zip->close();
	}

	public function testCbzToEpubIsFixedLayout(): void {
		$this->wireLibrary();
		$this->tags = [$this->tag(Tag::TYPE_GENRE, 'Superhero', Tag::SOURCE_FILE), $this->tag(Tag::TYPE_TAG, 'Favourite', Tag::SOURCE_APP)];
		$cbz = Fixtures::path('comic.cbz');
		$epub = $this->convert($cbz, 'cbz', 'epub');

		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($epub));
		$first = $zip->statIndex(0);
		$this->assertSame('mimetype', $first['name'], 'mimetype is the first entry');
		$this->assertSame(\ZipArchive::CM_STORE, $first['comp_method'], 'mimetype is stored');
		$this->assertSame('application/epub+zip', $zip->getFromName('mimetype'));

		$opf = (string)$zip->getFromName('OEBPS/content.opf');
		$dom = new \DOMDocument();
		$this->assertTrue($dom->loadXML($opf), 'OPF is well-formed');
		$this->assertStringContainsString('<meta property="rendition:layout">pre-paginated</meta>', $opf);
		$this->assertStringContainsString('<dc:title>Comic Title</dc:title>', $opf);
		$this->assertStringContainsString('<dc:creator>Bob &amp; Co</dc:creator>', $opf);
		$this->assertStringContainsString('<dc:subject>Superhero</dc:subject>', $opf);
		$this->assertStringContainsString('belongs-to-collection', $opf);
		$this->assertSame(5, substr_count($opf, '<itemref '), 'one spine item per page');
		$this->assertSame(1, substr_count($opf, 'properties="cover-image"'));
		// FrontCover is page index 2
		$this->assertMatchesRegularExpression('#<item id="img0003" href="images/0003\.png" media-type="image/png" properties="cover-image"/>#', $opf);
		$this->assertStringNotContainsString('page-progression-direction', $opf);

		for ($n = 1; $n <= 5; $n++) {
			$page = (string)$zip->getFromName(sprintf('OEBPS/pages/%04d.xhtml', $n));
			$d = new \DOMDocument();
			$this->assertTrue($d->loadXML($page), 'page ' . $n . ' is well-formed');
			$this->assertMatchesRegularExpression('#<meta name="viewport" content="width=\d+, height=\d+"/>#', $page);
			$this->assertNotFalse($zip->locateName(sprintf('OEBPS/images/%04d.png', $n)));
		}
		$nav = (string)$zip->getFromName('OEBPS/nav.xhtml');
		$this->assertStringContainsString('epub:type="toc"', $nav);
		$this->assertSame(5, substr_count($nav, '<li>'));
		$this->assertStringContainsString('urn:oasis:names:tc:opendocument:xmlns:container', (string)$zip->getFromName('META-INF/container.xml'));
		$zip->close();

		// the app's own extractor reads it back
		$meta = (new \OCA\EbookReader\Metadata\EpubExtractor())->extract($epub);
		$this->assertSame('Comic Title', $meta->title);
	}

	public function testMangaCreatesRightToLeftSpine(): void {
		$this->wireLibrary();
		$src = Fixtures::temp('.cbz');
		$zip = new \ZipArchive();
		$zip->open($src, \ZipArchive::CREATE);
		$zip->addFromString('ComicInfo.xml', '<ComicInfo><Title>M</Title><Manga>YesAndRightToLeft</Manga></ComicInfo>');
		$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==');
		$zip->addFromString('1.png', (string)$png);
		$zip->addFromString('2.png', (string)$png);
		$zip->close();
		$epub = $this->convert($src, 'cbz', 'epub');
		$z = new \ZipArchive();
		$z->open($epub);
		$this->assertStringContainsString('<spine page-progression-direction="rtl">', (string)$z->getFromName('OEBPS/content.opf'));
		$z->close();
	}

	public function testRatingStatusAppTagsAndProgressAreCarriedOver(): void {
		$this->wireLibrary();
		$this->tags = [$this->tag(Tag::TYPE_TAG, 'Favourite', Tag::SOURCE_APP)];
		$setTags = [];
		$this->library->method('setTags')->willReturnCallback(function (int $bookId, string $type, array $names, string $source) use (&$setTags): void {
			$setTags[] = [$bookId, $type, $names, $source];
		});
		[$cbz] = $this->scrambledCbz();
		$p = new Progress();
		$p->setLocator((string)json_encode(['href' => 'page3.png', 'type' => 'image/png', 'locations' => ['position' => 3, 'totalProgression' => 0.5]]));
		$p->setPercentage(0.5);
		$p->setDevice('phone');
		$p->setClientUpdatedAt(1234);
		$this->storedProgress = $p;

		$this->convert($cbz, 'cbz', 'cbt');
		$this->assertCount(1, $this->putProgress);
		[$newFileId, $locator] = $this->putProgress[0];
		$this->assertSame(101, $newFileId);
		$this->assertSame('0003.png', $locator['href'], 'href remapped by page index');
		$this->assertSame(3, $locator['locations']['position']);
		$this->assertSame(0.5, $locator['locations']['totalProgression']);
		$this->assertSame([[101, 'tag', ['Favourite'], 'app']], $setTags);
	}

	public function testDeleteOriginalOnlyAfterWrite(): void {
		$this->wireLibrary();
		$cbz = Fixtures::path('plain.cbz');
		$name = 'Comic Title.cbz';
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturn((int)filesize($cbz));
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn($cbz);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/' . $name);
		$file->method('getParent')->willReturn($this->folder);
		$file->method('isDeletable')->willReturn(true);
		$file->expects($this->once())->method('delete');
		$this->libraryLookups[1] = [$this->book(1, 'cbz', $name), $file];
		$this->service->convert('u', 1, 'cbt', true);
		$this->assertArrayHasKey('Comic Title.cbt', $this->written);
	}

	public function testExistingTargetIsAConflictAndNothingIsDeleted(): void {
		$this->wireLibrary();
		$this->existing['Comic Title.cbt'] = true;
		$name = 'Comic Title.cbz';
		$file = $this->fileMock(Fixtures::path('plain.cbz'), $name, 1);
		$this->libraryLookups[1] = [$this->book(1, 'cbz', $name), $file];
		try {
			$this->service->convert('u', 1, 'cbt', true);
			$this->fail('expected conflict');
		} catch (ConvertException $e) {
			$this->assertSame(409, $e->getStatus());
		}
		$this->assertSame([], $this->written);
	}

	public function testCbrCannotBeWrittenAndUnknownTargetsAreRejected(): void {
		$this->wireLibrary();
		$name = 'Comic Title.cbz';
		$this->libraryLookups[1] = [$this->book(1, 'cbz', $name), $this->fileMock(Fixtures::path('plain.cbz'), $name, 1)];
		foreach (['cbr' => 415, 'pdf' => 400, 'cbz' => 400] as $target => $status) {
			try {
				$this->service->convert('u', 1, $target, false);
				$this->fail('expected failure for ' . $target);
			} catch (ConvertException $e) {
				$this->assertSame($status, $e->getStatus(), $target);
			}
		}
	}

	public function testCb7NeedsTheServerToolOtherwiseTheBrowserDoesIt(): void {
		$this->wireLibrary();
		$name = 'Comic Title.cbz';
		$this->libraryLookups[1] = [$this->book(1, 'cbz', $name), $this->fileMock(Fixtures::path('plain.cbz'), $name, 1)];
		$t = $this->service->targets('u', 1);
		$this->assertSame('cbz', $t['source']);
		$byFormat = [];
		foreach ($t['targets'] as $x) {
			$byFormat[$x['format']] = $x;
		}
		$this->assertSame(['cbz', 'cbr', 'cb7', 'cbt', 'epub'], array_keys($byFormat));
		$this->assertSame('unavailable', $byFormat['cbz']['mode']);
		$this->assertSame('unavailable', $byFormat['cbr']['mode']);
		$this->assertSame(ConvertService::REASON_RAR, $byFormat['cbr']['reason']);
		$this->assertSame('server', $byFormat['cbt']['mode']);
		$this->assertSame('server', $byFormat['epub']['mode']);
		$this->assertSame('client', $byFormat['cb7']['mode'], 'no 7z on the server: the browser can write 7z');

		$this->expectException(ConvertException::class);
		$this->service->convert('u', 1, 'cb7', false);
	}

	public function testCbrSourceWithoutToolIsConvertedInTheBrowser(): void {
		$this->wireLibrary();
		$name = 'Old.cbr';
		$this->libraryLookups[1] = [$this->book(1, 'cbr', $name), $this->fileMock(Fixtures::path('plain.cbz'), $name, 1)];
		$modes = [];
		foreach ($this->service->targets('u', 1)['targets'] as $x) {
			$modes[$x['format']] = $x['mode'];
		}
		$this->assertSame(['cbz' => 'client', 'cbr' => 'unavailable', 'cb7' => 'client', 'cbt' => 'client', 'epub' => 'client'], $modes);
	}

	public function testExistingFileMakesTargetUnavailableAndEpubSourcesHaveNoTargets(): void {
		$this->wireLibrary();
		$this->existing['Comic Title.cbt'] = true;
		$name = 'Comic Title.cbz';
		$this->libraryLookups[1] = [$this->book(1, 'cbz', $name), $this->fileMock(Fixtures::path('plain.cbz'), $name, 1)];
		foreach ($this->service->targets('u', 1)['targets'] as $x) {
			if ($x['format'] === 'cbt') {
				$this->assertSame('unavailable', $x['mode']);
				$this->assertSame(ConvertService::REASON_EXISTS, $x['reason']);
			}
		}
		$this->libraryLookups[2] = [$this->book(2, 'epub', 'x.epub'), $this->fileMock(Fixtures::path('plain.cbz'), 'x.epub', 2)];
		$this->assertSame([], $this->service->targets('u', 2)['targets']);
	}

	public function testCb7RoundtripWithRealSevenZipIfInstalled(): void {
		$tools = new ArchiveTools();
		if (!$tools->canRead('cb7') || !$tools->canWrite('cb7')) {
			$this->markTestSkipped('no 7z installed');
		}
		$this->service = $this->serviceWith($tools);
		$this->wireLibrary();
		[$cbz, $original] = $this->scrambledCbz();
		$cb7 = $this->convert($cbz, 'cbz', 'cb7');
		$this->assertSame($original, $this->pageContentsWith($cb7, 'cb7', $tools));
		$back = $this->convert($cb7, 'cb7', 'cbz', false, 2);
		$this->assertSame($original, $this->pageContents($back, 'cbz'));
	}

	/** @return list<string> */
	private function pageContentsWith(string $path, string $format, ArchiveTools $tools): array {
		$a = ComicArchive::open($path, $format, $tools);
		$out = [];
		foreach ($a->pages() as $p) {
			$out[] = (string)$a->read($p);
		}
		$a->close();
		return $out;
	}

	public function testCapabilities(): void {
		$c = $this->service->capabilities();
		$this->assertSame(['sevenZip' => false, 'unrar' => false, 'bsdtar' => false], $c['tools']);
		$this->assertSame(['cbz', 'cbt'], $c['server']['read']);
		$this->assertSame(['cbz', 'cbt', 'epub'], $c['server']['write']);
	}

	private function tag(string $type, string $name, string $source): Tag {
		$t = new Tag();
		$t->setType($type);
		$t->setName($name);
		$t->setSource($source);
		return $t;
	}
}
