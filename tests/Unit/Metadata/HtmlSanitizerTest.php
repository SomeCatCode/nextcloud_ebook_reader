<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Metadata\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase {
	public function testKeepsAllowlist(): void {
		$in = '<p>a <b>b</b> <i>i</i> <em>e</em> <strong>s</strong><br/>x</p><ul><li>1</li></ul><ol><li>2</li></ol>';
		$this->assertSame('<p>a <b>b</b> <i>i</i> <em>e</em> <strong>s</strong><br>x</p><ul><li>1</li></ul><ol><li>2</li></ol>', HtmlSanitizer::sanitize($in));
	}

	public function testStripsAttributesAndScripts(): void {
		$out = HtmlSanitizer::sanitize('<p onclick="x()" style="color:red">hi</p><script>alert(1)</script><style>p{}</style><iframe src="x"></iframe>');
		$this->assertSame('<p>hi</p>', $out);
	}

	public function testUnwrapsLinksAndDropsImages(): void {
		$this->assertSame('<p>see <b>this</b> link</p>', HtmlSanitizer::sanitize('<p>see <b>this</b> <a href="javascript:alert(1)">link</a></p>'));
		$this->assertSame('x', HtmlSanitizer::sanitize('<img src="x" onerror="y()"><span>x</span>'));
	}

	public function testDivAndHeadingsBecomeParagraphs(): void {
		$this->assertSame('<p>T</p><p>body</p>', HtmlSanitizer::sanitize('<h2>T</h2><div>body</div>'));
	}

	public function testPlainTextBecomesParagraphs(): void {
		$this->assertSame('<p>a<br>b</p><p>c &amp; d</p>', HtmlSanitizer::sanitize("a\nb\n\nc & d"));
	}

	public function testEscapedMarkupInTextStaysText(): void {
		$this->assertSame('<p>1 &lt; 2</p>', HtmlSanitizer::sanitize('<p>1 &lt; 2</p>'));
	}

	public function testEmpty(): void {
		$this->assertSame('', HtmlSanitizer::sanitize('  '));
		$this->assertSame('', HtmlSanitizer::sanitize('<p> </p>'));
		$this->assertSame('', HtmlSanitizer::sanitize('<script>x</script>'));
	}

	public function testToText(): void {
		$this->assertSame("a\nb", HtmlSanitizer::toText('<p>a</p><p>b</p>'));
	}
}
