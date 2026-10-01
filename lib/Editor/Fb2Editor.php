<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Editor;

use DOMDocument;
use DOMElement;

/**
 * Edits FictionBook 2 files (.fb2 and zipped .fbz) via DOM manipulation.
 * Items are the top-level sections of the main body; the notes body is left untouched.
 */
final class Fb2Editor implements BookEditorInterface {
	private const NS_XLINK = 'http://www.w3.org/1999/xlink';
	private const MAX_BYTES = 200 * 1024 * 1024;

	private const DESCRIPTION_ORDER = ['title-info', 'src-title-info', 'document-info', 'publish-info', 'custom-info', 'output'];
	private const TITLE_INFO_ORDER = ['genre', 'author', 'book-title', 'annotation', 'keywords', 'date', 'coverpage', 'lang', 'src-lang', 'translator', 'sequence'];
	private const PUBLISH_ORDER = ['book-name', 'publisher', 'city', 'year', 'isbn', 'sequence'];

	private Fb2Genres $genres;

	public function __construct(?Fb2Genres $genres = null) {
		$this->genres = $genres ?? new Fb2Genres();
	}

	#[\Override]
	public function supports(string $format): bool {
		return $format === 'fb2' || $format === 'fbz';
	}

	#[\Override]
	public function readStructure(string $localPath, string $format): array {
		[$dom] = $this->load($localPath, $format);
		$root = $dom->documentElement;
		if (!$root instanceof DOMElement) {
			throw new EditorException('Invalid FB2 document.', 422);
		}
		$bodyIndex = 0;
		$main = $this->mainBody($root, $bodyIndex);
		$items = [];
		$toc = [];
		if ($main !== null) {
			$n = 0;
			foreach ($this->children($main, 'section') as $i => $section) {
				$n++;
				$items[] = [
					'id' => 'b' . $bodyIndex . '/s' . $i,
					'label' => $this->sectionTitle($section) ?? 'Abschnitt ' . $n,
					'href' => 'b' . $bodyIndex . '/s' . $i,
					'kind' => 'chapter',
					'linear' => true,
					'size' => mb_strlen($section->textContent),
				];
			}
			$counter = 0;
			$toc = $this->tocOf($main, 'b' . $bodyIndex, $counter);
		}
		return [
			'format' => $format,
			'capabilities' => ['metadata' => true, 'cover' => true, 'content' => true, 'toc' => true, 'writesFile' => true],
			'metadata' => $this->readMetadata($root),
			'items' => $items,
			'toc' => $toc,
			'warnings' => [],
		];
	}

	#[\Override]
	public function write(string $srcPath, string $dstPath, EditRequest $req): array {
		[$dom, $innerName] = $this->load($srcPath, str_ends_with(strtolower($srcPath), '.fbz') || $this->isZip($srcPath) ? 'fbz' : 'fb2');
		$root = $dom->documentElement;
		if (!$root instanceof DOMElement) {
			throw new EditorException('Invalid FB2 document.', 422);
		}
		$warnings = [];
		if ($req->isMetadataOnly()) {
			// fast path: only the description is replaced, sections are not looked at
			array_push($warnings, ...$this->applyMetadata($dom, $root, $req->metadata ?? []));
			$xml = (string)$dom->saveXML();
			if (!EditorUtil::isWellFormed($xml)) {
				throw new EditorException('The rewritten FB2 document is not well-formed.', 500);
			}
			$this->writeDocument($xml, $innerName, $dstPath);
			return ['warnings' => $warnings, 'itemMap' => []];
		}
		$bodyIndex = 0;
		$main = $this->mainBody($root, $bodyIndex);
		$prefix = 'b' . $bodyIndex;

		$sections = $main === null ? [] : $this->children($main, 'section');
		$ids = [];
		foreach (array_keys($sections) as $i) {
			$ids[] = $prefix . '/s' . $i;
		}
		$res = EditRequestValidator::resolve($ids, $req);
		if ($ids !== [] && $res['order'] === []) {
			throw new InvalidEditRequestException('At least one section must remain.');
		}

		// section titles (before structural changes, ids refer to the original document)
		if ($req->toc !== null && $main !== null) {
			$all = [];
			$this->collectSections($main, $prefix, $all);
			$seenNodes = 0;
			$nestingChanged = false;
			$this->applyTocTitles($req->toc, $all, null, $seenNodes, $nestingChanged, $dom);
			if ($nestingChanged || $seenNodes < count($all)) {
				$warnings[] = 'Bei FB2 ergibt sich das Inhaltsverzeichnis aus den Abschnitten; nur die Titel wurden übernommen.';
			}
		}

		// remove / reorder
		if ($main !== null && $res['changed']) {
			$byId = array_combine($ids, $sections);
			foreach ($res['removed'] as $id) {
				$main->removeChild($byId[$id]);
			}
			foreach ($res['order'] as $id) {
				$main->appendChild($byId[$id]);
			}
		}
		$itemMap = [];
		$newPos = array_flip($res['order']);
		foreach ($ids as $id) {
			$itemMap[$id] = isset($newPos[$id]) ? $prefix . '/s' . $newPos[$id] : null;
		}

		if ($req->metadata !== null) {
			array_push($warnings, ...$this->applyMetadata($dom, $root, $req->metadata));
		}
		if ($req->cover !== null) {
			$this->applyCover($dom, $root, $req->cover);
		}

		$xml = (string)$dom->saveXML();
		if (!EditorUtil::isWellFormed($xml)) {
			throw new EditorException('The rewritten FB2 document is not well-formed.', 500);
		}

		$fmt = $this->writeDocument($xml, $innerName, $dstPath);
		$check = $this->readStructure($dstPath, $fmt);
		if (count($check['items']) !== count($res['order'])) {
			throw new EditorException('Verification of the rewritten FB2 failed (section count).', 500);
		}
		return ['warnings' => $warnings, 'itemMap' => $itemMap];
	}

