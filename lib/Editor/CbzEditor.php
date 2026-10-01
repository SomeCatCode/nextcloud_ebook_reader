<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Editor;

use DOMDocument;
use DOMElement;
use ZipArchive;

/**
 * Reads and rewrites comic book archives (CBZ). Pages are renamed 0001.ext ... and ComicInfo.xml is maintained.
 */
final class CbzEditor implements BookEditorInterface {
	public const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'];
	private const COMICINFO = 'ComicInfo.xml';

	#[\Override]
	public function supports(string $format): bool {
		return $format === 'cbz';
	}

	#[\Override]
	public function readStructure(string $localPath, string $format): array {
		$zip = EditorUtil::openZip($localPath);
		try {
			$pages = $this->listPages($zip);
			$items = [];
			$n = 0;
			foreach ($pages as $name => $size) {
				$n++;
				$items[] = ['id' => $name, 'label' => 'Seite ' . $n, 'href' => $name, 'kind' => 'page', 'linear' => true, 'size' => $size];
			}
			$names = array_keys($pages);
			$info = $this->loadComicInfo($zip);
			$metadata = $this->emptyMetadata();
			$toc = [];
			if ($info !== null) {
				$metadata = $this->readMetadata($info);
				$counter = 0;
				foreach ($info->getElementsByTagName('Page') as $pageEl) {
					$bm = trim($pageEl->getAttribute('Bookmark'));
					$idx = $pageEl->getAttribute('Image');
					if ($bm !== '' && ctype_digit($idx) && isset($names[(int)$idx])) {
						$counter++;
						$toc[] = ['id' => 't' . $counter, 'label' => $bm, 'itemId' => $names[(int)$idx], 'fragment' => null, 'children' => []];
					}
				}
			}
			return [
				'format' => 'cbz',
				'capabilities' => ['metadata' => true, 'cover' => true, 'content' => true, 'toc' => true, 'writesFile' => true],
				'metadata' => $metadata,
				'items' => $items,
				'toc' => $toc,
				'warnings' => [],
			];
		} finally {
			$zip->close();
		}
	}

