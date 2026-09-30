<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Builds small, deterministic fixture books into tests/fixtures/books/.
 *
 *   php -d extension=zip -d extension=gd tests/fixtures/generate.php
 *
 * Other tests may `require_once` this file and call ebr_generate_fixtures($dir).
 */

const EBR_FIXTURE_TIME = 946684800; // 2000-01-01, fixed mtime for zip entries

/** @return string PNG bytes of a flat colour image */
function ebr_png(int $w, int $h, int $r, int $g, int $b): string {
	$img = imagecreatetruecolor($w, $h);
	$c = imagecolorallocate($img, $r, $g, $b);
	imagefill($img, 0, 0, $c);
	ob_start();
	imagepng($img, null, 9);
	return (string)ob_get_clean();
}

/**
 * @param array<string, string> $files name => content (order kept)
 * @param list<string> $stored names written uncompressed
 */
function ebr_zip(string $path, array $files, array $stored = []): void {
	@unlink($path);
	$zip = new ZipArchive();
	if ($zip->open($path, ZipArchive::CREATE) !== true) {
		throw new RuntimeException('Cannot create ' . $path);
	}
	foreach ($files as $name => $content) {
		$zip->addFromString($name, $content);
		$zip->setMtimeName($name, EBR_FIXTURE_TIME);
		$zip->setCompressionName($name, in_array($name, $stored, true) ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE);
	}
	$zip->close();
}

function ebr_container(string $opf): string {
	return '<?xml version="1.0"?>' . "\n"
		. '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'
		. '<rootfiles><rootfile full-path="' . $opf . '" media-type="application/oebps-package+xml"/></rootfiles></container>';
}

function ebr_xhtml(string $title, string $body): string {
	return '<?xml version="1.0" encoding="utf-8"?>' . "\n"
		. '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>' . $title . '</title></head><body>' . $body . '</body></html>';
}

function ebr_epub2(string $path): void {
	$opf = '<?xml version="1.0" encoding="utf-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="2.0" unique-identifier="bookid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:opf="http://www.idpf.org/2007/opf">
    <dc:title>Der Test-Roman</dc:title>
    <dc:creator opf:role="aut" opf:file-as="Mustermann, Erika">Erika Mustermann</dc:creator>
    <dc:creator opf:role="ill">Max Zeichner</dc:creator>
    <dc:language>de</dc:language>
    <dc:publisher>Testverlag</dc:publisher>
    <dc:date>2019-05-17T10:00:00+00:00</dc:date>
    <dc:identifier id="bookid">urn:uuid:11111111-2222-3333-4444-555555555555</dc:identifier>
    <dc:identifier opf:scheme="ISBN">978-3-16-148410-0</dc:identifier>
    <dc:description>&lt;p&gt;Ein &lt;b&gt;spannender&lt;/b&gt; Roman.&lt;/p&gt;&lt;script&gt;alert(1)&lt;/script&gt;&lt;p&gt;Zweiter Absatz.&lt;/p&gt;</dc:description>
    <dc:subject>Fantasy</dc:subject>
    <dc:subject>Lieblingsbuch</dc:subject>
    <meta name="calibre:series" content="Die Testreihe"/>
    <meta name="calibre:series_index" content="2.0"/>
    <meta name="cover" content="cover-img"/>
  </metadata>
  <manifest>
    <item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>
    <item id="cover-img" href="images/cover.png" media-type="image/png"/>
    <item id="ch1" href="ch1.xhtml" media-type="application/xhtml+xml"/>
    <item id="ch2" href="ch2.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine toc="ncx"><itemref idref="ch1"/><itemref idref="ch2"/></spine>
</package>';
	$ncx = '<?xml version="1.0" encoding="utf-8"?>
<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><head/><docTitle><text>Der Test-Roman</text></docTitle>
<navMap>
<navPoint id="n1" playOrder="1"><navLabel><text>Kapitel 1</text></navLabel><content src="ch1.xhtml"/></navPoint>
<navPoint id="n2" playOrder="2"><navLabel><text>Kapitel 2</text></navLabel><content src="ch2.xhtml"/></navPoint>
</navMap></ncx>';
	ebr_zip($path, [
		'mimetype' => 'application/epub+zip',
		'META-INF/container.xml' => ebr_container('OEBPS/content.opf'),
		'OEBPS/content.opf' => $opf,
		'OEBPS/toc.ncx' => $ncx,
		'OEBPS/ch1.xhtml' => ebr_xhtml('Kapitel 1', '<h1>Kapitel 1</h1><p>Es war einmal.</p>'),
		'OEBPS/ch2.xhtml' => ebr_xhtml('Kapitel 2', '<h1>Kapitel 2</h1><p>Und so weiter.</p>'),
		'OEBPS/images/cover.png' => ebr_png(60, 90, 200, 30, 30),
	], ['mimetype']);
}