	/** @return string the written format ("fb2" or "fbz") */
	private function writeDocument(string $xml, ?string $innerName, string $dstPath): string {
		if ($innerName === null) {
			if (file_put_contents($dstPath, $xml) === false) {
				throw new EditorException('Cannot write the output file.', 500);
			}
			return 'fb2';
		}
		$w = new ZipWriter($dstPath);
		try {
			$w->addString($innerName, $xml);
			$w->close();
		} catch (\Throwable $e) {
			$w->abort();
			throw $e;
		}
		return 'fbz';
	}

	// ------------------------------------------------------------------ loading

	private function isZip(string $path): bool {
		$h = @file_get_contents($path, false, null, 0, 4);
		return $h === "PK\x03\x04";
	}

	/** @return array{0: DOMDocument, 1: ?string} document and inner file name (fbz only) */
	private function load(string $path, string $format): array {
		$inner = null;
		if ($format === 'fbz' || $this->isZip($path)) {
			$zip = EditorUtil::openZip($path);
			try {
				for ($i = 0; $i < $zip->numFiles; $i++) {
					$name = (string)$zip->getNameIndex($i);
					if (EditorUtil::isSafeName($name) && str_ends_with(strtolower($name), '.fb2')) {
						$inner = $name;
						break;
					}
				}
				if ($inner === null) {
					throw new EditorException('The archive does not contain an .fb2 file.', 422);
				}
				$xml = EditorUtil::readEntry($zip, $inner, self::MAX_BYTES);
			} finally {
				$zip->close();
			}
		} else {
			if (filesize($path) > self::MAX_BYTES) {
				throw new EditorException('The FB2 file is too large.', 413);
			}
			$xml = file_get_contents($path);
		}
		if ($xml === null || $xml === false) {
			throw new EditorException('The FB2 file cannot be read.', 422);
		}
		$dom = EditorUtil::loadXml($xml, 'FB2');
		if ($dom->documentElement === null || $dom->documentElement->localName !== 'FictionBook') {
			throw new EditorException('Not a FictionBook document.', 422);
		}
		return [$dom, $inner];
	}

	// ------------------------------------------------------------------ dom helpers

	/** @return list<DOMElement> */
	private function children(DOMElement $parent, string $name): array {
		$out = [];
		foreach ($parent->childNodes as $c) {
			if ($c instanceof DOMElement && $c->localName === $name) {
				$out[] = $c;
			}
		}
		return $out;
	}

	private function child(DOMElement $parent, string $name): ?DOMElement {
		return $this->children($parent, $name)[0] ?? null;
	}