	#[\Override]
	public function write(string $srcPath, string $dstPath, EditRequest $req): array {
		$zip = EditorUtil::openZip($srcPath);
		try {
			if ($req->isMetadataOnly()) {
				return $this->writeMetadataOnly($zip, $dstPath, $req);
			}
			$pages = $this->listPages($zip);
			$oldNames = array_keys($pages);
			$oldIndex = array_flip($oldNames);
			$res = EditRequestValidator::resolve($oldNames, $req);
			$order = $res['order'];
			$removed = array_flip($res['removed']);

			$coverUpload = null;
			$coverItem = null;
			if ($req->cover !== null) {
				$source = $req->cover['source'] ?? '';
				if ($source === 'upload') {
					$coverUpload = EditorUtil::decodeCoverUpload($req->cover);
				} elseif ($source === 'item') {
					$coverItem = $req->cover['itemId'] ?? null;
					if (!is_string($coverItem) || !isset($oldIndex[$coverItem]) || isset($removed[$coverItem])) {
						throw new InvalidEditRequestException('The cover item must be a remaining page.');
					}
				} else {
					throw new InvalidEditRequestException('Unknown cover source.');
				}
			}

			$total = count($order) + ($coverUpload !== null ? 1 : 0);
			if ($total === 0) {
				throw new InvalidEditRequestException('At least one page must remain.');
			}
			$width = max(4, strlen((string)$total));

			// new names
			$newNames = [];
			$itemMap = [];
			$seq = $coverUpload !== null ? 1 : 0;
			foreach ($order as $old) {
				$seq++;
				$ext = strtolower(pathinfo($old, PATHINFO_EXTENSION));
				$newNames[$old] = sprintf('%0' . $width . 'd.%s', $seq, $ext);
			}
			foreach ($oldNames as $old) {
				$itemMap[$old] = $newNames[$old] ?? null;
			}
			$newIndex = [];
			$pos = $coverUpload !== null ? 1 : 0;
			foreach ($order as $old) {
				$newIndex[$old] = $pos++;
			}

			// ComicInfo
			$info = $this->loadComicInfo($zip);
			if ($info === null) {
				$info = new DOMDocument('1.0', 'UTF-8');
				$root = $info->createElement('ComicInfo');
				$root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
				$root->setAttribute('xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
				$info->appendChild($root);
			}
			$root = $info->documentElement;
			if (!$root instanceof DOMElement) {
				throw new EditorException('ComicInfo.xml is invalid.', 422);
			}
			if ($req->metadata !== null) {
				$this->applyMetadata($info, $root, $req->metadata);
			}
			$this->setChild($info, $root, 'PageCount', (string)$total);

			// existing page attributes by old index
			$oldPageAttrs = [];
			foreach ($info->getElementsByTagName('Page') as $pageEl) {
				$idx = $pageEl->getAttribute('Image');
				if (ctype_digit($idx)) {
					$attrs = [];
					foreach ($pageEl->attributes ?? [] as $a) {
						$attrs[$a->nodeName] = $a->nodeValue ?? '';
					}
					$oldPageAttrs[(int)$idx] = $attrs;
				}
			}
			$pageList = [];
			if ($coverUpload !== null) {
				$pageList[] = ['Image' => '0', 'Type' => 'FrontCover'];
			}
			foreach ($order as $old) {
				$attrs = $oldPageAttrs[$oldIndex[$old]] ?? [];
				$attrs['Image'] = (string)$newIndex[$old];
				if ($coverUpload !== null || $coverItem !== null) {
					if (($attrs['Type'] ?? '') === 'FrontCover') {
						unset($attrs['Type']);
					}
					if ($old === $coverItem) {
						$attrs['Type'] = 'FrontCover';
					}
				}
				if ($req->toc !== null) {
					unset($attrs['Bookmark']);
				}
				$pageList[] = $attrs;
			}
			if ($req->toc !== null) {
				$marks = [];
				$this->flattenToc($req->toc, $marks);
				foreach ($marks as [$label, $itemId]) {
					if (!isset($oldIndex[$itemId])) {
						throw new InvalidEditRequestException('Unknown item id in toc: ' . $itemId);
					}
					if (isset($removed[$itemId])) {
						continue;
					}
					$target = $newIndex[$itemId];
					foreach ($pageList as $i => $attrs) {
						if ((int)$attrs['Image'] === $target) {
							$pageList[$i]['Bookmark'] = $label;
						}
					}
				}
			}
			foreach (iterator_to_array($root->getElementsByTagName('Pages')) as $old) {
				$root->removeChild($old);
			}
			$pagesEl = $info->createElement('Pages');
			foreach ($pageList as $attrs) {
				$p = $info->createElement('Page');
				foreach ($attrs as $k => $v) {
					if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', (string)$k) === 1) {
						$p->setAttribute((string)$k, EditorUtil::xmlSafe((string)$v));
					}
				}
				$pagesEl->appendChild($p);
			}
			$root->appendChild($pagesEl);
			$infoXml = (string)$info->saveXML();

			return $this->writeRenamed($zip, $dstPath, $coverUpload, $order, $newNames, $infoXml, $itemMap, $pages);
		} finally {
			$zip->close();
		}
	}

	// ------------------------------------------------------------------

