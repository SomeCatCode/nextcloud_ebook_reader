<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

/** EPUB 2 and 3: container.xml -> OPF. */
class EpubExtractor implements ExtractorInterface {
	#[\Override]
	public function supports(string $format): bool {
		return $format === 'epub';
	}

	#[\Override]
	public function extract(string $localPath): BookMetadata {
		$zip = SafeZip::open($localPath);
		try {
			return $this->read($zip);
		} finally {
			$zip->close();
		}
	}

	private function read(SafeZip $zip): BookMetadata {
		$opfPath = $this->findOpfPath($zip);
		$opf = $opfPath === null ? null : XmlUtil::load($zip->read($opfPath) ?? '');
		if ($opfPath === null || $opf === null) {
			throw new UnsafeArchiveException('EPUB has no readable OPF package document');
		}
		$xp = new \DOMXPath($opf);
		$dir = str_contains($opfPath, '/') ? substr($opfPath, 0, (int)strrpos($opfPath, '/')) : '';
		$md = "//*[local-name()='metadata']";

		$title = XmlUtil::text($xp, "$md/*[local-name()='title']");

		$authors = $this->authors($xp, $md);
		$descNode = $xp->query("$md/*[local-name()='description']")->item(0);
		$description = null;
		if ($descNode !== null) {
			$raw = $descNode->childNodes->length > 0 && $this->hasElementChild($descNode)
				? XmlUtil::innerXml($descNode)
				: $descNode->textContent;
			$clean = HtmlSanitizer::sanitize($raw);
			$description = $clean === '' ? null : $clean;
		}
		$language = XmlUtil::text($xp, "$md/*[local-name()='language']");
		$publisher = XmlUtil::text($xp, "$md/*[local-name()='publisher']");
		$date = XmlUtil::text($xp, "$md/*[local-name()='date']");
		$publishedAt = null;
		if ($date !== null && preg_match('/^\d{4}(-\d{2}(-\d{2})?)?/', $date, $m) === 1) {
			$publishedAt = $m[0];
		}
		$isbn = $this->isbn($xp, $md);
		[$series, $seriesIndex] = $this->series($xp, $md);
		$subjects = XmlUtil::texts($xp, "$md/*[local-name()='subject']");
		$subjects = array_values(array_unique($subjects));

		[$coverData, $coverMime] = $this->cover($zip, $xp, $dir);

		return new BookMetadata(
			title: $title,
			authors: $authors,
			series: $series,
			seriesIndex: $seriesIndex,
			description: $description,
			language: $language,
			publisher: $publisher,
			isbn: $isbn,
			publishedAt: $publishedAt,
			subjects: $subjects,
			coverData: $coverData,
			coverMime: $coverMime,
		);
	}

	private function findOpfPath(SafeZip $zip): ?string {
		$container = XmlUtil::load($zip->read('META-INF/container.xml') ?? '');
		if ($container !== null) {
			$xp = new \DOMXPath($container);
			$nodes = $xp->query("//*[local-name()='rootfile']/@full-path");
			if ($nodes !== false && $nodes->length > 0) {
				$p = SafeZip::resolve('', (string)$nodes->item(0)?->nodeValue);
				if ($p !== null && $zip->has($p)) {
					return $zip->find($p);
				}
			}
		}
		// fallback: first .opf in the archive
		foreach ($zip->names() as $name) {
			if (str_ends_with(strtolower($name), '.opf')) {
				return $name;
			}
		}
		return null;
	}

	private function hasElementChild(\DOMNode $node): bool {
		foreach ($node->childNodes as $c) {
			if ($c instanceof \DOMElement) {
				return true;
			}
		}
		return false;
	}

	/** @return list<string> */
	private function authors(\DOMXPath $xp, string $md): array {
		$all = [];
		$aut = [];
		$nodes = $xp->query("$md/*[local-name()='creator']");
		foreach ($nodes === false ? [] : $nodes as $n) {
			if (!$n instanceof \DOMElement) {
				continue;
			}
			$name = XmlUtil::clean($n->textContent);
			if ($name === null) {
				continue;
			}
			$role = strtolower($n->getAttributeNS('http://www.idpf.org/2007/opf', 'role'));
			if ($role === '') {
				$role = strtolower($n->getAttribute('opf:role'));
			}
			if ($role === '' && preg_match('/^[A-Za-z0-9_.:-]+$/', $n->getAttribute('id')) === 1) {
				$refines = XmlUtil::text($xp, "$md/*[local-name()='meta'][@refines='#" . $n->getAttribute('id') . "'][@property='role']");
				$role = strtolower($refines ?? '');
			}
			$all[] = $name;
			if ($role === '' || $role === 'aut') {
				$aut[] = $name;
			}
		}
		$out = $aut !== [] ? $aut : $all;
		return array_values(array_unique($out));
	}

