<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Editor;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ZipArchive;

/**
 * Reads and rewrites EPUB 2/3 packages. The result is always a new zip built from scratch
 * ("mimetype" first and stored, META-INF/* copied unchanged).
 *
 * Known limitation: duplicate spine idrefs are dropped when the order is changed.
 */
final class EpubEditor implements BookEditorInterface {
	private const NS_OPF = 'http://www.idpf.org/2007/opf';
	private const NS_DC = 'http://purl.org/dc/elements/1.1/';
	private const NS_CONTAINER = 'urn:oasis:names:tc:opendocument:xmlns:container';
	private const NS_XHTML = 'http://www.w3.org/1999/xhtml';
	private const NS_EPUB = 'http://www.idpf.org/2007/ops';
	private const NS_NCX = 'http://www.daisy.org/z3986/2005/ncx/';

	private const SCANNABLE = ['application/xhtml+xml', 'text/html', 'image/svg+xml', 'text/css'];
	private const MAX_WARNINGS = 20;

	#[\Override]
	public function supports(string $format): bool {
		return $format === 'epub';
	}

	#[\Override]
	public function readStructure(string $localPath, string $format): array {
		$zip = EditorUtil::openZip($localPath);
		try {
			$pkg = $this->loadPackage($zip);
			$raw = $this->readToc($zip, $pkg);

			$pathToId = [];
			foreach ($pkg->spine as $s) {
				$path = $pkg->manifest[$s['idref']]['path'] ?? null;
				if ($path !== null && !isset($pathToId[$path])) {
					$pathToId[$path] = $s['idref'];
				}
			}
			$labels = [];
			$this->collectLabels($raw, $labels);

			$items = [];
			$seen = [];
			foreach ($pkg->spine as $s) {
				$id = $s['idref'];
				if (isset($seen[$id]) || !isset($pkg->manifest[$id])) {
					continue;
				}
				$seen[$id] = true;
				$path = $pkg->manifest[$id]['path'];
				$st = $zip->statName($path);
				$items[] = [
					'id' => $id,
					'label' => $labels[$path] ?? basename($path),
					'href' => $path,
					'kind' => 'chapter',
					'linear' => $s['linear'],
					'size' => $st === false ? 0 : (int)$st['size'],
				];
			}

			$counter = 0;
			$toc = $this->rawToApi($raw, $pathToId, $counter);

			return [
				'format' => 'epub',
				'capabilities' => ['metadata' => true, 'cover' => true, 'content' => true, 'toc' => true, 'writesFile' => true],
				'metadata' => $this->readMetadata($pkg),
				'items' => $items,
				'toc' => $toc,
				'warnings' => [],
			];
		} finally {
			$zip->close();
		}
	}

	#[\Override]
	public function write(string $srcPath, string $dstPath, EditRequest $req, ?callable $progress = null): array {
		$zip = EditorUtil::openZip($srcPath);
		$writer = null;
		try {
			if ($req->isMetadataOnly()) {
				return $this->writeMetadataOnly($zip, $srcPath, $dstPath, $req, $progress);
			}
			$pkg = $this->loadPackage($zip);
			$warnings = [];

			$spineIds = [];
			$idPath = [];
			foreach ($pkg->spine as $s) {
				if (isset($pkg->manifest[$s['idref']]) && !isset($idPath[$s['idref']])) {
					$spineIds[] = $s['idref'];
					$idPath[$s['idref']] = $pkg->manifest[$s['idref']]['path'];
				}
			}
			$res = EditRequestValidator::resolve($spineIds, $req);
			if ($res['order'] === [] && $spineIds !== []) {
				throw new InvalidEditRequestException('At least one chapter must remain.');
			}
			$removedDocs = [];
			foreach ($res['removed'] as $id) {
				$removedDocs[$idPath[$id]] = true;
			}

			// cover
			$extraFiles = [];
			$overrides = [];
			$oldCoverPaths = [];
			$liveExtra = [];
			if ($req->cover !== null) {
				$oldCoverPaths = $this->currentCoverPaths($pkg);
				$coverPath = $this->applyCover($zip, $pkg, $req->cover, $extraFiles);
				$liveExtra[$coverPath] = true;
			}
			foreach ($this->currentCoverPaths($pkg) as $p) {
				$liveExtra[$p] = true;
			}

			// orphaned resources
			try {
				$orphans = $removedDocs === [] && $oldCoverPaths === [] ? [] : $this->findOrphans($zip, $pkg, $removedDocs, $oldCoverPaths, $liveExtra);
			} catch (EditorException) {
				// A document could not be scanned safely (too large / regex limit): keep every resource
				// rather than risk deleting one that is still referenced.
				$orphans = [];
				$warnings[] = 'Some chapters are too large to check for unused images and styles; all resources were kept.';
			}
			$removedAll = $removedDocs;
			foreach ($orphans as $o) {
				$removedAll[$o] = true;
			}

			// existing toc, needed to prune it
			$existingToc = $removedAll !== [] && $req->toc === null ? $this->readToc($zip, $pkg) : [];

			// manifest / spine
			$pathIndex = $pkg->pathIndex();
			foreach (array_keys($removedAll) as $path) {
				$id = $pathIndex[$path] ?? null;
				if ($id !== null && isset($pkg->manifest[$id])) {
					$el = $pkg->manifest[$id]['el'];
					$el->parentNode?->removeChild($el);
					unset($pkg->manifest[$id]);
				}
			}
			$this->cleanDanglingCoverMeta($pkg);
			$this->cleanGuide($pkg, $removedAll);
			if ($res['changed']) {
				$this->applySpine($pkg, $res['order']);
			}

			// metadata
			if ($req->metadata !== null) {
				$this->applyMetadata($pkg, $req->metadata);
			}

			// toc
			$overrides = [];
			$tocChanged = $req->toc !== null || $removedAll !== [];
			if ($tocChanged) {
				if ($req->toc !== null) {
					$tree = $this->buildTreeFromRequest($req->toc, $idPath, $pkg, $removedAll);
				} else {
					$tree = $this->pruneRaw($existingToc, $removedAll);
				}
				if ($tree === [] && $res['order'] !== []) {
					$first = $res['order'][0];
					$tree = [['label' => $this->titleOf($pkg) ?? basename($idPath[$first]), 'path' => $idPath[$first], 'fragment' => null, 'children' => []]];
				}
				$this->rewriteTocFiles($zip, $pkg, $tree, $removedAll, $overrides, $warnings);
			}

			$overrides[$pkg->opfPath] = (string)$pkg->dom->saveXML();
			if (!EditorUtil::isWellFormed($overrides[$pkg->opfPath])) {
				throw new EditorException('The rewritten package document is not well-formed.', 500);
			}

			// link warnings
			if ($removedDocs !== []) {
				array_push($warnings, ...$this->linkWarnings($zip, $pkg, $removedDocs, $removedAll));
			}

			// write
			EditorUtil::report($progress, 0, 1, 'Preparing');
			$writer = new ZipWriter($dstPath);
			$mime = EditorUtil::readEntry($zip, 'mimetype', 1024);
			$writer->addString('mimetype', $mime !== null && trim($mime) !== '' ? trim($mime) : 'application/epub+zip', true);
			$numFiles = $zip->numFiles;
			for ($i = 0; $i < $numFiles; $i++) {
				EditorUtil::report($progress, $i + 1, $numFiles, 'Writing entry');
				$name = (string)$zip->getNameIndex($i);
				if ($name === 'mimetype' || str_ends_with($name, '/') || !EditorUtil::isSafeName($name) || isset($removedAll[$name])) {
					continue;
				}
				if (isset($overrides[$name])) {
					$writer->addString($name, $overrides[$name]);
					continue;
				}
				$writer->copyFrom($zip, $name);
			}
			foreach ($extraFiles as $path => $data) {
				$writer->addString($path, $data);
			}
			EditorUtil::report($progress, 1, 1, 'Finalizing archive');
			$writer->close();
			$writer = null;

			// verify the result can be re-opened
			$check = $this->readStructure($dstPath, 'epub');
			if (count($check['items']) !== count($res['order'])) {
				throw new EditorException('Verification of the rewritten EPUB failed (chapter count).', 500);
			}

			$itemMap = [];
			foreach ($idPath as $path) {
				$itemMap[$path] = isset($removedDocs[$path]) ? null : $path;
			}
			foreach ($orphans as $o) {
				$itemMap[$o] = null;
			}
			return ['warnings' => array_values(array_unique($warnings)), 'itemMap' => $itemMap];
		} catch (\Throwable $e) {
			$writer?->abort();
			throw $e;
		} finally {
			$zip->close();
		}
	}

