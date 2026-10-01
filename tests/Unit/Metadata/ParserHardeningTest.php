<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Editor\EditorException;
use OCA\EbookReader\Editor\EditorUtil;
use OCA\EbookReader\Metadata\BookMetadata;
use OCA\EbookReader\Metadata\EpubExtractor;
use OCA\EbookReader\Metadata\Fb2Extractor;
use OCA\EbookReader\Metadata\MobiExtractor;
use OCA\EbookReader\Metadata\SafeZip;
use OCA\EbookReader\Metadata\UnsafeArchiveException;
use OCA\EbookReader\Metadata\XmlUtil;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures.php';

/** Regression tests of the parser hardening (security audit 2026-10-01, B1/B3/B4/B5/B7). */
class ParserHardeningTest extends TestCase {
	private const LAUGHS = '<?xml version="1.0" encoding="UTF-8"?>'
		. '<!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">'
		. '<!ENTITY lol3 "&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;">]>'
		. '<package xmlns="http://www.idpf.org/2007/opf"><metadata><title xmlns="http://purl.org/dc/elements/1.1/">&lol3;</title></metadata></package>';

	private static function utf16(string $xml): string {
		$xml = str_replace('encoding="UTF-8"', 'encoding="UTF-16"', $xml);
		return "\xFF\xFE" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8');
	}

	// ---- B1 ---------------------------------------------------------------------------------

	public function testBillionLaughsRejectedByEditorUtil(): void {
		$this->expectException(EditorException::class);
		EditorUtil::loadXml(self::LAUGHS, 'OPF');
	}

	public function testBillionLaughsUtf16RejectedByEditorUtil(): void {
		$this->expectException(EditorException::class);
		EditorUtil::loadXml(self::utf16(self::LAUGHS), 'OPF');
	}

	public function testBillionLaughsRejectedByXmlUtil(): void {
		$this->assertNull(XmlUtil::load(self::LAUGHS));
		$this->assertNull(XmlUtil::load("\xEF\xBB\xBF" . self::LAUGHS));
		$this->assertNull(XmlUtil::load(self::utf16(self::LAUGHS)));
	}

	public function testInternalSubsetWithoutEntityIsRejected(): void {
		$xml = '<?xml version="1.0"?><!DOCTYPE a [<!ELEMENT a ANY>]><a/>';
		$this->assertNull(XmlUtil::load($xml));
		$this->expectException(EditorException::class);
		EditorUtil::loadXml($xml);
	}

	public function testLegitimateDoctypeStillParses(): void {
		$xhtml = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">'
			. '<html xmlns="http://www.w3.org/1999/xhtml"><body><p>ok</p></body></html>';
		$this->assertNotNull(XmlUtil::load($xhtml));
		$this->assertSame('html', EditorUtil::loadXml($xhtml)->documentElement?->localName);
		$u16 = self::utf16('<?xml version="1.0" encoding="UTF-8"?><a>x</a>');
		$this->assertNotNull(XmlUtil::load($u16));
		$this->assertSame('a', EditorUtil::loadXml($u16)->documentElement?->localName);
	}