	private function isbn(\DOMXPath $xp, string $md): ?string {
		$nodes = $xp->query("$md/*[local-name()='identifier']");
		$fallback = null;
		foreach ($nodes === false ? [] : $nodes as $n) {
			if (!$n instanceof \DOMElement) {
				continue;
			}
			$value = trim($n->textContent);
			$scheme = strtolower($n->getAttributeNS('http://www.idpf.org/2007/opf', 'scheme'));
			if ($scheme === '') {
				$scheme = strtolower($n->getAttribute('opf:scheme'));
			}
			if ($scheme === 'isbn') {
				return $this->normaliseIsbn($value);
			}
			if (stripos($value, 'urn:isbn:') === 0) {
				return $this->normaliseIsbn(substr($value, 9));
			}
			if ($fallback === null) {
				$c = str_replace(['-', ' '], '', $value);
				if (preg_match('/^(97[89])?\d{9}[\dXx]$/', $c) === 1) {
					$fallback = strtoupper($c);
				}
			}
		}
		return $fallback;
	}

	private function normaliseIsbn(string $v): ?string {
		$v = strtoupper(str_replace(['-', ' '], '', trim($v)));
		return $v === '' ? null : substr($v, 0, 32);
	}

	/** @return array{0: ?string, 1: ?float} */
	private function series(\DOMXPath $xp, string $md): array {
		$metaQ = "$md/*[local-name()='meta']";
		$series = XmlUtil::text($xp, $metaQ . "[@name='calibre:series']/@content");
		$index = XmlUtil::text($xp, $metaQ . "[@name='calibre:series_index']/@content");
		if ($series !== null) {
			return [$series, $index !== null && is_numeric($index) ? (float)$index : null];
		}
		// EPUB 3 belongs-to-collection
		$nodes = $xp->query($metaQ . "[@property='belongs-to-collection']");
		foreach ($nodes === false ? [] : $nodes as $n) {
			if (!$n instanceof \DOMElement) {
				continue;
			}
			$name = XmlUtil::clean($n->textContent);
			if ($name === null) {
				continue;
			}
			$id = $n->getAttribute('id');
			if (preg_match('/^[A-Za-z0-9_.:-]+$/', $id) !== 1) {
				$id = '';
			}
			$pos = null;
			if ($id !== '') {
				$type = XmlUtil::text($xp, $metaQ . "[@refines='#$id'][@property='collection-type']");
				if ($type !== null && strtolower($type) !== 'series') {
					continue;
				}
				$posText = XmlUtil::text($xp, $metaQ . "[@refines='#$id'][@property='group-position']");
				if ($posText !== null && is_numeric($posText)) {
					$pos = (float)$posText;
				}
			}
			return [$name, $pos];
		}
		return [null, null];
	}

	/** @return array{0: ?string, 1: ?string} */
	private function cover(SafeZip $zip, \DOMXPath $xp, string $dir): array {
		/** @var array<string, array{href: string, type: string, props: string}> $items */
		$items = [];
		$nodes = $xp->query("//*[local-name()='manifest']/*[local-name()='item']");
		foreach ($nodes === false ? [] : $nodes as $n) {
			if ($n instanceof \DOMElement && $n->getAttribute('id') !== '') {
				$items[$n->getAttribute('id')] = [
					'href' => $n->getAttribute('href'),
					'type' => $n->getAttribute('media-type'),
					'props' => $n->getAttribute('properties'),
				];
			}
		}
		$candidates = [];
		foreach ($items as $it) {
			if (in_array('cover-image', preg_split('/\s+/', $it['props']) ?: [], true)) {
				$candidates[] = $it['href'];
			}
		}
		$metaCover = XmlUtil::text($xp, "//*[local-name()='metadata']/*[local-name()='meta'][@name='cover']/@content");
		if ($metaCover !== null && isset($items[$metaCover])) {
			$candidates[] = $items[$metaCover]['href'];
		}
		foreach (['cover-image', 'cover', 'coverimage', 'cover-img'] as $id) {
			if (isset($items[$id]) && str_starts_with($items[$id]['type'], 'image/')) {
				$candidates[] = $items[$id]['href'];
			}
		}
		foreach ($candidates as $href) {
			$img = $this->readImage($zip, $dir, $href);
			if ($img !== null) {
				return $img;
			}
		}
		// guide/cover fallback
		$g = XmlUtil::text($xp, "//*[local-name()='guide']/*[local-name()='reference'][@type='cover']/@href");
		if ($g !== null) {
			$img = $this->readImage($zip, $dir, $g);
			if ($img !== null) {
				return $img;
			}
			$page = SafeZip::resolve($dir, $g);
			$html = $page === null ? null : $zip->read($page);
			if ($html !== null && preg_match('/<(?:img|image)\b[^>]*?(?:src|href)\s*=\s*["\']([^"\']+)["\']/i', $html, $m) === 1) {
				$pageDir = str_contains((string)$page, '/') ? substr((string)$page, 0, (int)strrpos((string)$page, '/')) : '';
				$img = $this->readImage($zip, $pageDir, html_entity_decode($m[1]));
				if ($img !== null) {
					return $img;
				}
			}
		}
		return [null, null];
	}

	/** @return ?array{0: string, 1: string} */
	private function readImage(SafeZip $zip, string $dir, string $href): ?array {
		$path = SafeZip::resolve($dir, $href);
		if ($path === null) {
			return null;
		}
		try {
			$data = $zip->read($path);
		} catch (UnsafeArchiveException) {
			return null;
		}
		if ($data === null) {
			return null;
		}
		$mime = ImageUtil::mime($data);
		return $mime === null ? null : [$data, $mime];
	}
}
