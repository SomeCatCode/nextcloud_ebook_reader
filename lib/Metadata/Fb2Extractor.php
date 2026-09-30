<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

/** FictionBook 2 (.fb2) and zipped FictionBook (.fb2.zip, format "fbz"). */
class Fb2Extractor implements ExtractorInterface {
	private const MAX_FB2_SIZE = 200 * 1024 * 1024;

	/** @var ?array<string, array{de?: string, en?: string}> */
	private ?array $genreMap = null;

	#[\Override]
	public function supports(string $format): bool {
		return $format === 'fb2' || $format === 'fbz';
	}

	#[\Override]
	public function extract(string $localPath): BookMetadata {
		$xml = $this->loadXml($localPath);
		$doc = XmlUtil::load($xml);
		if ($doc === null) {
			throw new \RuntimeException('FB2 is not well-formed XML');
		}
		$xp = new \DOMXPath($doc);
		$ti = "//*[local-name()='description']/*[local-name()='title-info']";
		$pi = "//*[local-name()='description']/*[local-name()='publish-info']";

		$title = XmlUtil::text($xp, "$ti/*[local-name()='book-title']");

		$authors = [];
		$authorNodes = $xp->query("$ti/*[local-name()='author']");
		foreach ($authorNodes === false ? [] : $authorNodes as $a) {
			$parts = [];
			foreach (['first-name', 'middle-name', 'last-name'] as $p) {
				$t = XmlUtil::text($xp, "*[local-name()='$p']", $a);
				if ($t !== null) {
					$parts[] = $t;
				}
			}
			$name = $parts !== [] ? implode(' ', $parts) : XmlUtil::text($xp, "*[local-name()='nickname']", $a);
			if ($name !== null && !in_array($name, $authors, true)) {
				$authors[] = $name;
			}
		}

		$series = null;
		$seriesIndex = null;
		$seq = $xp->query("$ti/*[local-name()='sequence']")->item(0);
		if ($seq instanceof \DOMElement) {
			$series = XmlUtil::clean($seq->getAttribute('name'));
			$num = trim($seq->getAttribute('number'));
			$seriesIndex = $num !== '' && is_numeric($num) ? (float)$num : null;
		}

		$description = null;
		$ann = $xp->query("$ti/*[local-name()='annotation']")->item(0);
		if ($ann !== null) {
			$html = $this->annotationHtml($ann);
			$clean = HtmlSanitizer::sanitize($html);
			$description = $clean === '' ? null : $clean;
		}

		$language = XmlUtil::text($xp, "$ti/*[local-name()='lang']");
		$publisher = XmlUtil::text($xp, "$pi/*[local-name()='publisher']");
		$isbn = XmlUtil::text($xp, "$pi/*[local-name()='isbn']");
		if ($isbn !== null) {
			$isbn = strtoupper(str_replace(['-', ' '], '', $isbn));
			$isbn = substr($isbn, 0, 32);
		}
		$publishedAt = null;
		$year = XmlUtil::text($xp, "$pi/*[local-name()='year']");
		if ($year !== null && preg_match('/^\d{4}/', $year, $m) === 1) {
			$publishedAt = $m[0];
		} else {
			$dateNode = $xp->query("$ti/*[local-name()='date']")->item(0);
			$dv = $dateNode instanceof \DOMElement ? trim($dateNode->getAttribute('value')) : '';
			if ($dv === '' && $dateNode !== null) {
				$dv = trim($dateNode->textContent);
			}
			if (preg_match('/^\d{4}(-\d{2}(-\d{2})?)?/', $dv, $m) === 1) {
				$publishedAt = $m[0];
			}
		}

		$genres = [];
		$unknown = [];
		foreach (XmlUtil::texts($xp, "$ti/*[local-name()='genre']") as $code) {
			$label = $this->genreLabel($code);
			if ($label !== null && !in_array($label, $genres, true)) {
				$genres[] = $label;
			} elseif ($label === null && !in_array(strtolower(trim($code)), ["", "other", "unrecognised"], true)) {
				$unknown[] = $code; // not in the FB2 vocabulary: keep as tag
			}
		}
		$tags = XmlUtil::splitList(XmlUtil::text($xp, "$ti/*[local-name()='keywords']"));
		foreach ($unknown as $u) {
			if (!in_array($u, $tags, true)) {
				$tags[] = $u;
			}
		}

		[$coverData, $coverMime] = $this->cover($xp, $ti);

		return new BookMetadata(
			title: $title,
			authors: $authors,
			series: $series,
			seriesIndex: $seriesIndex,
			description: $description,
			language: $language,
			publisher: $publisher,
			isbn: $isbn === '' ? null : $isbn,
			publishedAt: $publishedAt,
			genres: $genres,
			tags: $tags,
			coverData: $coverData,
			coverMime: $coverMime,
		);
	}