	/**
	 * Metadata-only fast path: every entry is copied unchanged (page names are kept, nothing is renumbered),
	 * only ComicInfo.xml is replaced. Pages and bookmarks inside ComicInfo.xml stay as they are.
	 *
	 * @return array{warnings: list<string>, itemMap: array<string, ?string>}
	 */
	private function writeMetadataOnly(ZipArchive $zip, string $dstPath, EditRequest $req): array {
		$pageCount = count($this->listPages($zip));
		$info = $this->loadComicInfo($zip);
		if ($info === null) {
			$info = new DOMDocument('1.0', 'UTF-8');
			$root = $info->createElement('ComicInfo');
			$root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
			$root->setAttribute('xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
			$info->appendChild($root);
		}
		$root = $info->documentElement;
		if (!$root instanceof DOMElement) {
			throw new EditorException('ComicInfo.xml is invalid.', 422);
		}
		$this->applyMetadata($info, $root, $req->metadata ?? []);
		$this->setChild($info, $root, 'PageCount', (string)$pageCount);
		$infoXml = (string)$info->saveXML();

		$writer = new ZipWriter($dstPath);
		try {
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = (string)$zip->getNameIndex($i);
				if ($name === '' || str_ends_with($name, '/') || $name === self::COMICINFO || !EditorUtil::isSafeName($name)) {
					continue;
				}
				$writer->copyFrom($zip, $name);
			}
			$writer->addString(self::COMICINFO, $infoXml);
			$writer->close();
		} catch (\Throwable $e) {
			$writer->abort();
			throw $e;
		}
		$check = $this->readStructure($dstPath, 'cbz');
		if (count($check['items']) !== $pageCount) {
			throw new EditorException('Verification of the rewritten CBZ failed (page count).', 500);
		}
		return ['warnings' => [], 'itemMap' => []];
	}