function ebr_epub3(string $path): void {
	$opf = '<?xml version="1.0" encoding="utf-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="bookid">urn:isbn:9783161484100</dc:identifier>
    <dc:title>The Third Book</dc:title>
    <dc:creator id="a1">Jane Doe</dc:creator>
    <meta refines="#a1" property="role" scheme="marc:relators">aut</meta>
    <dc:creator id="a2">John Roe</dc:creator>
    <meta refines="#a2" property="role" scheme="marc:relators">aut</meta>
    <dc:language>en</dc:language>
    <dc:publisher>Example Press</dc:publisher>
    <dc:date>2021-03-04</dc:date>
    <dc:description>Plain text description.

Second paragraph &amp; more.</dc:description>
    <dc:subject>Science Fiction</dc:subject>
    <dc:subject>Space Opera</dc:subject>
    <dc:subject>Crime</dc:subject>
    <meta name="calibre:series" content="Space Saga"/>
    <meta name="calibre:series_index" content="3"/>
    <meta property="dcterms:modified">2021-03-04T00:00:00Z</meta>
  </metadata>
  <manifest>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="cover" href="cover.png" media-type="image/png" properties="cover-image"/>
    <item id="c1" href="c1.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine><itemref idref="c1"/></spine>
</package>';
	$nav = '<?xml version="1.0" encoding="utf-8"?>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><head><title>Nav</title></head><body>
<nav epub:type="toc"><ol><li><a href="c1.xhtml">Chapter 1</a></li></ol></nav></body></html>';
	ebr_zip($path, [
		'mimetype' => 'application/epub+zip',
		'META-INF/container.xml' => ebr_container('content.opf'),
		'content.opf' => $opf,
		'nav.xhtml' => $nav,
		'c1.xhtml' => ebr_xhtml('Chapter 1', '<h1>Chapter 1</h1><p>Hello.</p>'),
		'cover.png' => ebr_png(80, 120, 30, 60, 200),
	], ['mimetype']);
}

/** EPUB 3 with belongs-to-collection series and a guide-less, cover-less layout. */
function ebr_epub3_collection(string $path): void {
	$opf = '<?xml version="1.0" encoding="utf-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="bookid">urn:uuid:aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee</dc:identifier>
    <dc:title>Collection Book</dc:title>
    <dc:creator>Anna Autor</dc:creator>
    <dc:language>en</dc:language>
    <meta property="belongs-to-collection" id="c1">Great Series</meta>
    <meta refines="#c1" property="collection-type">series</meta>
    <meta refines="#c1" property="group-position">4.5</meta>
  </metadata>
  <manifest>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="c1x" href="c1.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine><itemref idref="c1x"/></spine>
</package>';
	ebr_zip($path, [
		'mimetype' => 'application/epub+zip',
		'META-INF/container.xml' => ebr_container('content.opf'),
		'content.opf' => $opf,
		'nav.xhtml' => ebr_xhtml('Nav', '<nav><ol><li><a href="c1.xhtml">One</a></li></ol></nav>'),
		'c1.xhtml' => ebr_xhtml('One', '<p>One</p>'),
	], ['mimetype']);
}

function ebr_fb2_xml(): string {
	$cover = base64_encode(ebr_png(50, 75, 30, 160, 60));
	return '<?xml version="1.0" encoding="utf-8"?>
<FictionBook xmlns="http://www.gribuser.ru/xml/fictionbook/2.0" xmlns:l="http://www.w3.org/1999/xlink">
<description>
<title-info>
<genre>sf_fantasy</genre>
<genre>detective</genre>
<genre>no_such_code</genre>
<author><first-name>Ivan</first-name><middle-name>I.</middle-name><last-name>Petrov</last-name></author>
<book-title>Das FB2-Buch</book-title>
<annotation><p>Eine <strong>kurze</strong> Beschreibung.</p><empty-line/><p>Noch ein <emphasis>Absatz</emphasis>.</p></annotation>
<keywords>Drachen, Magie; Lieblingsbuch</keywords>
<date value="2015-06-01">June 2015</date>
<coverpage><image l:href="#cover.png"/></coverpage>
<lang>de</lang>
<sequence name="FB2-Serie" number="7"/>
</title-info>
<document-info><author><nickname>tester</nickname></author><date value="2020-01-01">2020</date><id>fixture-1</id><version>1.0</version></document-info>
<publish-info><publisher>FB2 Verlag</publisher><year>2016</year><isbn>978-0-306-40615-7</isbn></publish-info>
</description>
<body><title><p>Das FB2-Buch</p></title><section><title><p>Eins</p></title><p>Text.</p></section></body>
<binary id="cover.png" content-type="image/png">' . $cover . '</binary>
</FictionBook>
';
}