	public function testEpubWithBillionLaughsOpfIsRefused(): void {
		$path = Fixtures::temp('.epub');
		$z = new \ZipArchive();
		$z->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		$z->addFromString('mimetype', 'application/epub+zip');
		$z->addFromString('META-INF/container.xml', '<?xml version="1.0"?><container xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
		$z->addFromString('content.opf', self::LAUGHS);
		$z->close();
		$this->expectException(UnsafeArchiveException::class);
		(new EpubExtractor())->extract($path);
	}

	public function testBillionLaughsContainerXmlIsIgnored(): void {
		$path = Fixtures::temp('.epub');
		$z = new \ZipArchive();
		$z->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		$z->addFromString('META-INF/container.xml', str_replace('<package', '<container', self::LAUGHS));
		$z->addFromString('content.opf', '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Fallback</dc:title></metadata></package>');
		$z->close();
		// the poisoned container.xml is not parsed; the first .opf is used instead
		$this->assertSame('Fallback', (new EpubExtractor())->extract($path)->title);
	}

	// ---- B3 ---------------------------------------------------------------------------------

	public function testOversizedFb2IsRefused(): void {
		$f = Fixtures::temp('.fb2');
		$fh = fopen($f, 'wb');
		$this->assertNotFalse($fh);
		fwrite($fh, '<FictionBook/>');
		ftruncate($fh, 50 * 1024 * 1024 + 1);
		fclose($fh);
		$this->expectException(UnsafeArchiveException::class);
		(new Fb2Extractor())->extract($f);
	}

	public function testOversizedFb2CoverBinaryIsSkipped(): void {
		$f = Fixtures::temp('.fb2');
		$png = (string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
		$tpl = '<?xml version="1.0" encoding="UTF-8"?><FictionBook xmlns="http://www.gribuser.ru/xml/fictionbook/2.0" xmlns:l="http://www.w3.org/1999/xlink"><description><title-info><book-title>T</book-title><coverpage><image l:href="#c"/></coverpage></title-info></description><binary id="c" content-type="image/png">%s</binary></FictionBook>';
		file_put_contents($f, sprintf($tpl, base64_encode($png)));
		$this->assertSame('image/png', (new Fb2Extractor())->extract($f)->coverMime);

		// valid PNG signature followed by > 20 MB of data
		file_put_contents($f, sprintf($tpl, base64_encode($png . str_repeat("\0", 21 * 1024 * 1024))));
		$m = (new Fb2Extractor())->extract($f);
		$this->assertSame('T', $m->title);
		$this->assertNull($m->coverData);
	}

	// ---- B4 ---------------------------------------------------------------------------------

	public function testTruncatedExthParsesWithoutWarnings(): void {
		$src = (string)file_get_contents(Fixtures::path('book.mobi'));
		$o = unpack('N', substr($src, 78, 4));
		$this->assertIsArray($o);
		$start = $o[1];
		$h = unpack('N', substr($src, $start + 20, 4));
		$this->assertIsArray($h);
		$exthPos = 16 + $h[1];
		$this->assertSame('EXTH', substr($src, $start + $exthPos, 4));

		set_error_handler(static function (int $no, string $str): bool {
			throw new \ErrorException($str, 0, $no);
		});
		try {
			// cut inside the EXTH header, inside a record header and inside a record value
			foreach ([6, 10, 12, 16, 22, 30, 40] as $cut) {
				$data = substr($src, 0, $start + $exthPos + $cut);
				$data = substr_replace($data, pack('n', 1), 76, 2); // one record: record 0 runs to the end of the file
				$f = Fixtures::temp('.mobi');
				file_put_contents($f, $data);
				$this->assertInstanceOf(BookMetadata::class, (new MobiExtractor())->extract($f));
			}
		} finally {
			restore_error_handler();
		}
	}

	// ---- B5 ---------------------------------------------------------------------------------

	public function testSafeZipEntryCountCap(): void {
		$path = Fixtures::temp('.zip');
		$z = new \ZipArchive();
		$z->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		for ($i = 0; $i < 5; $i++) {
			$z->addFromString("f$i.txt", 'x');
		}
		$z->close();
		$zip = SafeZip::open($path, 5);
		$this->assertCount(5, $zip->names());
		$zip->close();
		$this->expectException(UnsafeArchiveException::class);
		SafeZip::open($path, 4);
	}

	public function testSafeZipDefaultCapIsOneHundredThousand(): void {
		$this->assertSame(100000, SafeZip::MAX_ENTRIES);
	}

	// ---- B7 ---------------------------------------------------------------------------------

	public function testReferencesOfNormalContentStillWork(): void {
		$refs = EditorUtil::references('OEBPS/text/a.xhtml', '<a href="../img/x.png#f">x</a><link href="s.css"/><p style="background:url(\'b.jpg\')"></p><a href="https://e.org/">e</a>');
		sort($refs);
		$this->assertSame(['OEBPS/img/x.png', 'OEBPS/text/b.jpg', 'OEBPS/text/s.css'], $refs);
	}

	public function testReferencesRefusesOversizedInputInsteadOfReturningNothing(): void {
		$this->expectException(EditorException::class);
		EditorUtil::references('a.xhtml', str_repeat(' ', EditorUtil::MAX_REGEX_INPUT + 1));
	}

	public function testReferencesFailsLoudlyOnRegexError(): void {
		$prevJit = ini_set('pcre.jit', '0');
		$prevLimit = ini_set('pcre.backtrack_limit', '10');
		try {
			$this->expectException(EditorException::class);
			EditorUtil::references('a.xhtml', '<a href="' . str_repeat('a', 500) . '">x</a>');
		} finally {
			ini_set('pcre.backtrack_limit', $prevLimit === false ? '1000000' : $prevLimit);
			ini_set('pcre.jit', $prevJit === false ? '1' : $prevJit);
		}
	}
}