	private function mk(DOMDocument $dom, string $name): DOMElement {
		$ns = $dom->documentElement?->namespaceURI;
		return $ns !== null && $ns !== '' ? $dom->createElementNS($ns, $name) : $dom->createElement($name);
	}

	private function setText(DOMElement $el, string $text): void {
		while ($el->firstChild !== null) {
			$el->removeChild($el->firstChild);
		}
		$el->appendChild($el->ownerDocument->createTextNode($text));
	}

	private function mainBody(DOMElement $root, int &$index): ?DOMElement {
		$bodies = $this->children($root, 'body');
		foreach ($bodies as $i => $b) {
			if ($b->getAttribute('name') === '') {
				$index = $i;
				return $b;
			}
		}
		$index = 0;
		return $bodies[0] ?? null;
	}

	private function sectionTitle(DOMElement $section): ?string {
		$t = $this->child($section, 'title');
		if ($t === null) {
			return null;
		}
		$s = EditorUtil::normalizeSpace($t->textContent);
		return $s === '' ? null : $s;
	}

	/** @return list<array<string, mixed>> */
	private function tocOf(DOMElement $parent, string $prefix, int &$counter): array {
		$out = [];
		foreach ($this->children($parent, 'section') as $i => $section) {
			$counter++;
			$id = $prefix . '/s' . $i;
			$out[] = [
				'id' => 't' . $counter,
				'label' => $this->sectionTitle($section) ?? 'Abschnitt ' . ($i + 1),
				'itemId' => $id,
				'fragment' => null,
				'children' => $this->tocOf($section, $id, $counter),
			];
		}
		return $out;
	}

	/** @param array<string, DOMElement> $out */
	private function collectSections(DOMElement $parent, string $prefix, array &$out): void {
		foreach ($this->children($parent, 'section') as $i => $section) {
			$id = $prefix . '/s' . $i;
			$out[$id] = $section;
			$this->collectSections($section, $id, $out);
		}
	}

	/**
	 * @param list<array<string, mixed>> $nodes
	 * @param array<string, DOMElement> $all
	 */
	private function applyTocTitles(array $nodes, array $all, ?string $parentId, int &$seen, bool &$nestingChanged, DOMDocument $dom, int $depth = 0): void {
		if ($depth > 50) {
			throw new InvalidEditRequestException('The table of contents is nested too deeply.');
		}
		foreach ($nodes as $n) {
			if (!is_array($n)) {
				throw new InvalidEditRequestException('Invalid table of contents node.');
			}
			$itemId = $n['itemId'] ?? null;
			$children = isset($n['children']) && is_array($n['children']) ? array_values($n['children']) : [];
			$myId = $parentId;
			if (is_string($itemId) && $itemId !== '') {
				if (!isset($all[$itemId])) {
					throw new InvalidEditRequestException('Unknown item id in toc: ' . $itemId);
				}
				$seen++;
				$expectedParent = substr_count($itemId, '/') >= 2 ? substr($itemId, 0, (int)strrpos($itemId, '/')) : null;
				if ($expectedParent !== $parentId) {
					$nestingChanged = true;
				}
				$label = EditorUtil::xmlSafe(EditorUtil::normalizeSpace((string)($n['label'] ?? '')));
				if ($label !== '') {
					$this->setSectionTitle($dom, $all[$itemId], $label);
				}
				$myId = $itemId;
			} else {
				$nestingChanged = true;
			}
			$this->applyTocTitles($children, $all, $myId, $seen, $nestingChanged, $dom, $depth + 1);
		}
	}

	private function setSectionTitle(DOMDocument $dom, DOMElement $section, string $label): void {
		$title = $this->child($section, 'title');
		if ($title !== null && $this->sectionTitle($section) === $label) {
			return;
		}
		if ($title === null) {
			$title = $this->mk($dom, 'title');
			$section->insertBefore($title, $section->firstChild);
		} else {
			while ($title->firstChild !== null) {
				$title->removeChild($title->firstChild);
			}
		}
		$p = $this->mk($dom, 'p');
		$this->setText($p, $label);
		$title->appendChild($p);
	}

	private function insertOrdered(DOMElement $parent, DOMElement $new, array $order): void {
		$pos = array_search($new->localName, $order, true);
		if ($pos === false) {
			$parent->appendChild($new);
			return;
		}
		foreach ($parent->childNodes as $c) {
			if ($c instanceof DOMElement) {
				$cp = array_search($c->localName, $order, true);
				if ($cp !== false && $cp > $pos) {
					$parent->insertBefore($new, $c);
					return;
				}
			}
		}
		$parent->appendChild($new);
	}

