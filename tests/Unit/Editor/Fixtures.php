<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Editor;

use ZipArchive;

/**
 * Minimal, self-contained fixture builders for the editor tests.
 */
final class Fixtures {
	public const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

	public static function png(): string {
		return (string)base64_decode(self::PNG_B64, true);
	}

	public static function tmp(string $suffix): string {
		$p = tempnam(sys_get_temp_dir(), 'ebrt');
		if ($p === false) {
			throw new \RuntimeException('tempnam');
		}
		@unlink($p);
		return $p . $suffix;
	}

	/** @param array<string, string> $files */
	private static function zip(string $path, array $files, bool $mimetypeFirst = false): void {
		$z = new ZipArchive();
		$z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		foreach ($files as $name => $content) {
			$z->addFromString($name, $content);
			if ($name === 'mimetype') {
				$z->setCompressionName($name, ZipArchive::CM_STORE);
			}
		}
		$z->close();
	}

	public static function epub3(): string {
		$path = self::tmp('.epub');
		$opf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="uid">urn:uuid:12345678-1234-1234-1234-123456789abc</dc:identifier>
    <dc:title>Test Book</dc:title>
    <dc:creator id="a1">Jane Doe</dc:creator>
    <meta refines="#a1" property="role" scheme="marc:relators">aut</meta>
    <dc:language>en</dc:language>
    <dc:subject>Fantasy</dc:subject>
    <dc:description>Plain description</dc:description>
    <meta property="dcterms:modified">2020-01-01T00:00:00Z</meta>
    <meta name="cover" content="cover-img"/>
  </metadata>
  <manifest>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>
    <item id="css" href="style/main.css" media-type="text/css"/>
    <item id="cover-img" href="images/cover.png" media-type="image/png" properties="cover-image"/>
    <item id="pic" href="images/pic.png" media-type="image/png"/>
    <item id="ch1" href="text/ch1.xhtml" media-type="application/xhtml+xml"/>
    <item id="ch2" href="text/ch2.xhtml" media-type="application/xhtml+xml"/>
    <item id="ch3" href="text/ch3.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine toc="ncx">
    <itemref idref="ch1"/>
    <itemref idref="ch2"/>
    <itemref idref="ch3" linear="no"/>
  </spine>
</package>
XML;
		$page = static fn (string $title, string $body): string => '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>' . $title . '</title><link rel="stylesheet" href="../style/main.css"/></head><body>' . $body . '</body></html>';
		$nav = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><head><title>Nav</title></head><body>
<nav epub:type="toc" id="toc"><h1>Contents</h1><ol>
<li><a href="text/ch1.xhtml">Chapter One</a></li>
<li><a href="text/ch2.xhtml">Chapter Two</a><ol><li><a href="text/ch2.xhtml#s1">Section</a></li></ol></li>
<li><a href="text/ch3.xhtml">Chapter Three</a></li>
</ol></nav>
<nav epub:type="landmarks" hidden="hidden"><ol><li><a epub:type="bodymatter" href="text/ch2.xhtml">Start</a></li></ol></nav>
</body></html>
XML;
		$ncx = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><head><meta name="dtb:uid" content="x"/><meta name="dtb:depth" content="2"/></head>
<docTitle><text>Test Book</text></docTitle>
<navMap>
<navPoint id="n1" playOrder="1"><navLabel><text>Chapter One</text></navLabel><content src="text/ch1.xhtml"/></navPoint>
<navPoint id="n2" playOrder="2"><navLabel><text>Chapter Two</text></navLabel><content src="text/ch2.xhtml"/></navPoint>
<navPoint id="n3" playOrder="3"><navLabel><text>Chapter Three</text></navLabel><content src="text/ch3.xhtml"/></navPoint>
</navMap></ncx>
XML;
		self::zip($path, [
			'mimetype' => 'application/epub+zip',
			'META-INF/container.xml' => '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>',
			'META-INF/encryption.xml' => '<?xml version="1.0"?><encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container"/>',
			'OEBPS/content.opf' => $opf,
			'OEBPS/nav.xhtml' => $nav,
			'OEBPS/toc.ncx' => $ncx,
			'OEBPS/style/main.css' => 'body { color: black; }',
			'OEBPS/images/cover.png' => self::png(),
			'OEBPS/images/pic.png' => self::png(),
			'OEBPS/text/ch1.xhtml' => $page('One', '<p>One <a href="ch2.xhtml#s1">link to two</a></p>'),
			'OEBPS/text/ch2.xhtml' => $page('Two', '<p id="s1">Two</p><img src="../images/pic.png" alt=""/>'),
			'OEBPS/text/ch3.xhtml' => $page('Three', '<p>Three</p>'),
		]);
		return $path;
	}

	public static function cbz(int $pages = 4, bool $withInfo = false): string {
		$path = self::tmp('.cbz');
		$files = [];
		for ($i = 1; $i <= $pages; $i++) {
			$files['page' . $i . '.png'] = self::png() . str_repeat((string)$i, $i);
		}
		$files['page10.png'] = self::png() . 'ten';
		if ($withInfo) {
			$files['ComicInfo.xml'] = '<?xml version="1.0"?><ComicInfo><Title>Old</Title><Series>S</Series><Number>2</Number><Writer>A, B</Writer><Year>2001</Year><Month>5</Month><Pages><Page Image="0" Type="FrontCover"/><Page Image="2" Bookmark="Act 2"/></Pages></ComicInfo>';
		}
		self::zip($path, $files);
		return $path;
	}

	public static function fb2(bool $zipped = false): string {
		$xml = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<FictionBook xmlns="http://www.gribuser.ru/xml/fictionbook/2.0" xmlns:l="http://www.w3.org/1999/xlink">
<description>
<title-info><genre>sf_fantasy</genre><author><first-name>Ann</first-name><last-name>Author</last-name></author><book-title>Old Title</book-title><lang>de</lang></title-info>
<document-info><author><nickname>x</nickname></author><date>2020</date><id>abc</id><version>1.0</version></document-info>
</description>
<body>
<section><title><p>One</p></title><p>Text one</p></section>
<section><title><p>Two</p></title><section><title><p>Two A</p></title><p>a</p></section></section>
<section><p>Untitled</p></section>
</body>
<body name="notes"><section id="n1"><title><p>1</p></title><p>Note</p></section></body>
</FictionBook>
XML;
		if (!$zipped) {
			$path = self::tmp('.fb2');
			file_put_contents($path, $xml);
			return $path;
		}
		$path = self::tmp('.fbz');
		self::zip($path, ['book.fb2' => $xml]);
		return $path;
	}
}