	/**
	 * Metadata-only fast path: the source is copied as a file and only the package document (OPF) is replaced in the
	 * copy (deflate; mimetype and every other entry are copied raw by libzip, without recompression). Spine, manifest,
	 * table of contents and resources are not looked at.
	 *
	 * @param ?callable(float, string): void $progress
	 * @return array{warnings: list<string>, itemMap: array<string, ?string>}
	 */
	private function writeMetadataOnly(ZipArchive $zip, string $srcPath, string $dstPath, EditRequest $req, ?callable $progress = null): array {
		$pkg = $this->loadPackage($zip);
		$this->applyMetadata($pkg, $req->metadata ?? []);
		$opf = (string)$pkg->dom->saveXML();
		if (!EditorUtil::isWellFormed($opf)) {
			throw new EditorException('The rewritten package document is not well-formed.', 500);
		}
		$unsafe = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			if ($name !== '' && !str_ends_with($name, '/') && !EditorUtil::isSafeName($name)) {
				$unsafe[] = $name;
			}
		}
		EditorUtil::report($progress, 0, 1, 'Copying file');
		EditorUtil::replaceInCopy($srcPath, $dstPath, $pkg->opfPath, $opf, $unsafe);
		EditorUtil::report($progress, 1, 1, 'Verifying file');
		try {
			$check = EditorUtil::openZip($dstPath);
			try {
				$this->loadPackage($check);
			} finally {
				$check->close();
			}
		} catch (\Throwable $e) {
			@unlink($dstPath);
			throw $e;
		}
		return ['warnings' => [], 'itemMap' => []];
	}

	/** Resolves a manifest id or spine id to a zip path (used by the item controller). */
	public function pathForId(string $localPath, string $id): ?string {
		$zip = EditorUtil::openZip($localPath);
		try {
			$pkg = $this->loadPackage($zip);
			return $pkg->manifest[$id]['path'] ?? null;
		} finally {
			$zip->close();
		}
	}

	// ------------------------------------------------------------------ package

	private function loadPackage(ZipArchive $zip): EpubPackage {
		$container = EditorUtil::readEntry($zip, 'META-INF/container.xml');
		if ($container === null) {
			throw new EditorException('The EPUB has no META-INF/container.xml.', 422);
		}
		$cdom = EditorUtil::loadXml($container, 'container.xml');
		$cxp = new DOMXPath($cdom);
		$cxp->registerNamespace('c', self::NS_CONTAINER);
		$rf = $cxp->query('//c:rootfile[@full-path]');
		$rfEl = $rf !== false ? $rf->item(0) : null;
		$opfPath = $rfEl instanceof DOMElement ? EditorUtil::resolvePath('', $rfEl->getAttribute('full-path')) : '';
		if ($opfPath === '') {
			throw new EditorException('The EPUB has no package document.', 422);
		}
		$opf = EditorUtil::readEntry($zip, $opfPath);
		if ($opf === null) {
			throw new EditorException('The package document ' . $opfPath . ' is missing.', 422);
		}
		$dom = EditorUtil::loadXml($opf, 'Package document');
		$xp = new DOMXPath($dom);
		$xp->registerNamespace('o', self::NS_OPF);
		$xp->registerNamespace('dc', self::NS_DC);
		$root = $dom->documentElement;
		$version = $root !== null && $root->getAttribute('version') !== '' ? $root->getAttribute('version') : '2.0';
		$opfDir = EditorUtil::dirName($opfPath);

		$manifest = [];
		$nodes = $xp->query('//o:manifest/o:item');
		if ($nodes !== false) {
			foreach ($nodes as $el) {
				if (!$el instanceof DOMElement) {
					continue;
				}
				$id = $el->getAttribute('id');
				$href = $el->getAttribute('href');
				if ($id === '' || $href === '' || isset($manifest[$id])) {
					continue;
				}
				$manifest[$id] = [
					'id' => $id,
					'href' => $href,
					'path' => EditorUtil::resolvePath($opfDir, rawurldecode((string)preg_replace('~[#?].*$~s', '', $href))),
					'type' => $el->getAttribute('media-type'),
					'props' => $el->getAttribute('properties'),
					'el' => $el,
				];
			}
		}

		$spine = [];
		$nodes = $xp->query('//o:spine/o:itemref');
		if ($nodes !== false) {
			foreach ($nodes as $el) {
				if ($el instanceof DOMElement && $el->getAttribute('idref') !== '') {
					$spine[] = ['idref' => $el->getAttribute('idref'), 'linear' => strtolower($el->getAttribute('linear')) !== 'no', 'el' => $el];
				}
			}
		}

		$navPath = null;
		$ncxPath = null;
		foreach ($manifest as $item) {
			if ($navPath === null && in_array('nav', preg_split('/\s+/', trim($item['props'])) ?: [], true)) {
				$navPath = $item['path'];
			}
		}
		$spineEl = $xp->query('//o:spine')->item(0) ?? null;
		$tocId = $spineEl instanceof DOMElement ? $spineEl->getAttribute('toc') : '';
		if ($tocId !== '' && isset($manifest[$tocId])) {
			$ncxPath = $manifest[$tocId]['path'];
		} else {
			foreach ($manifest as $item) {
				if ($item['type'] === 'application/x-dtbncx+xml') {
					$ncxPath = $item['path'];
					break;
				}
			}
		}
		if ($spine === [] && $manifest === []) {
			throw new EditorException('The EPUB package has no content.', 422);
		}
		return new EpubPackage($opfPath, $opfDir, $dom, $xp, $version, $manifest, $spine, $navPath, $ncxPath);
	}

	// ------------------------------------------------------------------ metadata read

	/** @return array<string, mixed> */
	private function readMetadata(EpubPackage $pkg): array {
		$xp = $pkg->xp;
		$first = function (string $q) use ($xp): ?string {
			$n = $xp->query($q);
			if ($n === false || $n->length === 0 || $n->item(0) === null) {
				return null;
			}
			$v = EditorUtil::normalizeSpace($n->item(0)->textContent);
			return $v === '' ? null : $v;
		};
		$all = function (string $q) use ($xp): array {
			$out = [];
			$n = $xp->query($q);
			if ($n !== false) {
				foreach ($n as $el) {
					$v = EditorUtil::normalizeSpace($el->textContent);
					if ($v !== '') {
						$out[] = $v;
					}
				}
			}
			return $out;
		};

		$series = $first("//o:meta[@name='calibre:series']/@content");
		$index = $first("//o:meta[@name='calibre:series_index']/@content");
		if ($series === null) {
			$series = $first("//o:meta[@property='belongs-to-collection']");
			$id = $xp->query("//o:meta[@property='belongs-to-collection']/@id");
			if ($series !== null && $id !== false && $id->length > 0 && $id->item(0) !== null) {
				$index = $first("//o:meta[@refines='#" . $id->item(0)->nodeValue . "' and @property='group-position']");
			}
		}
		$isbn = null;
		foreach ($all('//dc:identifier') as $ident) {
			if (preg_match('/^(?:urn:isbn:)?([0-9][0-9Xx\- ]{8,16})$/i', $ident, $m) === 1) {
				$isbn = str_replace([' ', '-'], '', $m[1]);
				break;
			}
		}
		$date = $first('//dc:date');
		$desc = $first('//dc:description');

		return [
			'title' => $first('//dc:title'),
			'authors' => $all('//dc:creator'),
			'series' => $series,
			'seriesIndex' => $index !== null && is_numeric($index) ? (float)$index : null,
			'description' => $desc,
			'language' => $first('//dc:language'),
			'publisher' => $first('//dc:publisher'),
			'isbn' => $isbn,
			'publishedAt' => $date !== null ? substr($date, 0, 10) : null,
			'genres' => [],
			'tags' => $all('//dc:subject'),
		];
	}

	private function titleOf(EpubPackage $pkg): ?string {
		$n = $pkg->xp->query('//dc:title');
		if ($n === false || $n->length === 0 || $n->item(0) === null) {
			return null;
		}
		$v = EditorUtil::normalizeSpace($n->item(0)->textContent);
		return $v === '' ? null : $v;
	}

	// ------------------------------------------------------------------ toc read

	/**
	 * @return list<array{label: string, path: ?string, fragment: ?string, children: list<array<string, mixed>>}>
	 */
	private function readToc(ZipArchive $zip, EpubPackage $pkg): array {
		if ($pkg->navPath !== null) {
			$xml = EditorUtil::readEntry($zip, $pkg->navPath);
			if ($xml !== null) {
				try {
					$tree = $this->parseNav($xml, $pkg->navPath);
					if ($tree !== null) {
						return $tree;
					}
				} catch (EditorException) {
					// fall through to the NCX
				}
			}
		}
		if ($pkg->ncxPath !== null) {
			$xml = EditorUtil::readEntry($zip, $pkg->ncxPath);
			if ($xml !== null) {
				try {
					return $this->parseNcx($xml, $pkg->ncxPath);
				} catch (EditorException) {
					return [];
				}
			}
		}
		return [];
	}

	/** @return ?list<array<string, mixed>> */
	private function parseNav(string $xml, string $navPath): ?array {
		$dom = EditorUtil::loadXml($xml, 'Navigation document');
		$xp = new DOMXPath($dom);
		$xp->registerNamespace('x', self::NS_XHTML);
		$navs = $xp->query('//x:nav');
		if ($navs === false || $navs->length === 0) {
			return null;
		}
		$toc = $this->findTocNav($navs);
		if ($toc === null) {
			return null;
		}
		return $this->parseNavList($toc, $xp, EditorUtil::dirName($navPath), $navPath);
	}

	/** @param \DOMNodeList $navs */
	private function findTocNav(\DOMNodeList $navs): ?DOMElement {
		foreach ($navs as $nav) {
			if ($nav instanceof DOMElement) {
				$type = $nav->getAttributeNS(self::NS_EPUB, 'type');
				if ($type === '') {
					$type = $nav->getAttribute('epub:type');
				}
				if (in_array('toc', preg_split('/\s+/', trim($type)) ?: [], true)) {
					return $nav;
				}
			}
		}
		return null;
	}

	/** @return list<array<string, mixed>> */
	private function parseNavList(DOMNode $parent, DOMXPath $xp, string $dir, string $selfPath): array {
		$out = [];
		$lis = $xp->query('x:ol/x:li', $parent);
		if ($lis === false) {
			return [];
		}
		foreach ($lis as $li) {
			$label = '';
			$path = null;
			$fragment = null;
			$a = $xp->query('x:a|x:span', $li);
			$el = $a !== false ? $a->item(0) : null;
			if ($el instanceof DOMElement) {
				$label = EditorUtil::normalizeSpace($el->textContent);
				if ($el->localName === 'a' && $el->getAttribute('href') !== '') {
					[$path, $fragment] = $this->splitHref($el->getAttribute('href'), $dir, $selfPath);
				}
			}
			$out[] = ['label' => $label, 'path' => $path, 'fragment' => $fragment, 'children' => $this->parseNavList($li, $xp, $dir, $selfPath)];
		}
		return $out;
	}

	/** @return list<array<string, mixed>> */
	private function parseNcx(string $xml, string $ncxPath): array {
		$dom = EditorUtil::loadXml($xml, 'NCX');
		$xp = new DOMXPath($dom);
		$xp->registerNamespace('n', self::NS_NCX);
		$map = $xp->query('//n:navMap');
		if ($map === false || $map->length === 0 || $map->item(0) === null) {
			return [];
		}
		return $this->parseNcxPoints($map->item(0), $xp, EditorUtil::dirName($ncxPath), $ncxPath);
	}

	/** @return list<array<string, mixed>> */
	private function parseNcxPoints(DOMNode $parent, DOMXPath $xp, string $dir, string $selfPath): array {
		$out = [];
		$pts = $xp->query('n:navPoint', $parent);
		if ($pts === false) {
			return [];
		}
		foreach ($pts as $pt) {
			$label = '';
			$t = $xp->query('n:navLabel/n:text', $pt);
			if ($t !== false && $t->length > 0 && $t->item(0) !== null) {
				$label = EditorUtil::normalizeSpace($t->item(0)->textContent);
			}
			$path = null;
			$fragment = null;
			$c = $xp->query('n:content', $pt);
			$cEl = $c !== false ? $c->item(0) : null;
			if ($cEl instanceof DOMElement && $cEl->getAttribute('src') !== '') {
				[$path, $fragment] = $this->splitHref($cEl->getAttribute('src'), $dir, $selfPath);
			}
			$out[] = ['label' => $label, 'path' => $path, 'fragment' => $fragment, 'children' => $this->parseNcxPoints($pt, $xp, $dir, $selfPath)];
		}
		return $out;
	}

	/** @return array{0: ?string, 1: ?string} */
	private function splitHref(string $href, string $dir, string $selfPath): array {
		$href = trim($href);
		if ($href === '' || EditorUtil::isExternalUrl($href)) {
			return [null, null];
		}
		$fragment = null;
		$pos = strpos($href, '#');
		if ($pos !== false) {
			$fragment = rawurldecode(substr($href, $pos + 1));
			$href = substr($href, 0, $pos);
			if ($fragment === '') {
				$fragment = null;
			}
		}
		$path = $href === '' ? $selfPath : EditorUtil::resolvePath($dir, rawurldecode((string)preg_replace('~\?.*$~s', '', $href)));
		return [$path, $fragment];
	}

	/**
	 * @param list<array<string, mixed>> $nodes
	 * @param array<string, string> $labels
	 */
	private function collectLabels(array $nodes, array &$labels): void {
		foreach ($nodes as $n) {
			$path = $n['path'] ?? null;
			if (is_string($path) && !isset($labels[$path]) && ($n['label'] ?? '') !== '') {
				$labels[$path] = (string)$n['label'];
			}
			/** @var list<array<string, mixed>> $children */
			$children = $n['children'] ?? [];
			$this->collectLabels($children, $labels);
		}
	}

	/**
	 * @param list<array<string, mixed>> $nodes
	 * @param array<string, string> $pathToId
	 * @return list<array<string, mixed>>
	 */
	private function rawToApi(array $nodes, array $pathToId, int &$counter): array {
		$out = [];
		foreach ($nodes as $n) {
			$counter++;
			$path = $n['path'] ?? null;
			/** @var list<array<string, mixed>> $children */
			$children = $n['children'] ?? [];
			$id = 't' . $counter;
			$out[] = [
				'id' => $id,
				'label' => (string)($n['label'] ?? ''),
				'itemId' => is_string($path) ? ($pathToId[$path] ?? null) : null,
				'href' => $path,
				'fragment' => $n['fragment'] ?? null,
				'children' => $this->rawToApi($children, $pathToId, $counter),
			];
		}
		return $out;
	}

	// ------------------------------------------------------------------ write helpers

	/** @return list<string> zip paths of the current cover image item(s) */
	private function currentCoverPaths(EpubPackage $pkg): array {
		$paths = [];
		foreach ($pkg->manifest as $item) {
			if (in_array('cover-image', preg_split('/\s+/', trim($item['props'])) ?: [], true)) {
				$paths[$item['path']] = true;
			}
		}
		$n = $pkg->xp->query("//o:metadata/o:meta[@name='cover']");
		if ($n !== false) {
			foreach ($n as $m) {
				if ($m instanceof DOMElement && isset($pkg->manifest[$m->getAttribute('content')])) {
					$paths[$pkg->manifest[$m->getAttribute('content')]['path']] = true;
				}
			}
		}
		return array_map('strval', array_keys($paths));
	}

	/**
	 * @param array<string, mixed> $cover
	 * @param array<string, string> $extraFiles
	 * @return string zip path of the new cover
	 */
	private function applyCover(ZipArchive $zip, EpubPackage $pkg, array $cover, array &$extraFiles): string {
		$source = $cover['source'] ?? '';
		$metadata = $pkg->xp->query('//o:metadata')->item(0) ?? null;
		$manifestEl = $pkg->xp->query('//o:manifest')->item(0) ?? null;
		if (!$metadata instanceof DOMElement || !$manifestEl instanceof DOMElement) {
			throw new EditorException('The package has no metadata/manifest.', 422);
		}
		if ($source === 'upload') {
			$img = EditorUtil::decodeCoverUpload($cover);
			$base = ($pkg->opfDir === '' ? '' : $pkg->opfDir . '/') . 'images/ebr-cover';
			$path = $base . '.' . $img['ext'];
			$n = 1;
			while ($zip->locateName($path) !== false) {
				$n++;
				$path = $base . '-' . $n . '.' . $img['ext'];
			}
			$id = 'ebr-cover';
			while (isset($pkg->manifest[$id])) {
				$id .= 'x';
			}
			$el = $pkg->dom->createElementNS(self::NS_OPF, 'item');
			$el->setAttribute('id', $id);
			$el->setAttribute('href', EditorUtil::encodeUrl(EditorUtil::relativePath($pkg->opfDir, $path)));
			$el->setAttribute('media-type', $img['mime']);
			$manifestEl->appendChild($el);
			$pkg->manifest[$id] = ['id' => $id, 'href' => $el->getAttribute('href'), 'path' => $path, 'type' => $img['mime'], 'props' => '', 'el' => $el];
			$extraFiles[$path] = $img['data'];
		} elseif ($source === 'item') {
			$id = $cover['itemId'] ?? null;
			if (!is_string($id) || !isset($pkg->manifest[$id]) || !str_starts_with($pkg->manifest[$id]['type'], 'image/')) {
				throw new InvalidEditRequestException('The cover item must be an image of the book.');
			}
			$path = $pkg->manifest[$id]['path'];
		} else {
			throw new InvalidEditRequestException('Unknown cover source.');
		}

		// reset previous cover markers
		foreach ($pkg->manifest as $mid => $item) {
			$tokens = preg_split('/\s+/', trim($item['props'])) ?: [];
			if (in_array('cover-image', $tokens, true)) {
				$tokens = array_values(array_filter($tokens, static fn (string $t): bool => $t !== 'cover-image' && $t !== ''));
				if ($tokens === []) {
					$item['el']->removeAttribute('properties');
				} else {
					$item['el']->setAttribute('properties', implode(' ', $tokens));
				}
				$pkg->manifest[$mid]['props'] = implode(' ', $tokens);
			}
		}
		$old = $pkg->xp->query("//o:metadata/o:meta[@name='cover']");
		if ($old !== false) {
			foreach (iterator_to_array($old) as $m) {
				$m->parentNode?->removeChild($m);
			}
		}
		$newId = (string)array_search($path, array_column($pkg->manifest, 'path', 'id'), true);
		$newEl = $pkg->manifest[$newId]['el'];
		if ($pkg->isEpub3()) {
			$props = trim($newEl->getAttribute('properties') . ' cover-image');
			$newEl->setAttribute('properties', $props);
			$pkg->manifest[$newId]['props'] = $props;
		}
		$meta = $pkg->dom->createElementNS(self::NS_OPF, 'meta');
		$meta->setAttribute('name', 'cover');
		$meta->setAttribute('content', $newId);
		$metadata->appendChild($meta);
		return $path;
	}

	private function cleanDanglingCoverMeta(EpubPackage $pkg): void {
		$n = $pkg->xp->query("//o:metadata/o:meta[@name='cover']");
		if ($n === false) {
			return;
		}
		foreach (iterator_to_array($n) as $m) {
			if ($m instanceof DOMElement && !isset($pkg->manifest[$m->getAttribute('content')])) {
				$m->parentNode?->removeChild($m);
			}
		}
	}

	/** @param array<string, true> $removedAll */
	private function cleanGuide(EpubPackage $pkg, array $removedAll): void {
		$n = $pkg->xp->query('//o:guide/o:reference');
		if ($n === false) {
			return;
		}
		foreach (iterator_to_array($n) as $ref) {
			if (!$ref instanceof DOMElement) {
				continue;
			}
			$href = (string)preg_replace('~[#?].*$~s', '', $ref->getAttribute('href'));
			if ($href !== '' && isset($removedAll[EditorUtil::resolvePath($pkg->opfDir, rawurldecode($href))])) {
				$ref->parentNode?->removeChild($ref);
			}
		}
	}

	/** @param list<string> $order */
	private function applySpine(EpubPackage $pkg, array $order): void {
		$spineEl = $pkg->xp->query('//o:spine')->item(0) ?? null;
		if (!$spineEl instanceof DOMElement) {
			return;
		}
		$byId = [];
		foreach ($pkg->spine as $s) {
			if (!isset($byId[$s['idref']])) {
				$byId[$s['idref']] = $s['el'];
			}
		}
		foreach ($pkg->spine as $s) {
			$s['el']->parentNode?->removeChild($s['el']);
		}
		$new = [];
		foreach ($order as $id) {
			if (isset($byId[$id])) {
				$spineEl->appendChild($byId[$id]);
				$new[] = ['idref' => $id, 'linear' => strtolower($byId[$id]->getAttribute('linear')) !== 'no', 'el' => $byId[$id]];
			}
		}
		$pkg->spine = $new;
	}

	/**
	 * @param array<string, true> $removedDocs
	 * @param list<string> $extraCandidates
	 * @param array<string, true> $liveExtra
	 * @return list<string> orphaned resource paths (not including the removed docs)
	 */
	private function findOrphans(ZipArchive $zip, EpubPackage $pkg, array $removedDocs, array $extraCandidates, array $liveExtra): array {
		$pathIndex = $pkg->pathIndex();
		$protected = [];
		foreach ($pkg->spine as $s) {
			if (isset($pkg->manifest[$s['idref']])) {
				$protected[$pkg->manifest[$s['idref']]['path']] = true;
			}
		}
		foreach ([$pkg->navPath, $pkg->ncxPath] as $p) {
			if ($p !== null) {
				$protected[$p] = true;
			}
		}
		$cache = [];
		$refs = function (string $path) use ($zip, $pkg, $pathIndex, &$cache): array {
			if (isset($cache[$path])) {
				return $cache[$path];
			}
			$cache[$path] = [];
			$id = $pathIndex[$path] ?? null;
			if ($id === null || !in_array($pkg->manifest[$id]['type'], self::SCANNABLE, true)) {
				return [];
			}
			$content = EditorUtil::readEntry($zip, $path);
			if ($content !== null) {
				$cache[$path] = EditorUtil::references($path, $content);
			}
			return $cache[$path];
		};

		$candidates = [];
		$addCandidate = function (string $p) use (&$candidates, $pathIndex, $protected): void {
			if (isset($pathIndex[$p]) && !isset($protected[$p])) {
				$candidates[$p] = true;
			}
		};
		foreach (array_keys($removedDocs) as $doc) {
			foreach ($refs($doc) as $r) {
				$addCandidate($r);
			}
		}
		foreach ($extraCandidates as $p) {
			$addCandidate($p);
		}

		$guideLive = [];
		$g = $pkg->xp->query('//o:guide/o:reference');
		if ($g !== false) {
			foreach ($g as $ref) {
				if ($ref instanceof DOMElement) {
					$href = (string)preg_replace('~[#?].*$~s', '', $ref->getAttribute('href'));
					$guideLive[EditorUtil::resolvePath($pkg->opfDir, rawurldecode($href))] = true;
				}
			}
		}

		$removedSet = $removedDocs;
		$orphans = [];
		for ($iter = 0; $iter < 20; $iter++) {
			$live = $liveExtra + $guideLive;
			foreach ($pkg->manifest as $item) {
				if (isset($removedSet[$item['path']]) || $item['path'] === $pkg->navPath) {
					continue;
				}
				foreach ($refs($item['path']) as $r) {
					$live[$r] = true;
				}
			}
			$new = [];
			foreach (array_keys($candidates) as $c) {
				if (!isset($live[$c]) && !isset($removedSet[$c])) {
					$new[] = $c;
				}
			}
			if ($new === []) {
				break;
			}
			foreach ($new as $c) {
				$removedSet[$c] = true;
				$orphans[] = $c;
				foreach ($refs($c) as $r) {
					$addCandidate($r);
				}
			}
		}
		return $orphans;
	}

	/**
	 * @param array<string, mixed> $m
	 */
	private function applyMetadata(EpubPackage $pkg, array $m): void {
		$dom = $pkg->dom;
		$metadata = $pkg->xp->query('//o:metadata')->item(0) ?? null;
		if (!$metadata instanceof DOMElement) {
			throw new EditorException('The package has no metadata.', 422);
		}
		$has = static fn (string $k): bool => array_key_exists($k, $m);
		$str = static function (string $k) use ($m): ?string {
			$v = $m[$k] ?? null;
			if (!is_scalar($v)) {
				return null;
			}
			$v = EditorUtil::xmlSafe(trim((string)$v));
			return $v === '' ? null : $v;
		};
		/** @return list<string> */
		$list = static function (string $k) use ($m): array {
			$v = $m[$k] ?? [];
			if (!is_array($v)) {
				return [];
			}
			$out = [];
			foreach ($v as $x) {
				if (is_scalar($x)) {
					$s = EditorUtil::xmlSafe(trim((string)$x));
					if ($s !== '' && !in_array($s, $out, true)) {
						$out[] = $s;
					}
				}
			}
			return $out;
		};

		if ($has('title')) {
			$this->setDc($pkg, $metadata, 'title', $str('title'), false);
		}
		if ($has('authors')) {
			foreach ($this->dcElements($metadata, 'creator') as $el) {
				$this->removeWithRefines($pkg, $el);
			}
			foreach ($list('authors') as $author) {
				$el = $dom->createElementNS(self::NS_DC, 'dc:creator');
				$this->setText($el, $author);
				if (!$pkg->isEpub3()) {
					$el->setAttributeNS(self::NS_OPF, 'opf:role', 'aut');
				}
				$metadata->appendChild($el);
			}
		}
		if ($has('description')) {
			$text = EditorUtil::htmlToText($str('description'));
			$this->setDc($pkg, $metadata, 'description', $text === '' ? null : $text, true);
		}
		if ($has('language')) {
			$this->setDc($pkg, $metadata, 'language', $str('language'), true);
		}
		if ($has('publisher')) {
			$this->setDc($pkg, $metadata, 'publisher', $str('publisher'), true);
		}
		if ($has('publishedAt')) {
			$this->setDc($pkg, $metadata, 'date', $str('publishedAt'), true);
		}
		if ($has('isbn')) {
			$this->setIsbn($pkg, $metadata, $str('isbn'));
		}
		if ($has('genres') || $has('tags')) {
			foreach ($this->dcElements($metadata, 'subject') as $el) {
				$this->removeWithRefines($pkg, $el);
			}
			$subjects = [];
			foreach (array_merge($list('genres'), $list('tags')) as $s) {
				if (!in_array($s, $subjects, true)) {
					$subjects[] = $s;
				}
			}
			foreach ($subjects as $s) {
				$el = $dom->createElementNS(self::NS_DC, 'dc:subject');
				$this->setText($el, $s);
				$metadata->appendChild($el);
			}
		}
		if ($has('series') || $has('seriesIndex')) {
			$this->setSeries($pkg, $metadata, $str('series'), isset($m['seriesIndex']) && is_numeric($m['seriesIndex']) ? (float)$m['seriesIndex'] : null);
		}
		// EPUB 3 requires an up-to-date modification date
		$mod = $pkg->xp->query("//o:meta[@property='dcterms:modified']");
		if ($mod !== false && $mod->length > 0 && $mod->item(0) instanceof DOMElement) {
			$this->setText($mod->item(0), gmdate('Y-m-d\TH:i:s\Z'));
		}
	}

	private function setText(DOMElement $el, string $text): void {
		while ($el->firstChild !== null) {
			$el->removeChild($el->firstChild);
		}
		$el->appendChild($el->ownerDocument->createTextNode($text));
	}

	/** @return list<DOMElement> */
	private function dcElements(DOMElement $metadata, string $local): array {
		$out = [];
		foreach ($metadata->getElementsByTagNameNS(self::NS_DC, $local) as $el) {
			if ($el instanceof DOMElement) {
				$out[] = $el;
			}
		}
		return $out;
	}

	private function setDc(EpubPackage $pkg, DOMElement $metadata, string $local, ?string $value, bool $dropExtras): void {
		$els = $this->dcElements($metadata, $local);
		if ($value === null) {
			if ($local !== 'title' || count($els) > 1) {
				foreach (($local === 'title' ? array_slice($els, 0, 1) : $els) as $el) {
					$this->removeWithRefines($pkg, $el);
				}
			}
			return;
		}
		if ($els === []) {
			$el = $pkg->dom->createElementNS(self::NS_DC, 'dc:' . $local);
			$this->setText($el, $value);
			$metadata->appendChild($el);
			return;
		}
		$this->setText($els[0], $value);
		if ($dropExtras) {
			foreach (array_slice($els, 1) as $extra) {
				$this->removeWithRefines($pkg, $extra);
			}
		}
	}

	private function removeWithRefines(EpubPackage $pkg, DOMElement $el): void {
		$id = $el->getAttribute('id');
		if ($id !== '' && preg_match('/^[A-Za-z0-9_.\-]+$/', $id) === 1) {
			$refs = $pkg->xp->query("//o:meta[@refines='#" . $id . "']");
			if ($refs !== false) {
				foreach (iterator_to_array($refs) as $r) {
					$r->parentNode?->removeChild($r);
				}
			}
		}
		$el->parentNode?->removeChild($el);
	}

	private function setIsbn(EpubPackage $pkg, DOMElement $metadata, ?string $isbn): void {
		$uid = $pkg->dom->documentElement?->getAttribute('unique-identifier') ?? '';
		$isbnEls = [];
		foreach ($this->dcElements($metadata, 'identifier') as $el) {
			$text = trim($el->textContent);
			$scheme = strtolower($el->getAttributeNS(self::NS_OPF, 'scheme'));
			if ($scheme === 'isbn' || preg_match('/^urn:isbn:/i', $text) === 1 || preg_match('/^[0-9][0-9Xx\- ]{8,16}$/', $text) === 1) {
				$isbnEls[] = $el;
			}
		}
		if ($isbn === null) {
			foreach ($isbnEls as $el) {
				if ($uid === '' || $el->getAttribute('id') !== $uid) {
					$this->removeWithRefines($pkg, $el);
				}
			}
			return;
		}
		if ($isbnEls === []) {
			$el = $pkg->dom->createElementNS(self::NS_DC, 'dc:identifier');
			$this->setText($el, 'urn:isbn:' . $isbn);
			$metadata->appendChild($el);
			return;
		}
		$first = $isbnEls[0];
		$plain = strtolower($first->getAttributeNS(self::NS_OPF, 'scheme')) === 'isbn' || preg_match('/^urn:isbn:/i', trim($first->textContent)) !== 1;
		$this->setText($first, ($plain ? '' : 'urn:isbn:') . $isbn);
		foreach (array_slice($isbnEls, 1) as $el) {
			if ($uid === '' || $el->getAttribute('id') !== $uid) {
				$this->removeWithRefines($pkg, $el);
			}
		}
	}

	private function setSeries(EpubPackage $pkg, DOMElement $metadata, ?string $series, ?float $index): void {
		$dom = $pkg->dom;
		$old = $pkg->xp->query("//o:metadata/o:meta[@name='calibre:series' or @name='calibre:series_index']");
		if ($old !== false) {
			foreach (iterator_to_array($old) as $el) {
				$el->parentNode?->removeChild($el);
			}
		}
		$old = $pkg->xp->query("//o:metadata/o:meta[@property='belongs-to-collection']");
		if ($old !== false) {
			foreach (iterator_to_array($old) as $el) {
				if ($el instanceof DOMElement) {
					$this->removeWithRefines($pkg, $el);
				}
			}
		}
		if ($series === null) {
			return;
		}
		$idx = $index === null ? null : rtrim(rtrim(sprintf('%.4F', $index), '0'), '.');
		$m = $dom->createElementNS(self::NS_OPF, 'meta');
		$m->setAttribute('name', 'calibre:series');
		$m->setAttribute('content', $series);
		$metadata->appendChild($m);
		if ($idx !== null) {
			$m = $dom->createElementNS(self::NS_OPF, 'meta');
			$m->setAttribute('name', 'calibre:series_index');
			$m->setAttribute('content', $idx);
			$metadata->appendChild($m);
		}
		if ($pkg->isEpub3()) {
			$m = $dom->createElementNS(self::NS_OPF, 'meta');
			$m->setAttribute('property', 'belongs-to-collection');
			$m->setAttribute('id', 'ebr-series');
			$this->setText($m, $series);
			$metadata->appendChild($m);
			$t = $dom->createElementNS(self::NS_OPF, 'meta');
			$t->setAttribute('refines', '#ebr-series');
			$t->setAttribute('property', 'collection-type');
			$this->setText($t, 'series');
			$metadata->appendChild($t);
			if ($idx !== null) {
				$g = $dom->createElementNS(self::NS_OPF, 'meta');
				$g->setAttribute('refines', '#ebr-series');
				$g->setAttribute('property', 'group-position');
				$this->setText($g, $idx);
				$metadata->appendChild($g);
			}
		}
	}

	// ------------------------------------------------------------------ toc write

	/**
	 * @param list<array<string, mixed>> $toc
	 * @param array<string, string> $idPath spine id => path
	 * @param array<string, true> $removedAll
	 * @return list<array{label: string, path: ?string, fragment: ?string, children: list<array<string, mixed>>}>
	 */
	private function buildTreeFromRequest(array $toc, array $idPath, EpubPackage $pkg, array $removedAll, int $depth = 0): array {
		if ($depth > 30) {
			throw new InvalidEditRequestException('The table of contents is nested too deeply.');
		}
		$pathIndex = $pkg->pathIndex();
		$out = [];
		foreach ($toc as $node) {
			if (!is_array($node)) {
				throw new InvalidEditRequestException('Invalid table of contents node.');
			}
			$label = EditorUtil::xmlSafe(EditorUtil::normalizeSpace((string)($node['label'] ?? '')));
			$path = null;
			$itemId = $node['itemId'] ?? null;
			if ($itemId !== null && $itemId !== '') {
				if (!is_string($itemId) || !isset($idPath[$itemId])) {
					throw new InvalidEditRequestException('Unknown item id in toc: ' . (is_scalar($itemId) ? (string)$itemId : '?'));
				}
				$path = $idPath[$itemId];
			} elseif (isset($node['href']) && is_string($node['href']) && isset($pathIndex[$node['href']])) {
				$path = $node['href'];
			}
			$fragment = isset($node['fragment']) && is_string($node['fragment']) && $node['fragment'] !== '' ? $node['fragment'] : null;
			$children = isset($node['children']) && is_array($node['children']) ? array_values($node['children']) : [];
			$childTree = $this->buildTreeFromRequest($children, $idPath, $pkg, $removedAll, $depth + 1);
			if ($path !== null && isset($removedAll[$path])) {
				array_push($out, ...$childTree);
				continue;
			}
			$out[] = ['label' => $label === '' ? ($path !== null ? basename($path) : '-') : $label, 'path' => $path, 'fragment' => $path === null ? null : $fragment, 'children' => $childTree];
		}
		return $out;
	}

	/**
	 * @param list<array<string, mixed>> $nodes
	 * @param array<string, true> $removedAll
	 * @return list<array{label: string, path: ?string, fragment: ?string, children: list<array<string, mixed>>}>
	 */
	private function pruneRaw(array $nodes, array $removedAll): array {
		$out = [];
		foreach ($nodes as $n) {
			/** @var list<array<string, mixed>> $children */
			$children = $n['children'] ?? [];
			$kids = $this->pruneRaw($children, $removedAll);
			$path = $n['path'] ?? null;
			if (is_string($path) && isset($removedAll[$path])) {
				array_push($out, ...$kids);
				continue;
			}
			$out[] = [
				'label' => (string)($n['label'] ?? ''),
				'path' => is_string($path) ? $path : null,
				'fragment' => isset($n['fragment']) && is_string($n['fragment']) ? $n['fragment'] : null,
				'children' => $kids,
			];
		}
		return $out;
	}

	/**
	 * @param list<array<string, mixed>> $tree
	 * @param array<string, true> $removedAll
	 * @param array<string, string> $overrides
	 * @param list<string> $warnings
	 */
	private function rewriteTocFiles(ZipArchive $zip, EpubPackage $pkg, array $tree, array $removedAll, array &$overrides, array &$warnings): void {
		$done = false;
		if ($pkg->navPath !== null && !isset($removedAll[$pkg->navPath])) {
			$xml = EditorUtil::readEntry($zip, $pkg->navPath);
			if ($xml !== null) {
				try {
					$overrides[$pkg->navPath] = $this->rewriteNav($xml, $pkg->navPath, $tree, $removedAll);
					$done = true;
				} catch (EditorException $e) {
					$warnings[] = 'Das Inhaltsverzeichnis (nav) konnte nicht neu geschrieben werden: ' . $e->getMessage();
				}
			}
		}
		if ($pkg->ncxPath !== null && !isset($removedAll[$pkg->ncxPath])) {
			$xml = EditorUtil::readEntry($zip, $pkg->ncxPath);
			if ($xml !== null) {
				try {
					$overrides[$pkg->ncxPath] = $this->rewriteNcx($xml, $pkg->ncxPath, $tree);
					$done = true;
				} catch (EditorException $e) {
					$warnings[] = 'Das Inhaltsverzeichnis (NCX) konnte nicht neu geschrieben werden: ' . $e->getMessage();
				}
			}
		}
		if (!$done) {
			$warnings[] = 'Dieses EPUB hat kein bearbeitbares Inhaltsverzeichnis; die Änderung wurde nicht übernommen.';
		}
	}

	/**
	 * @param list<array<string, mixed>> $tree
	 * @param array<string, true> $removedAll
	 */
	private function rewriteNav(string $xml, string $navPath, array $tree, array $removedAll): string {
		$dom = EditorUtil::loadXml($xml, 'Navigation document');
		$xp = new DOMXPath($dom);
		$xp->registerNamespace('x', self::NS_XHTML);
		$navs = $xp->query('//x:nav');
		if ($navs === false || $navs->length === 0) {
			throw new EditorException('no nav element', 422);
		}
		$toc = $this->findTocNav($navs);
		if ($toc === null) {
			throw new EditorException('no toc nav element', 422);
		}
		$dir = EditorUtil::dirName($navPath);
		$ol = $this->buildOl($dom, $tree, $dir);
		$existing = $xp->query('x:ol', $toc);
		if ($existing !== false && $existing->length > 0 && $existing->item(0) !== null) {
			$toc->replaceChild($ol, $existing->item(0));
			for ($i = 1; $i < $existing->length; $i++) {
				$extra = $existing->item($i);
				if ($extra !== null) {
					$toc->removeChild($extra);
				}
			}
		} else {
			$toc->appendChild($ol);
		}
		// landmarks / page-list: drop entries that point to removed documents
		foreach ($navs as $nav) {
			if ($nav === $toc || !$nav instanceof DOMElement) {
				continue;
			}
			$as = $xp->query('.//x:li[x:a]', $nav);
			if ($as === false) {
				continue;
			}
			foreach (iterator_to_array($as) as $li) {
				$a = $xp->query('x:a', $li);
				$aEl = $a !== false ? $a->item(0) : null;
				if ($aEl instanceof DOMElement) {
					[$p] = $this->splitHref($aEl->getAttribute('href'), $dir, $navPath);
					if ($p !== null && isset($removedAll[$p])) {
						$li->parentNode?->removeChild($li);
					}
				}
			}
		}
		return (string)$dom->saveXML();
	}

	/** @param list<array<string, mixed>> $nodes */
	private function buildOl(DOMDocument $dom, array $nodes, string $dir): DOMElement {
		$ol = $dom->createElementNS(self::NS_XHTML, 'ol');
		foreach ($nodes as $n) {
			$li = $dom->createElementNS(self::NS_XHTML, 'li');
			$path = $n['path'] ?? null;
			if (is_string($path)) {
				$el = $dom->createElementNS(self::NS_XHTML, 'a');
				$href = EditorUtil::encodeUrl(EditorUtil::relativePath($dir, $path));
				if (isset($n['fragment']) && is_string($n['fragment']) && $n['fragment'] !== '') {
					$href .= '#' . rawurlencode($n['fragment']);
				}
				$el->setAttribute('href', $href);
			} else {
				$el = $dom->createElementNS(self::NS_XHTML, 'span');
			}
			$this->setText($el, (string)($n['label'] ?? ''));
			$li->appendChild($el);
			/** @var list<array<string, mixed>> $children */
			$children = $n['children'] ?? [];
			if ($children !== []) {
				$li->appendChild($this->buildOl($dom, $children, $dir));
			}
			$ol->appendChild($li);
		}
		return $ol;
	}

	/** @param list<array<string, mixed>> $tree */
	private function rewriteNcx(string $xml, string $ncxPath, array $tree): string {
		$dom = EditorUtil::loadXml($xml, 'NCX');
		$xp = new DOMXPath($dom);
		$xp->registerNamespace('n', self::NS_NCX);
		$map = $xp->query('//n:navMap');
		if ($map === false || $map->length === 0 || !$map->item(0) instanceof DOMElement) {
			throw new EditorException('no navMap', 422);
		}
		$navMap = $map->item(0);
		$points = $xp->query('n:navPoint', $navMap);
		if ($points !== false) {
			foreach (iterator_to_array($points) as $p) {
				$navMap->removeChild($p);
			}
		}
		$order = 0;
		$maxDepth = 0;
		$dir = EditorUtil::dirName($ncxPath);
		$build = function (array $nodes, int $depth) use (&$build, $dom, $dir, &$order, &$maxDepth): array {
			$out = [];
			foreach ($nodes as $n) {
				/** @var list<array<string, mixed>> $children */
				$children = $n['children'] ?? [];
				$path = $n['path'] ?? null;
				if (!is_string($path)) {
					array_push($out, ...$build($children, $depth));
					continue;
				}
				$order++;
				$maxDepth = max($maxDepth, $depth);
				$pt = $dom->createElementNS(self::NS_NCX, 'navPoint');
				$pt->setAttribute('id', 'navPoint-' . $order);
				$pt->setAttribute('playOrder', (string)$order);
				$lab = $dom->createElementNS(self::NS_NCX, 'navLabel');
				$txt = $dom->createElementNS(self::NS_NCX, 'text');
				$txt->appendChild($dom->createTextNode((string)($n['label'] ?? '')));
				$lab->appendChild($txt);
				$pt->appendChild($lab);
				$content = $dom->createElementNS(self::NS_NCX, 'content');
				$src = EditorUtil::encodeUrl(EditorUtil::relativePath($dir, $path));
				if (isset($n['fragment']) && is_string($n['fragment']) && $n['fragment'] !== '') {
					$src .= '#' . rawurlencode($n['fragment']);
				}
				$content->setAttribute('src', $src);
				$pt->appendChild($content);
				foreach ($build($children, $depth + 1) as $child) {
					$pt->appendChild($child);
				}
				$out[] = $pt;
			}
			return $out;
		};
		foreach ($build($tree, 1) as $pt) {
			$navMap->appendChild($pt);
		}
		$depthMeta = $xp->query("//n:head/n:meta[@name='dtb:depth']");
		$depthEl = $depthMeta !== false ? $depthMeta->item(0) : null;
		if ($depthEl instanceof DOMElement) {
			$depthEl->setAttribute('content', (string)max(1, $maxDepth));
		}
		return (string)$dom->saveXML();
	}

	// ------------------------------------------------------------------ warnings

	/**
	 * @param array<string, true> $removedDocs
	 * @param array<string, true> $removedAll
	 * @return list<string>
	 */
	private function linkWarnings(ZipArchive $zip, EpubPackage $pkg, array $removedDocs, array $removedAll): array {
		$out = [];
		$total = 0;
		foreach ($pkg->manifest as $item) {
			if (isset($removedAll[$item['path']]) || $item['path'] === $pkg->navPath) {
				continue;
			}
			if (!in_array($item['type'], ['application/xhtml+xml', 'text/html'], true)) {
				continue;
			}
			$content = EditorUtil::readEntry($zip, $item['path']);
			if ($content === null) {
				continue;
			}
			$n = 0;
			try {
				$refs = EditorUtil::references($item['path'], $content);
			} catch (EditorException) {
				continue; // too large to scan; link warnings are best effort
			}
			foreach ($refs as $r) {
				if (isset($removedDocs[$r])) {
					$n++;
				}
			}
			if ($n > 0) {
				$total++;
				if (count($out) < self::MAX_WARNINGS) {
					$out[] = 'Die Datei "' . $item['path'] . '" enthält ' . $n . ' Link(s) auf entfernte Kapitel.';
				}
			}
		}
		if ($total > count($out)) {
			$out[] = 'Weitere ' . ($total - count($out)) . ' Datei(en) enthalten Links auf entfernte Kapitel.';
		}
		return $out;
	}
}
