<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Http;

use OCA\EbookReader\Http\OpdsDownloadResponse;
use OCP\AppFramework\Http\IOutput;
use PHPUnit\Framework\TestCase;

class OpdsDownloadResponseTest extends TestCase {
	private const DATA = '0123456789abcdefghij';

	private function response(?string $range): OpdsDownloadResponse {
		$h = fopen('php://memory', 'w+b');
		fwrite($h, self::DATA);
		rewind($h);
		return new OpdsDownloadResponse($h, strlen(self::DATA), 'book.epub', 'application/epub+zip', $range);
	}

	private function body(OpdsDownloadResponse $r): string {
		$out = '';
		$io = $this->createMock(IOutput::class);
		$io->method('setOutput')->willReturnCallback(static function (string $d) use (&$out): void {
			$out .= $d;
		});
		$r->callback($io);
		return $out;
	}

	public function testFullDownload(): void {
		$r = $this->response(null);
		$this->assertSame(200, $r->getStatus());
		$this->assertSame('20', $r->getOwnHeaders()['Content-Length']);
		$this->assertSame('bytes', $r->getOwnHeaders()['Accept-Ranges']);
		$this->assertSame(self::DATA, $this->body($r));
	}

	public function testRanges(): void {
		$r = $this->response('bytes=5-9');
		$this->assertSame(206, $r->getStatus());
		$this->assertSame('bytes 5-9/20', $r->getOwnHeaders()['Content-Range']);
		$this->assertSame('5', $r->getOwnHeaders()['Content-Length']);
		$this->assertSame('56789', $this->body($r));
		$this->assertSame('hij', $this->body($this->response('bytes=-3')));
		$this->assertSame('fghij', $this->body($this->response('bytes=15-')));
		$this->assertSame('ghij', $this->body($this->response('bytes=16-999')));
	}

	public function testUnsatisfiableAndMalformedRanges(): void {
		$r = $this->response('bytes=20-30');
		$this->assertSame(416, $r->getStatus());
		$this->assertSame('bytes */20', $r->getOwnHeaders()['Content-Range']);
		$this->assertSame('', $this->body($r));
		// malformed or multi-range requests get the whole file
		foreach (['bytes=a-b', 'items=1-2', 'bytes=1-2,5-6', 'bytes=9-3'] as $header) {
			$full = $this->response($header);
			$this->assertSame(200, $full->getStatus(), $header);
			$this->assertSame(self::DATA, $this->body($full), $header);
		}
	}

	public function testContentDisposition(): void {
		$header = OpdsDownloadResponse::contentDisposition("B\u{fc}ch \"x\"; y\r\n/..\\.epub");
		$this->assertStringNotContainsString("\r", $header);
		$this->assertStringNotContainsString("\n", $header);
		$this->assertStringContainsString('filename="B_ch _x_; y...epub"', $header);
		$this->assertStringContainsString("filename*=UTF-8''B%C3%BCch%20%22x%22%3B%20y...epub", $header);
		$this->assertStringStartsWith('attachment; filename="book"', OpdsDownloadResponse::contentDisposition(''));
	}
}
