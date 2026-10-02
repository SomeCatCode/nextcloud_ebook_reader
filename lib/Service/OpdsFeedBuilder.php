<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

/**
 * Serialises OPDS 1.2 (Atom) feeds and the OpenSearch description. All text goes through XMLWriter (escaping) after invalid
 * XML characters were removed; nothing is concatenated into markup.
 *
 * @psalm-type OpdsLink = array{rel?: string, href: string, type?: string, title?: string, length?: int}
 * @psalm-type OpdsEntry = array{
 *     id: string,
 *     title: string,
 *     updated: int,
 *     authors?: list<string>,
 *     content?: string,
 *     summary?: string,
 *     language?: string,
 *     publisher?: string,
 *     issued?: string,
 *     identifier?: string,
 *     categories?: list<string>,
 *     links: list<OpdsLink>,
 * }
 * @psalm-type OpdsFeed = array{
 *     id: string,
 *     title: string,
 *     updated: int,
 *     kind: string,
 *     selfUrl: string,
 *     startUrl: string,
 *     upUrl?: string,
 *     searchUrl?: string,
 *     links?: list<OpdsLink>,
 *     total?: int,
 *     perPage?: int,
 *     startIndex?: int,
 *     entries: list<OpdsEntry>,
 * }
 */
class OpdsFeedBuilder {
	public const KIND_NAVIGATION = 'navigation';
	public const KIND_ACQUISITION = 'acquisition';

	public const REL_ACQUISITION = 'http://opds-spec.org/acquisition';
	public const REL_IMAGE = 'http://opds-spec.org/image';
	public const REL_THUMBNAIL = 'http://opds-spec.org/image/thumbnail';
	public const REL_SUBSECTION = 'subsection';

	private const NS_ATOM = 'http://www.w3.org/2005/Atom';
	private const NS_DC = 'http://purl.org/dc/elements/1.1/';
	private const NS_DCTERMS = 'http://purl.org/dc/terms/';
	private const NS_OPDS = 'http://opds-spec.org/2010/catalog';
	private const NS_OPENSEARCH = 'http://a9.com/-/spec/opensearch/1.1/';

	/** MIME types the OPDS clients expect, by library format. */
	public const MIME_TYPES = [
		'epub' => 'application/epub+zip',
		'mobi' => 'application/x-mobipocket-ebook',
		'azw3' => 'application/vnd.amazon.ebook',
		'fb2' => 'application/x-fictionbook+xml',
		'fbz' => 'application/x-zip-compressed-fb2',
		'cbz' => 'application/vnd.comicbook+zip',
		'cbr' => 'application/vnd.comicbook-rar',
		'cb7' => 'application/x-cb7',
		'cbt' => 'application/x-cbt',
	];

	public static function mimeFor(string $format): string {
		return self::MIME_TYPES[strtolower($format)] ?? 'application/octet-stream';
	}

	/** Content-Type of a feed of the given kind. */
	public static function contentType(string $kind): string {
		return 'application/atom+xml;profile=opds-catalog;kind=' . ($kind === self::KIND_ACQUISITION ? self::KIND_ACQUISITION : self::KIND_NAVIGATION);
	}

	/** Drops what XML 1.0 cannot represent (control characters, invalid UTF-8, non-characters). */
	public static function clean(string $text): string {
		$text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
		return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
	}

	public static function timestamp(int $unix): string {
		return gmdate('Y-m-d\TH:i:s\Z', max(0, $unix));
	}

	/** @param OpdsFeed $feed */
	public function build(array $feed): string {
		$w = $this->writer();
		$w->startElement('feed');
		$w->writeAttribute('xmlns', self::NS_ATOM);
		$w->writeAttribute('xmlns:dc', self::NS_DC);
		$w->writeAttribute('xmlns:dcterms', self::NS_DCTERMS);
		$w->writeAttribute('xmlns:opds', self::NS_OPDS);
		$w->writeAttribute('xmlns:opensearch', self::NS_OPENSEARCH);

		$this->text($w, 'id', $feed['id']);
		$this->text($w, 'title', $feed['title']);
		$this->text($w, 'updated', self::timestamp($feed['updated']));
		$w->startElement('author');
		$this->text($w, 'name', 'Nextcloud E-Book Reader');
		$w->endElement();

		$type = self::contentType($feed['kind']);
		$this->link($w, ['rel' => 'self', 'href' => $feed['selfUrl'], 'type' => $type]);
		$this->link($w, ['rel' => 'start', 'href' => $feed['startUrl'], 'type' => self::contentType(self::KIND_NAVIGATION)]);
		if (isset($feed['upUrl'])) {
			$this->link($w, ['rel' => 'up', 'href' => $feed['upUrl'], 'type' => self::contentType(self::KIND_NAVIGATION)]);
		}
		if (isset($feed['searchUrl'])) {
			$this->link($w, ['rel' => 'search', 'href' => $feed['searchUrl'], 'type' => 'application/opensearchdescription+xml', 'title' => 'Search']);
		}
		foreach ($feed['links'] ?? [] as $link) {
			$this->link($w, $link);
		}
		if (isset($feed['total'])) {
			$this->text($w, 'opensearch:totalResults', (string)$feed['total']);
			$this->text($w, 'opensearch:itemsPerPage', (string)($feed['perPage'] ?? count($feed['entries'])));
			$this->text($w, 'opensearch:startIndex', (string)($feed['startIndex'] ?? 1));
		}

		foreach ($feed['entries'] as $entry) {
			$this->entry($w, $entry);
		}

		$w->endElement();
		$w->endDocument();
		return $w->outputMemory();
	}

