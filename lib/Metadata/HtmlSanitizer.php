<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

/**
 * Tiny DOM allowlist sanitiser for book descriptions.
 * Allowed: p br b i em strong ul ol li (no attributes). Block-ish elements (div, h1-h6) become <p>,
 * script/style are dropped with their content, all other elements are unwrapped.
 */
final class HtmlSanitizer {
	private const ALLOWED = ['p', 'br', 'b', 'i', 'em', 'strong', 'ul', 'ol', 'li'];
	private const TO_P = ['div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote'];
	private const DROP = ['script', 'style', 'head', 'title', 'object', 'iframe', 'template'];

	/** Returns sanitised HTML ('' if nothing is left). Plain text is turned into paragraphs. */
	public static function sanitize(string $html): string {
		$html = trim($html);
		if ($html === '') {
			return '';
		}
		if (!str_contains($html, '<')) {
			return self::fromPlainText(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		}
		$prev = libxml_use_internal_errors(true);
		try {
			$doc = new \DOMDocument();
			$ok = $doc->loadHTML(
				'<?xml encoding="utf-8"?><body>' . $html . '</body>',
				LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_HTML_NODEFDTD
			);
			if (!$ok) {
				return self::fromPlainText(strip_tags($html));
			}
			$body = $doc->getElementsByTagName('body')->item(0);
			if ($body === null) {
				return '';
			}
			$out = self::children($body);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($prev);
		}
		$out = preg_replace('#<p>\s*</p>#u', '', $out) ?? $out;
		return trim($out);
	}

	/** Plain text version (tags stripped, block ends become line breaks). */
	public static function toText(string $html): string {
		$s = preg_replace('#</p>|<br\s*/?>|</li>#i', "\n", $html) ?? $html;
		$s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return trim($s);
	}

	private static function fromPlainText(string $text): string {
		$paras = preg_split('/\R{2,}/u', trim($text)) ?: [];
		$out = '';
		foreach ($paras as $p) {
			$p = trim($p);
			if ($p === '') {
				continue;
			}
			$lines = array_map(
				static fn (string $l): string => htmlspecialchars(trim($l), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
				preg_split('/\R/u', $p) ?: []
			);
			$out .= '<p>' . implode('<br>', $lines) . '</p>';
		}
		return $out;
	}

	private static function children(\DOMNode $node): string {
		$out = '';
		foreach ($node->childNodes as $child) {
			$out .= self::node($child);
		}
		return $out;
	}

	private static function node(\DOMNode $node): string {
		if ($node instanceof \DOMText) {
			return htmlspecialchars($node->data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		}
		if (!$node instanceof \DOMElement) {
			return '';
		}
		$name = strtolower($node->localName ?? $node->tagName);
		if (in_array($name, self::DROP, true)) {
			return '';
		}
		if ($name === 'br') {
			return '<br>';
		}
		if (in_array($name, self::TO_P, true)) {
			$name = 'p';
		}
		$inner = self::children($node);
		if (in_array($name, self::ALLOWED, true)) {
			return '<' . $name . '>' . $inner . '</' . $name . '>';
		}
		return $inner;
	}
}
