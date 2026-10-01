<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Service;

use OCA\EbookReader\Metadata\TarArchive;

/**
 * Writes comic archives from pages that were staged as files in a directory (page names
 * are already the final, zero padded entry names). Images are stored, never recompressed.
 */
class ComicWriter {
	public const COMIC_INFO = 'ComicInfo.xml';

	private const IMAGE_TYPES = [
		'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
		'webp' => 'image/webp', 'avif' => 'image/avif', 'bmp' => 'image/bmp',
	];

	public function __construct(
		private ArchiveTools $tools,
	) {
	}

	/**
	 * @param list<string> $pages file names in $dir, in reading order
	 */
	public function writeZip(string $dst, string $dir, array $pages, ?string $comicInfo): void {
		$zip = new \ZipArchive();
		if ($zip->open($dst, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('Cannot create zip file');
		}
		foreach ($pages as $page) {
			$zip->addFile($dir . '/' . $page, $page);
			$zip->setCompressionName($page, \ZipArchive::CM_STORE);
		}
		if ($comicInfo !== null) {
			$zip->addFromString(self::COMIC_INFO, $comicInfo);
		}
		if (!$zip->close()) {
			throw new \RuntimeException('Cannot write zip file');
		}
	}

	/**
	 * @param list<string> $pages
	 */
	public function writeTar(string $dst, string $dir, array $pages, ?string $comicInfo): void {
		$files = [];
		foreach ($pages as $page) {
			$files[$page] = $dir . '/' . $page;
		}
		if ($comicInfo !== null) {
			$info = $dir . '/' . self::COMIC_INFO;
			file_put_contents($info, $comicInfo);
			$files[self::COMIC_INFO] = $info;
		}
		TarArchive::write($dst, $files);
	}

	/**
	 * @param list<string> $pages
	 */
	public function writeSevenZip(string $dst, string $dir, array $pages, ?string $comicInfo): void {
		if ($comicInfo !== null) {
			file_put_contents($dir . '/' . self::COMIC_INFO, $comicInfo);
		}
		$this->tools->createSevenZip($dst, $dir);
	}

	/**
	 * Fixed layout EPUB 3: one XHTML page per image, the image fitted via the viewport size.
	 *
	 * @param list<string> $pages image file names in $dir, in reading order
	 * @param array{title?: ?string, authors?: list<string>, series?: ?string, seriesIndex?: ?float, description?: ?string, language?: ?string, publisher?: ?string, publishedAt?: ?string, genres?: list<string>, tags?: list<string>} $meta
	 * @return list<string> the zip paths of the page documents (same order as $pages)
	 */
	public function writeEpub(string $dst, string $dir, array $pages, array $meta, bool $rtl, int $coverIndex = 0): array {
		$zip = new \ZipArchive();
		if ($zip->open($dst, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('Cannot create epub file');
		}
		$zip->addFromString('mimetype', 'application/epub+zip');
		$zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
		$zip->addFromString('META-INF/container.xml', '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles>'
			. '<rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');

		$manifest = '';
		$spine = '';
		$nav = '';
		$pageHrefs = [];
		$coverIndex = isset($pages[$coverIndex]) ? $coverIndex : 0;
		foreach ($pages as $i => $page) {
			$n = $i + 1;
			$id = sprintf('%04d', $n);
			$ext = strtolower(pathinfo($page, PATHINFO_EXTENSION));
			$mime = self::IMAGE_TYPES[$ext] ?? 'image/jpeg';
			$size = @getimagesize($dir . '/' . $page);
			[$w, $h] = $size !== false && $size[0] > 0 && $size[1] > 0 ? [$size[0], $size[1]] : [1000, 1500];

			$zip->addFile($dir . '/' . $page, 'OEBPS/images/' . $page);
			$zip->setCompressionName('OEBPS/images/' . $page, \ZipArchive::CM_STORE);
			$zip->addFromString('OEBPS/pages/' . $id . '.xhtml', $this->pageXhtml($n, $page, $w, $h));
			$pageHrefs[] = 'OEBPS/pages/' . $id . '.xhtml';

			$manifest .= '<item id="img' . $id . '" href="images/' . $this->esc($page) . '" media-type="' . $mime . '"'
				. ($i === $coverIndex ? ' properties="cover-image"' : '') . '/>' . "\n";
			$manifest .= '<item id="p' . $id . '" href="pages/' . $id . '.xhtml" media-type="application/xhtml+xml"/>' . "\n";
			$spine .= '<itemref idref="p' . $id . '"/>' . "\n";
			$nav .= '<li><a href="pages/' . $id . '.xhtml">' . ($i === $coverIndex ? 'Cover' : 'Page ' . $n) . '</a></li>' . "\n";
		}
		$manifest .= '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>' . "\n";

		$zip->addFromString('OEBPS/nav.xhtml', '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<!DOCTYPE html>' . "\n"
			. '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><head><meta charset="utf-8"/><title>'
			. $this->esc($meta['title'] ?? 'Comic') . '</title></head><body><nav epub:type="toc" id="toc"><ol>' . "\n"
			. $nav . '</ol></nav></body></html>');
		$zip->addFromString('OEBPS/content.opf', $this->opf($meta, $manifest, $spine, $rtl, sprintf('%04d', $coverIndex + 1)));
		if (!$zip->close()) {
			throw new \RuntimeException('Cannot write epub file');
		}
		return $pageHrefs;
	}

	private function pageXhtml(int $n, string $image, int $w, int $h): string {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<!DOCTYPE html>' . "\n"
			. '<html xmlns="http://www.w3.org/1999/xhtml"><head><meta charset="utf-8"/><title>Page ' . $n . '</title>'
			. '<meta name="viewport" content="width=' . $w . ', height=' . $h . '"/>'
			. '<style>html,body{margin:0;padding:0;width:' . $w . 'px;height:' . $h . 'px;overflow:hidden}'
			. 'img{display:block;width:' . $w . 'px;height:' . $h . 'px}</style></head>'
			. '<body><img src="../images/' . $this->esc($image) . '" alt="Page ' . $n . '"/></body></html>';
	}

	/** @param array<string, mixed> $meta */
	private function opf(array $meta, string $manifest, string $spine, bool $rtl, string $coverId): string {
		$title = $this->str($meta['title'] ?? null) ?? 'Comic';
		$lang = $this->str($meta['language'] ?? null) ?? 'und';
		$uuid = bin2hex(random_bytes(16));
		$uuid = substr($uuid, 0, 8) . '-' . substr($uuid, 8, 4) . '-4' . substr($uuid, 13, 3) . '-a' . substr($uuid, 17, 3) . '-' . substr($uuid, 20, 12);
		$md = '<dc:identifier id="bookid">urn:uuid:' . $uuid . '</dc:identifier>' . "\n"
			. '<dc:title>' . $this->esc($title) . '</dc:title>' . "\n"
			. '<dc:language>' . $this->esc($lang) . '</dc:language>' . "\n";
		/** @var list<string> $authors */
		$authors = is_array($meta['authors'] ?? null) ? $meta['authors'] : [];
		foreach ($authors as $a) {
			$md .= '<dc:creator>' . $this->esc($a) . '</dc:creator>' . "\n";
		}
		if (($p = $this->str($meta['publisher'] ?? null)) !== null) {
			$md .= '<dc:publisher>' . $this->esc($p) . '</dc:publisher>' . "\n";
		}
		if (($d = $this->str($meta['publishedAt'] ?? null)) !== null) {
			$md .= '<dc:date>' . $this->esc($d) . '</dc:date>' . "\n";
		}
		if (($desc = $this->str($meta['description'] ?? null)) !== null) {
			$text = trim(html_entity_decode(strip_tags(preg_replace('#</(p|div|br)\s*>|<br\s*/?>#i', "\n", $desc) ?? $desc), ENT_QUOTES | ENT_XML1, 'UTF-8'));
			if ($text !== '') {
				$md .= '<dc:description>' . $this->esc($text) . '</dc:description>' . "\n";
			}
		}
		foreach (['genres', 'tags'] as $key) {
			/** @var list<string> $subjects */
			$subjects = is_array($meta[$key] ?? null) ? $meta[$key] : [];
			foreach ($subjects as $s) {
				$md .= '<dc:subject>' . $this->esc($s) . '</dc:subject>' . "\n";
			}
		}
		$series = $this->str($meta['series'] ?? null);
		if ($series !== null) {
			$md .= '<meta property="belongs-to-collection" id="series1">' . $this->esc($series) . '</meta>' . "\n"
				. '<meta refines="#series1" property="collection-type">series</meta>' . "\n";
			$idx = $meta['seriesIndex'] ?? null;
			if (is_float($idx) || is_int($idx)) {
				$pos = rtrim(rtrim(number_format((float)$idx, 2, '.', ''), '0'), '.');
				$md .= '<meta refines="#series1" property="group-position">' . $pos . '</meta>' . "\n";
				$md .= '<meta name="calibre:series_index" content="' . $pos . '"/>' . "\n";
			}
			$md .= '<meta name="calibre:series" content="' . $this->esc($series) . '"/>' . "\n";
		}
		$md .= '<meta property="dcterms:modified">' . gmdate('Y-m-d\TH:i:s\Z') . '</meta>' . "\n"
			. '<meta property="rendition:layout">pre-paginated</meta>' . "\n"
			. '<meta property="rendition:orientation">auto</meta>' . "\n"
			. '<meta property="rendition:spread">auto</meta>' . "\n"
			. '<meta name="cover" content="img' . $coverId . '"/>' . "\n";

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid" xml:lang="' . $this->esc($lang) . '">' . "\n"
			. '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n" . $md . '</metadata>' . "\n"
			. '<manifest>' . "\n" . $manifest . '</manifest>' . "\n"
			. '<spine' . ($rtl ? ' page-progression-direction="rtl"' : '') . '>' . "\n" . $spine . '</spine>' . "\n"
			. '</package>';
	}

	private function str(mixed $v): ?string {
		return is_string($v) && trim($v) !== '' ? trim($v) : null;
	}

	private function esc(string $s): string {
		$s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
		return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}