	/**
	 * @param ?array{data: string, mime: string, ext: string} $coverUpload
	 * @param list<string> $order
	 * @param array<string, string> $newNames
	 * @param array<string, ?string> $itemMap
	 * @param array<string, int> $pages
	 * @return array{warnings: list<string>, itemMap: array<string, ?string>}
	 */
	private function writeRenamed(ZipArchive $zip, string $dstPath, ?array $coverUpload, array $order, array $newNames, string $infoXml, array $itemMap, array $pages): array {
		$writer = new ZipWriter($dstPath);
		try {
			$width = strlen(pathinfo(end($newNames) ?: '0001.jpg', PATHINFO_FILENAME));
			if ($coverUpload !== null) {
				$writer->addString(sprintf('%0' . $width . 'd.%s', 1, $coverUpload['ext']), $coverUpload['data'], true);
			}
			foreach ($order as $old) {
				$writer->copyFromAs($zip, $old, $newNames[$old]);
			}
			// other entries (not pages, not ComicInfo.xml, not directories)
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = (string)$zip->getNameIndex($i);
				if (isset($pages[$name]) || str_ends_with($name, '/') || $name === self::COMICINFO || !EditorUtil::isSafeName($name)) {
					continue;
				}
				if (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::IMAGE_EXT, true)) {
					continue; // ignored images (e.g. __MACOSX)
				}
				$writer->copyFrom($zip, $name);
			}
			$writer->addString(self::COMICINFO, $infoXml);
			$writer->close();
		} catch (\Throwable $e) {
			$writer->abort();
			throw $e;
		}
		$check = $this->readStructure($dstPath, 'cbz');
		$expected = count($order) + ($coverUpload !== null ? 1 : 0);
		if (count($check['items']) !== $expected) {
			throw new EditorException('Verification of the rewritten CBZ failed (page count).', 500);
		}
		return ['warnings' => [], 'itemMap' => $itemMap];
	}

	/**
	 * Image entries in natural order: name => size.
	 * @return array<string, int>
	 */
	private function listPages(ZipArchive $zip): array {
		$names = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			if ($name === '' || str_ends_with($name, '/') || !EditorUtil::isSafeName($name)) {
				continue;
			}
			if (str_starts_with($name, '__MACOSX/') || str_starts_with(basename($name), '.')) {
				continue;
			}
			if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::IMAGE_EXT, true)) {
				continue;
			}
			$st = $zip->statIndex($i);
			$names[$name] = $st === false ? 0 : (int)$st['size'];
		}
		uksort($names, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
		return $names;
	}

	private function loadComicInfo(ZipArchive $zip): ?DOMDocument {
		$xml = EditorUtil::readEntry($zip, self::COMICINFO, 5 * 1024 * 1024);
		if ($xml === null) {
			return null;
		}
		try {
			$dom = EditorUtil::loadXml($xml, 'ComicInfo.xml');
		} catch (EditorException) {
			return null;
		}
		return $dom->documentElement !== null && $dom->documentElement->localName === 'ComicInfo' ? $dom : null;
	}

	/** @return array<string, mixed> */
	private function emptyMetadata(): array {
		return ['title' => null, 'authors' => [], 'series' => null, 'seriesIndex' => null, 'description' => null, 'language' => null, 'publisher' => null, 'isbn' => null, 'publishedAt' => null, 'genres' => [], 'tags' => []];
	}

	private function childText(DOMElement $root, string $name): ?string {
		foreach ($root->childNodes as $c) {
			if ($c instanceof DOMElement && $c->localName === $name) {
				$v = trim($c->textContent);
				return $v === '' ? null : $v;
			}
		}
		return null;
	}

	/** @return list<string> */
	private function splitList(?string $s, string $pattern = '/[,;]/'): array {
		if ($s === null) {
			return [];
		}
		$out = [];
		foreach (preg_split($pattern, $s) ?: [] as $p) {
			$p = trim($p);
			if ($p !== '' && !in_array($p, $out, true)) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/** @return array<string, mixed> */
	private function readMetadata(DOMDocument $info): array {
		$root = $info->documentElement;
		if (!$root instanceof DOMElement) {
			return $this->emptyMetadata();
		}
		$year = $this->childText($root, 'Year');
		$month = $this->childText($root, 'Month');
		$day = $this->childText($root, 'Day');
		$date = null;
		if ($year !== null && ctype_digit($year)) {
			$date = $year;
			if ($month !== null && ctype_digit($month) && (int)$month >= 1 && (int)$month <= 12) {
				$date .= sprintf('-%02d', (int)$month);
				if ($day !== null && ctype_digit($day) && (int)$day >= 1 && (int)$day <= 31) {
					$date .= sprintf('-%02d', (int)$day);
				}
			}
		}
		$number = $this->childText($root, 'Number');
		return [
			'title' => $this->childText($root, 'Title'),
			'authors' => $this->splitList($this->childText($root, 'Writer')),
			'series' => $this->childText($root, 'Series'),
			'seriesIndex' => $number !== null && is_numeric($number) ? (float)$number : null,
			'description' => $this->childText($root, 'Summary'),
			'language' => $this->childText($root, 'LanguageISO'),
			'publisher' => $this->childText($root, 'Publisher'),
			'isbn' => $this->childText($root, 'GTIN'),
			'publishedAt' => $date,
			'genres' => $this->splitList($this->childText($root, 'Genre')),
			'tags' => $this->splitList($this->childText($root, 'Tags')),
		];
	}

	private function setChild(DOMDocument $dom, DOMElement $root, string $name, ?string $value): void {
		$found = null;
		foreach (iterator_to_array($root->childNodes) as $c) {
			if ($c instanceof DOMElement && $c->localName === $name) {
				if ($found === null) {
					$found = $c;
				} else {
					$root->removeChild($c);
				}
			}
		}
		if ($value === null || $value === '') {
			if ($found !== null) {
				$root->removeChild($found);
			}
			return;
		}
		if ($found === null) {
			$found = $dom->createElement($name);
			$root->appendChild($found);
		}
		while ($found->firstChild !== null) {
			$found->removeChild($found->firstChild);
		}
		$found->appendChild($dom->createTextNode(EditorUtil::xmlSafe($value)));
	}

	/** @param array<string, mixed> $m */
	private function applyMetadata(DOMDocument $dom, DOMElement $root, array $m): void {
		$str = static function (string $k) use ($m): ?string {
			$v = $m[$k] ?? null;
			if (!is_scalar($v)) {
				return null;
			}
			$v = trim((string)$v);
			return $v === '' ? null : $v;
		};
		/** @return list<string> */
		$list = static function (string $k) use ($m): array {
			$v = $m[$k] ?? [];
			$out = [];
			foreach (is_array($v) ? $v : [] as $x) {
				if (is_scalar($x) && trim((string)$x) !== '' && !in_array(trim((string)$x), $out, true)) {
					$out[] = trim((string)$x);
				}
			}
			return $out;
		};
		if (array_key_exists('title', $m)) {
			$this->setChild($dom, $root, 'Title', $str('title'));
		}
		if (array_key_exists('series', $m)) {
			$this->setChild($dom, $root, 'Series', $str('series'));
		}
		if (array_key_exists('seriesIndex', $m)) {
			$idx = $m['seriesIndex'];
			$this->setChild($dom, $root, 'Number', is_numeric($idx) ? rtrim(rtrim(sprintf('%.4F', (float)$idx), '0'), '.') : null);
		}
		if (array_key_exists('description', $m)) {
			$t = EditorUtil::htmlToText($str('description'));
			$this->setChild($dom, $root, 'Summary', $t === '' ? null : $t);
		}
		if (array_key_exists('authors', $m)) {
			$a = $list('authors');
			$this->setChild($dom, $root, 'Writer', $a === [] ? null : implode(', ', $a));
		}
		if (array_key_exists('publisher', $m)) {
			$this->setChild($dom, $root, 'Publisher', $str('publisher'));
		}
		if (array_key_exists('language', $m)) {
			$this->setChild($dom, $root, 'LanguageISO', $str('language'));
		}
		if (array_key_exists('isbn', $m)) {
			$this->setChild($dom, $root, 'GTIN', $str('isbn'));
		}
		if (array_key_exists('publishedAt', $m)) {
			$d = $str('publishedAt');
			$y = null;
			$mo = null;
			$da = null;
			if ($d !== null && preg_match('/^(\d{4})(?:-(\d{1,2})(?:-(\d{1,2}))?)?/', $d, $mm) === 1) {
				$y = $mm[1];
				$mo = isset($mm[2]) && $mm[2] !== '' ? (string)(int)$mm[2] : null;
				$da = isset($mm[3]) && $mm[3] !== '' ? (string)(int)$mm[3] : null;
			}
			$this->setChild($dom, $root, 'Year', $y);
			$this->setChild($dom, $root, 'Month', $mo);
			$this->setChild($dom, $root, 'Day', $da);
		}
		if (array_key_exists('genres', $m)) {
			$g = $list('genres');
			$this->setChild($dom, $root, 'Genre', $g === [] ? null : implode(', ', $g));
		}
		if (array_key_exists('tags', $m)) {
			$t = $list('tags');
			$this->setChild($dom, $root, 'Tags', $t === [] ? null : implode(', ', $t));
		}
	}

	/**
	 * @param list<array<string, mixed>> $nodes
	 * @param list<array{0: string, 1: string}> $out
	 */
	private function flattenToc(array $nodes, array &$out, int $depth = 0): void {
		if ($depth > 30) {
			throw new InvalidEditRequestException('The table of contents is nested too deeply.');
		}
		foreach ($nodes as $n) {
			if (!is_array($n)) {
				throw new InvalidEditRequestException('Invalid table of contents node.');
			}
			$itemId = $n['itemId'] ?? null;
			if (is_string($itemId) && $itemId !== '') {
				$label = EditorUtil::normalizeSpace((string)($n['label'] ?? ''));
				$out[] = [$label === '' ? $itemId : $label, $itemId];
			}
			$children = isset($n['children']) && is_array($n['children']) ? array_values($n['children']) : [];
			$this->flattenToc($children, $out, $depth + 1);
		}
	}
}