	private function ensureChild(DOMDocument $dom, DOMElement $parent, string $name, array $order): DOMElement {
		$el = $this->child($parent, $name);
		if ($el === null) {
			$el = $this->mk($dom, $name);
			$this->insertOrdered($parent, $el, $order);
		}
		return $el;
	}

	private function removeAll(DOMElement $parent, string $name): void {
		foreach ($this->children($parent, $name) as $el) {
			$parent->removeChild($el);
		}
	}

	// ------------------------------------------------------------------ metadata

	/** @return array<string, mixed> */
	private function readMetadata(DOMElement $root): array {
		$desc = $this->child($root, 'description');
		$ti = $desc !== null ? $this->child($desc, 'title-info') : null;
		$pi = $desc !== null ? $this->child($desc, 'publish-info') : null;
		$text = static function (?DOMElement $p, string $name, self $self): ?string {
			$el = $p !== null ? $self->child($p, $name) : null;
			if ($el === null) {
				return null;
			}
			$v = EditorUtil::normalizeSpace($el->textContent);
			return $v === '' ? null : $v;
		};
		$authors = [];
		$genres = [];
		$series = null;
		$index = null;
		$desc_ = null;
		$date = null;
		if ($ti !== null) {
			foreach ($this->children($ti, 'author') as $a) {
				$parts = [];
				foreach (['first-name', 'middle-name', 'last-name'] as $n) {
					$v = $text($a, $n, $this);
					if ($v !== null) {
						$parts[] = $v;
					}
				}
				$name = $parts !== [] ? implode(' ', $parts) : $text($a, 'nickname', $this);
				if ($name !== null) {
					$authors[] = $name;
				}
			}
			foreach ($this->children($ti, 'genre') as $g) {
				$code = trim($g->textContent);
				if ($code !== '') {
					$label = $this->genres->labelFor($code);
					if (!in_array($label, $genres, true)) {
						$genres[] = $label;
					}
				}
			}
			$seq = $this->child($ti, 'sequence');
			if ($seq !== null && trim($seq->getAttribute('name')) !== '') {
				$series = trim($seq->getAttribute('name'));
				$num = trim($seq->getAttribute('number'));
				$index = is_numeric($num) ? (float)$num : null;
			}
			$ann = $this->child($ti, 'annotation');
			if ($ann !== null) {
				$paras = [];
				foreach ($ann->childNodes as $c) {
					if ($c instanceof DOMElement) {
						$v = EditorUtil::normalizeSpace($c->textContent);
						if ($v !== '') {
							$paras[] = $v;
						}
					}
				}
				$desc_ = $paras === [] ? null : implode("\n\n", $paras);
			}
			$d = $this->child($ti, 'date');
			if ($d !== null) {
				$date = trim($d->getAttribute('value')) !== '' ? trim($d->getAttribute('value')) : EditorUtil::normalizeSpace($d->textContent);
				$date = $date === '' ? null : substr($date, 0, 10);
			}
		}
		$keywords = $text($ti, 'keywords', $this);
		$tags = [];
		foreach (preg_split('/\s*[,;]\s*/', (string)$keywords) ?: [] as $k) {
			if ($k !== '' && !in_array($k, $tags, true)) {
				$tags[] = $k;
			}
		}
		return [
			'title' => $text($ti, 'book-title', $this),
			'authors' => $authors,
			'series' => $series,
			'seriesIndex' => $index,
			'description' => $desc_,
			'language' => $text($ti, 'lang', $this),
			'publisher' => $text($pi, 'publisher', $this),
			'isbn' => $text($pi, 'isbn', $this),
			'publishedAt' => $date,
			'genres' => $genres,
			'tags' => $tags,
		];
	}

	/**
	 * @param array<string, mixed> $m
	 * @return list<string> warnings
	 */
	private function applyMetadata(DOMDocument $dom, DOMElement $root, array $m): array {
		$warnings = [];
		$desc = $this->child($root, 'description');
		if ($desc === null) {
			$desc = $this->mk($dom, 'description');
			$root->insertBefore($desc, $root->firstChild);
		}
		$ti = $this->ensureChild($dom, $desc, 'title-info', self::DESCRIPTION_ORDER);

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
			$out = [];
			foreach (is_array($v) ? $v : [] as $x) {
				if (is_scalar($x)) {
					$s = EditorUtil::xmlSafe(trim((string)$x));
					if ($s !== '' && !in_array($s, $out, true)) {
						$out[] = $s;
					}
				}
			}
			return $out;
		};