	/** OpenSearch description document; $template contains the literal {searchTerms}. */
	public function buildOpenSearch(string $shortName, string $description, string $template): string {
		$w = $this->writer();
		$w->startElement('OpenSearchDescription');
		$w->writeAttribute('xmlns', self::NS_OPENSEARCH);
		$this->text($w, 'ShortName', $shortName);
		$this->text($w, 'Description', $description);
		$this->text($w, 'InputEncoding', 'UTF-8');
		$this->text($w, 'OutputEncoding', 'UTF-8');
		$w->startElement('Url');
		$w->writeAttribute('type', self::contentType(self::KIND_ACQUISITION));
		$w->writeAttribute('template', self::clean($template));
		$w->endElement();
		$w->endElement();
		$w->endDocument();
		return $w->outputMemory();
	}

	/** @param OpdsEntry $entry */
	private function entry(\XMLWriter $w, array $entry): void {
		$w->startElement('entry');
		$this->text($w, 'title', $entry['title']);
		$this->text($w, 'id', $entry['id']);
		$this->text($w, 'updated', self::timestamp($entry['updated']));
		foreach ($entry['authors'] ?? [] as $author) {
			if (trim($author) === '') {
				continue;
			}
			$w->startElement('author');
			$this->text($w, 'name', $author);
			$w->endElement();
		}
		if (isset($entry['language']) && trim($entry['language']) !== '') {
			$this->text($w, 'dc:language', $entry['language']);
		}
		if (isset($entry['publisher']) && trim($entry['publisher']) !== '') {
			$this->text($w, 'dc:publisher', $entry['publisher']);
		}
		if (isset($entry['issued']) && trim($entry['issued']) !== '') {
			$this->text($w, 'dcterms:issued', $entry['issued']);
		}
		if (isset($entry['identifier']) && trim($entry['identifier']) !== '') {
			$this->text($w, 'dc:identifier', $entry['identifier']);
		}
		foreach ($entry['categories'] ?? [] as $category) {
			if (trim($category) === '') {
				continue;
			}
			$w->startElement('category');
			$w->writeAttribute('term', self::clean($category));
			$w->writeAttribute('label', self::clean($category));
			$w->endElement();
		}
		if (isset($entry['summary']) && trim($entry['summary']) !== '') {
			$w->startElement('summary');
			$w->writeAttribute('type', 'text');
			$w->text(self::clean($entry['summary']));
			$w->endElement();
		}
		if (isset($entry['content']) && trim($entry['content']) !== '') {
			$w->startElement('content');
			$w->writeAttribute('type', 'text');
			$w->text(self::clean($entry['content']));
			$w->endElement();
		}
		foreach ($entry['links'] as $link) {
			$this->link($w, $link);
		}
		$w->endElement();
	}

	/** @param OpdsLink $link */
	private function link(\XMLWriter $w, array $link): void {
		$w->startElement('link');
		if (isset($link['rel'])) {
			$w->writeAttribute('rel', self::clean($link['rel']));
		}
		$w->writeAttribute('href', self::clean($link['href']));
		if (isset($link['type'])) {
			$w->writeAttribute('type', self::clean($link['type']));
		}
		if (isset($link['title'])) {
			$w->writeAttribute('title', self::clean($link['title']));
		}
		if (isset($link['length'])) {
			$w->writeAttribute('length', (string)max(0, $link['length']));
		}
		$w->endElement();
	}

	private function text(\XMLWriter $w, string $name, string $value): void {
		$w->startElement($name);
		$w->text(self::clean($value));
		$w->endElement();
	}

	private function writer(): \XMLWriter {
		$w = new \XMLWriter();
		$w->openMemory();
		$w->setIndent(true);
		$w->setIndentString("\t");
		$w->startDocument('1.0', 'UTF-8');
		return $w;
	}
}