	private function loadXml(string $path): string {
		$head = @file_get_contents($path, false, null, 0, 4);
		if ($head !== false && str_starts_with($head, 'PK')) {
			$zip = SafeZip::open($path);
			try {
				foreach ($zip->names() as $name) {
					if (str_ends_with(strtolower($name), '.fb2')) {
						$data = $zip->read($name, self::MAX_FB2_SIZE);
						if ($data !== null) {
							return $this->toUtf8($data);
						}
					}
				}
			} finally {
				$zip->close();
			}
			throw new \RuntimeException('No .fb2 entry in archive');
		}
		$size = @filesize($path);
		if ($size === false || $size > self::MAX_FB2_SIZE) {
			throw new UnsafeArchiveException('FB2 file too large');
		}
		$data = file_get_contents($path);
		if ($data === false) {
			throw new \RuntimeException('Cannot read file');
		}
		return $this->toUtf8($data);
	}

	/** FB2 files are often windows-1251; libxml handles that via the XML declaration, so only fix BOM. */
	private function toUtf8(string $data): string {
		return $data;
	}

	private function annotationHtml(\DOMNode $node): string {
		$out = '';
		foreach ($node->childNodes as $c) {
			if ($c instanceof \DOMText) {
				$out .= htmlspecialchars($c->data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
				continue;
			}
			if (!$c instanceof \DOMElement) {
				continue;
			}
			$inner = $this->annotationHtml($c);
			switch ($c->localName) {
				case 'p':
				case 'subtitle':
				case 'cite':
				case 'poem':
				case 'stanza':
					$out .= '<p>' . $inner . '</p>';
					break;
				case 'v':
					$out .= $inner . '<br>';
					break;
				case 'emphasis':
					$out .= '<em>' . $inner . '</em>';
					break;
				case 'strong':
					$out .= '<strong>' . $inner . '</strong>';
					break;
				case 'empty-line':
					$out .= '<br>';
					break;
				default:
					$out .= $inner;
			}
		}
		return $out;
	}

	private function genreLabel(string $code): ?string {
		$code = strtolower(trim($code));
		if ($code === '' || $code === 'other' || $code === 'unrecognised') {
			return null;
		}
		if ($this->genreMap === null) {
			$file = dirname(__DIR__, 2) . '/resources/fb2-genres.json';
			$data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
			$this->genreMap = is_array($data) ? $data : [];
		}
		$entry = $this->genreMap[$code] ?? null;
		if (is_array($entry)) {
			return $entry['de'] ?? $entry['en'] ?? null;
		}
		return null;
	}

	/** @return array{0: ?string, 1: ?string} */
	private function cover(\DOMXPath $xp, string $ti): array {
		$img = $xp->query("$ti/*[local-name()='coverpage']/*[local-name()='image']")->item(0);
		if (!$img instanceof \DOMElement) {
			return [null, null];
		}
		$href = '';
		foreach ($img->attributes ?? [] as $attr) {
			if ($attr->localName === 'href') {
				$href = (string)$attr->nodeValue;
				break;
			}
		}
		$id = ltrim($href, '#');
		if ($id === '' || preg_match('/^[A-Za-z0-9_.:-]+$/', $id) !== 1) {
			return [null, null];
		}
		$bin = $xp->query("//*[local-name()='binary'][@id='$id']")->item(0);
		if ($bin === null) {
			return [null, null];
		}
		$data = base64_decode((string)preg_replace('/\s+/', '', $bin->textContent), true);
		if ($data === false || $data === '') {
			return [null, null];
		}
		$mime = ImageUtil::mime($data);
		return $mime === null ? [null, null] : [$data, $mime];
	}
}