function ebr_cbz(string $path): void {
	$info = '<?xml version="1.0" encoding="utf-8"?>
<ComicInfo xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <Title>Comic Title</Title>
  <Series>Comic Series</Series>
  <Number>12</Number>
  <Summary>A comic summary.

With two paragraphs.</Summary>
  <Year>2018</Year><Month>9</Month><Day>3</Day>
  <Writer>Alice Writer, Bob Writer</Writer>
  <Publisher>Comic Publisher</Publisher>
  <Genre>Superhero, Action</Genre>
  <Tags>Lieblingsserie, Klassiker</Tags>
  <LanguageISO>en</LanguageISO>
  <PageCount>5</PageCount>
  <Pages>
    <Page Image="0"/>
    <Page Image="1"/>
    <Page Image="2" Type="FrontCover"/>
    <Page Image="3"/>
    <Page Image="4"/>
  </Pages>
</ComicInfo>';
	$files = ['ComicInfo.xml' => $info];
	// natural sort matters: page10 would sort before page2 in plain string order, so names are page1..page5
	$colors = [[255, 0, 0], [0, 255, 0], [0, 0, 255], [255, 255, 0], [255, 0, 255]];
	foreach ($colors as $i => [$r, $g, $b]) {
		$files['page' . ($i + 1) . '.png'] = ebr_png(20, 30, $r, $g, $b);
	}
	ebr_zip($path, $files);
}

/** Comic without ComicInfo.xml: cover = first image in natural order (page2 before page10). */
function ebr_cbz_plain(string $path): void {
	ebr_zip($path, [
		'page10.png' => ebr_png(10, 10, 0, 0, 0),
		'page2.png' => ebr_png(10, 10, 255, 255, 255),
		'notes.txt' => 'not an image',
	]);
}

/** Minimal MOBI: PDB header, record 0 (PalmDOC + MOBI header + EXTH + title), one text record, one cover image. */
function ebr_mobi(string $path, bool $drm = false): void {
	$title = 'Das MOBI-Buch';
	$exth = [
		[100, 'Karl Kindle'],
		[101, 'Mobi Verlag'],
		[103, '<p>MOBI <b>Beschreibung</b></p>'],
		[104, '9783161484100'],
		[105, 'Fantasy'],
		[105, 'Drachen; Magie'],
		[106, '2012-07-08T00:00:00+00:00'],
		[201, pack('N', 0)],
		[503, $title],
		[524, 'de'],
	];
	$body = '';
	foreach ($exth as [$type, $data]) {
		$body .= pack('NN', $type, strlen($data) + 8) . $data;
	}
	$exthBlock = 'EXTH' . pack('NN', 12 + strlen($body), count($exth)) . $body;
	$pad = (4 - strlen($exthBlock) % 4) % 4;
	$exthBlock .= str_repeat("\0", $pad);

	$headerLen = 232;
	$mobi = str_repeat("\0", $headerLen);
	$put = static function (int $off, string $bytes) use (&$mobi): void {
		$mobi = substr_replace($mobi, $bytes, $off, strlen($bytes));
	};
	$put(0, 'MOBI');
	$put(4, pack('N', $headerLen));
	$put(8, pack('N', 2));       // type: book
	$put(12, pack('N', 65001));  // UTF-8
	$put(16, pack('N', 1234));   // uid
	$put(20, pack('N', 6));      // version
	$fullNameOffset = 16 + $headerLen + strlen($exthBlock);
	$put(68, pack('N', $fullNameOffset));
	$put(72, pack('N', strlen($title)));
	$put(92, pack('N', 2));      // first image record index
	$put(112, pack('N', 0x40));  // EXTH flag

	$text = 'Hello MOBI.';
	$palmdoc = pack('nnNnnnn', 1, 0, strlen($text), 1, 4096, $drm ? 2 : 0, 0);
	$rec0 = $palmdoc . $mobi . $exthBlock . $title . "\0\0";
	$rec0 .= str_repeat("\0", (4 - strlen($rec0) % 4) % 4);
	$cover = ebr_png(40, 60, 120, 40, 160);

	$records = [$rec0, $text, $cover];
	$listLen = 78 + 8 * count($records) + 2;
	$offset = $listLen;
	$pdb = str_pad('Das_MOBI-Buch', 32, "\0") . str_repeat("\0", 28) . 'BOOKMOBI'
		. pack('N', 2 * count($records) - 1) . pack('N', 0) . pack('n', count($records));
	$list = '';
	foreach ($records as $i => $rec) {
		$list .= pack('N', $offset) . chr(0) . substr(pack('N', $i * 2), 1);
		$offset += strlen($rec);
	}
	file_put_contents($path, $pdb . $list . "\0\0" . implode('', $records));
}

/** @return list<string> written files */
function ebr_generate_fixtures(string $dir): array {
	if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
		throw new RuntimeException('Cannot create ' . $dir);
	}
	$fb2 = ebr_fb2_xml();
	file_put_contents($dir . '/book.fb2', $fb2);
	ebr_zip($dir . '/book.fb2.zip', ['book.fb2' => $fb2]);
	ebr_epub2($dir . '/epub2.epub');
	ebr_epub3($dir . '/epub3.epub');
	ebr_epub3_collection($dir . '/epub3-collection.epub');
	ebr_cbz($dir . '/comic.cbz');
	ebr_cbz_plain($dir . '/plain.cbz');
	ebr_mobi($dir . '/book.mobi');
	ebr_mobi($dir . '/drm.mobi', true);
	$files = glob($dir . '/*') ?: [];
	sort($files);
	return $files;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
	foreach (ebr_generate_fixtures(__DIR__ . '/books') as $f) {
		echo basename($f), ' ', filesize($f), " bytes\n";
	}
}