		if ($has('title') && $str('title') !== null) {
			$el = $this->ensureChild($dom, $ti, 'book-title', self::TITLE_INFO_ORDER);
			$this->setText($el, (string)$str('title'));
		}
		if ($has('authors') && $list('authors') !== []) {
			$this->removeAll($ti, 'author');
			foreach ($list('authors') as $name) {
				$this->insertOrdered($ti, $this->buildAuthor($dom, $name), self::TITLE_INFO_ORDER);
			}
		}
		if ($has('description')) {
			$this->removeAll($ti, 'annotation');
			$text = EditorUtil::htmlToText($str('description'));
			if ($text !== '') {
				$ann = $this->mk($dom, 'annotation');
				foreach (preg_split("/\n{2,}/", $text) ?: [] as $para) {
					$p = $this->mk($dom, 'p');
					$this->setText($p, str_replace("\n", ' ', trim($para)));
					$ann->appendChild($p);
				}
				$this->insertOrdered($ti, $ann, self::TITLE_INFO_ORDER);
			}
		}
		if ($has('language') && $str('language') !== null) {
			$el = $this->ensureChild($dom, $ti, 'lang', self::TITLE_INFO_ORDER);
			$this->setText($el, (string)$str('language'));
		}
		if ($has('publishedAt')) {
			$this->removeAll($ti, 'date');
			$d = $str('publishedAt');
			if ($d !== null) {
				$el = $this->mk($dom, 'date');
				if (preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $d) === 1) {
					$el->setAttribute('value', $d);
				}
				$this->setText($el, $d);
				$this->insertOrdered($ti, $el, self::TITLE_INFO_ORDER);
			}
		}
		if ($has('series') || $has('seriesIndex')) {
			$this->removeAll($ti, 'sequence');
			$series = $str('series');
			if ($series !== null) {
				$el = $this->mk($dom, 'sequence');
				$el->setAttribute('name', $series);
				$idx = $m['seriesIndex'] ?? null;
				if (is_numeric($idx)) {
					$el->setAttribute('number', (string)(int)floor((float)$idx));
					if ((float)$idx !== floor((float)$idx)) {
						$warnings[] = 'FB2 kennt nur ganzzahlige Seriennummern; der Band wurde abgerundet.';
					}
				}
				$this->insertOrdered($ti, $el, self::TITLE_INFO_ORDER);
			}
		}
		if ($has('genres') || $has('tags')) {
			$codes = [];
			$keywords = $list('tags');
			foreach ($list('genres') as $label) {
				$code = $this->genres->codeFor($label);
				if ($code !== null) {
					if (!in_array($code, $codes, true)) {
						$codes[] = $code;
					}
				} elseif (!in_array($label, $keywords, true)) {
					$keywords[] = $label;
					$warnings[] = 'Das Genre "' . $label . '" ist kein FB2-Genre und wurde als Schlagwort gespeichert.';
				}
			}
			if ($codes !== []) {
				$this->removeAll($ti, 'genre');
				foreach ($codes as $code) {
					$g = $this->mk($dom, 'genre');
					$this->setText($g, $code);
					$this->insertOrdered($ti, $g, self::TITLE_INFO_ORDER);
				}
			} elseif ($this->children($ti, 'genre') === []) {
				$g = $this->mk($dom, 'genre');
				$this->setText($g, 'unrecognised');
				$this->insertOrdered($ti, $g, self::TITLE_INFO_ORDER);
			}
			$this->removeAll($ti, 'keywords');
			if ($keywords !== []) {
				$k = $this->mk($dom, 'keywords');
				$this->setText($k, implode(', ', $keywords));
				$this->insertOrdered($ti, $k, self::TITLE_INFO_ORDER);
			}
		}
		if ($has('publisher') || $has('isbn')) {
			$pi = $this->child($desc, 'publish-info');
			if ($pi === null && (($has('publisher') && $str('publisher') !== null) || ($has('isbn') && $str('isbn') !== null))) {
				$pi = $this->ensureChild($dom, $desc, 'publish-info', self::DESCRIPTION_ORDER);
			}
			if ($pi !== null) {
				foreach (['publisher' => 'publisher', 'isbn' => 'isbn'] as $key => $name) {
					if (!$has($key)) {
						continue;
					}
					$v = $str($key);
					if ($v === null) {
						$this->removeAll($pi, $name);
					} else {
						$el = $this->ensureChild($dom, $pi, $name, self::PUBLISH_ORDER);
						$this->setText($el, $v);
					}
				}
			}
		}
		return $warnings;
	}

	private function buildAuthor(DOMDocument $dom, string $name): DOMElement {
		$a = $this->mk($dom, 'author');
		$first = '';
		$last = '';
		if (str_contains($name, ',')) {
			[$last, $first] = array_map('trim', explode(',', $name, 2));
		} else {
			$tokens = preg_split('/\s+/', $name) ?: [$name];
			if (count($tokens) === 1) {
				$nick = $this->mk($dom, 'nickname');
				$this->setText($nick, $name);
				$a->appendChild($nick);
				return $a;
			}
			$last = (string)array_pop($tokens);
			$first = implode(' ', $tokens);
		}
		$f = $this->mk($dom, 'first-name');
		$this->setText($f, $first);
		$l = $this->mk($dom, 'last-name');
		$this->setText($l, $last);
		$a->appendChild($f);
		$a->appendChild($l);
		return $a;
	}

	// ------------------------------------------------------------------ cover

	/** @param array<string, mixed> $cover */
	private function applyCover(DOMDocument $dom, DOMElement $root, array $cover): void {
		if (($cover['source'] ?? '') !== 'upload') {
			throw new InvalidEditRequestException('FB2 covers can only be uploaded.');
		}
		$img = EditorUtil::decodeCoverUpload($cover);
		$desc = $this->child($root, 'description');
		$ti = $desc !== null ? $this->child($desc, 'title-info') : null;
		if ($ti === null) {
			throw new EditorException('The FB2 file has no title-info.', 422);
		}
		$oldIds = [];
		foreach ($this->children($ti, 'coverpage') as $cp) {
			foreach ($cp->getElementsByTagName('image') as $im) {
				$href = $im->getAttributeNS(self::NS_XLINK, 'href');
				if ($href === '') {
					$href = $im->getAttribute('href');
				}
				if (str_starts_with($href, '#')) {
					$oldIds[] = substr($href, 1);
				}
			}
			$ti->removeChild($cp);
		}
		$existing = [];
		foreach ($this->children($root, 'binary') as $b) {
			$existing[$b->getAttribute('id')] = $b;
		}
		$id = 'cover-ebr.' . $img['ext'];
		while (isset($existing[$id]) && !in_array($id, $oldIds, true)) {
			$id = 'cover-ebr-' . bin2hex(random_bytes(2)) . '.' . $img['ext'];
		}

		// drop old cover binaries that nothing else references
		$still = [];
		foreach ($dom->getElementsByTagName('*') as $el) {
			foreach (['href'] as $attr) {
				$v = $el->getAttributeNS(self::NS_XLINK, $attr);
				if ($v === '' && $el->hasAttribute($attr)) {
					$v = $el->getAttribute($attr);
				}
				if (str_starts_with($v, '#')) {
					$still[substr($v, 1)] = true;
				}
			}
		}
		foreach ($oldIds as $old) {
			if (isset($existing[$old]) && !isset($still[$old])) {
				$root->removeChild($existing[$old]);
				unset($existing[$old]);
			}
		}
		if (isset($existing[$id])) {
			$root->removeChild($existing[$id]);
		}

		$binary = $this->mk($dom, 'binary');
		$binary->setAttribute('id', $id);
		$binary->setAttribute('content-type', $img['mime']);
		$this->setText($binary, rtrim(chunk_split(base64_encode($img['data']), 76, "\n")));
		$root->appendChild($binary);

		$cp = $this->mk($dom, 'coverpage');
		$im = $this->mk($dom, 'image');
		$im->setAttributeNS(self::NS_XLINK, 'l:href', '#' . $id);
		$cp->appendChild($im);
		$this->insertOrdered($ti, $cp, self::TITLE_INFO_ORDER);
	}
}
