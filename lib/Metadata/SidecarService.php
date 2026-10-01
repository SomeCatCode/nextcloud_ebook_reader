<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

use OCA\EbookReader\Editor\EditorUtil;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Sidecar metadata files ("Begleitdatei"): a hidden Calibre compatible OPF 2.0 file next to the book, named
 * "." + <full book file name> + ".opf" (".Golden Boy 01.cbz.opf").
 *
 * Writing uses the DOM (escaping), reading uses XmlUtil::load (no DTD / entities) and a size cap.
 */
class SidecarService {
	public const MAX_BYTES = 1024 * 1024;
	public const VERSION = '1';
	public const OPF_NS = 'http://www.idpf.org/2007/opf';
	public const DC_NS = 'http://purl.org/dc/elements/1.1/';

	/** Name of a sidecar: a dot, a book file name with a known extension, ".opf" */
	private const NAME_PATTERN = '/^\.(?<book>.+\.(?:epub|mobi|azw3|fb2|fbz|fb2\.zip|cbz|cbr|cb7|cbt))\.opf$/is';

	/** @var array<string, int> paths of sidecars this process is writing/moving/deleting right now (event loop guard) */
	private static array $guard = [];

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	// ------------------------------------------------------------------ names

	public static function nameFor(string $bookName): string {
		return '.' . $bookName . '.opf';
	}

	public static function isSidecarName(string $name): bool {
		return preg_match(self::NAME_PATTERN, $name) === 1;
	}

	/** File name of the book a sidecar belongs to, null if the name is not a sidecar name. */
	public static function bookNameOf(string $sidecarName): ?string {
		if (preg_match(self::NAME_PATTERN, $sidecarName, $m) !== 1) {
			return null;
		}
		return $m['book'];
	}

	/** True while this process itself changes the sidecar at that path (the file event listener ignores those events). */
	public static function isGuarded(string $path): bool {
		return isset(self::$guard[$path]);
	}

	/**
	 * @param callable(): void $fn
	 */
	private function guarded(string $path, callable $fn): void {
		self::$guard[$path] = (self::$guard[$path] ?? 0) + 1;
		try {
			$fn();
		} finally {
			if (--self::$guard[$path] <= 0) {
				unset(self::$guard[$path]);
			}
		}
	}

	// ------------------------------------------------------------------ lookup

	public function find(File $book): ?File {
		try {
			return $this->findIn($book->getParent(), $book->getName());
		} catch (\Throwable) {
			return null;
		}
	}

	public function findIn(Folder $parent, string $bookName): ?File {
		$name = self::nameFor($bookName);
		try {
			if (!$parent->nodeExists($name)) {
				return null;
			}
			$node = $parent->get($name);
		} catch (\Throwable) {
			return null;
		}
		return $node instanceof File && $node->isReadable() ? $node : null;
	}

	public function exists(File $book): bool {
		return $this->find($book) !== null;
	}

	/** Change marker of the book's sidecar (stored in books.sidecar_etag); null = no sidecar. */
	public function etagOf(File $book): ?string {
		return self::stateOf($this->find($book));
	}

	/** Same marker for an already known sidecar node (null = no sidecar). */
	public static function stateOf(?File $sidecar): ?string {
		if ($sidecar === null) {
			return null;
		}
		try {
			return substr($sidecar->getEtag() . ':' . $sidecar->getMTime(), 0, 64);
		} catch (\Throwable) {
			return null;
		}
	}

	// ------------------------------------------------------------------ read

	/** The sidecar of a book, null if there is none or it is unusable. */
	public function read(File $book): ?SidecarData {
		$sidecar = $this->find($book);
		if ($sidecar === null) {
			return null;
		}
		try {
			if ($sidecar->getSize() > self::MAX_BYTES) {
				$this->logger->info('Sidecar too large, ignored: ' . $sidecar->getName(), ['app' => 'ebookreader']);
				return null;
			}
			$content = $sidecar->getContent();
		} catch (\Throwable $e) {
			$this->logger->info('Sidecar not readable: ' . $e->getMessage(), ['app' => 'ebookreader']);
			return null;
		}
		return is_string($content) ? self::parse($content) : null;
	}

	/** Parses OPF content. Null for anything that is not a well-formed OPF package without DTD/entities, or is too large. */
	public static function parse(string $xml): ?SidecarData {
		if (strlen($xml) > self::MAX_BYTES) {
			return null;
		}
		$doc = XmlUtil::load($xml);
		if ($doc === null || $doc->documentElement === null || $doc->documentElement->localName !== 'package') {
			return null;
		}
		$xp = new \DOMXPath($doc);
		$md = "/*[local-name()='package']/*[local-name()='metadata']";
		$el = static fn (string $name): string => "$md/*[local-name()='$name']";
		$meta = static function (string $name) use ($xp, $md): ?string {
			$nodes = $xp->query("$md/*[local-name()='meta'][@name='" . $name . "']/@content");
			if ($nodes === false || $nodes->length === 0) {
				return null;
			}
			return XmlUtil::clean((string)$nodes->item(0)?->nodeValue);
		};

		$authors = [];
		$creators = $xp->query($el('creator'));
		foreach ($creators === false ? [] : $creators as $node) {
			if (!$node instanceof \DOMElement) {
				continue;
			}
			$role = strtolower(trim($node->getAttributeNS(self::OPF_NS, 'role') ?: $node->getAttribute('role')));
			$name = XmlUtil::clean($node->textContent);
			if ($name !== null && ($role === '' || $role === 'aut') && !in_array($name, $authors, true)) {
				$authors[] = $name;
			}
		}

		$description = null;
		$descNodes = $xp->query($el('description'));
		$descNode = $descNodes === false ? null : $descNodes->item(0);
		if ($descNode instanceof \DOMElement) {
			$raw = self::hasElementChild($descNode) ? XmlUtil::innerXml($descNode) : $descNode->textContent;
			$clean = HtmlSanitizer::sanitize($raw);
			$description = $clean === '' ? null : $clean;
		}

		$isbn = null;
		$uuid = null;
		$identifiers = $xp->query($el('identifier'));
		foreach ($identifiers === false ? [] : $identifiers as $node) {
			if (!$node instanceof \DOMElement) {
				continue;
			}
			$scheme = strtolower(trim($node->getAttributeNS(self::OPF_NS, 'scheme') ?: $node->getAttribute('scheme')));
			$value = XmlUtil::clean($node->textContent);
			if ($value === null) {
				continue;
			}
			if ($scheme === 'isbn' && $isbn === null) {
				$isbn = $value;
			}
			if ($node->getAttribute('id') === 'uuid_id' && $uuid === null) {
				$uuid = $value;
			}
		}

		$date = XmlUtil::text($xp, $el('date'));
		$publishedAt = null;
		if ($date !== null && preg_match('/^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?/', $date, $m) === 1) {
			$publishedAt = $m[1] . '-' . ($m[2] ?? '01') . '-' . ($m[3] ?? '01');
		}

		$index = $meta('calibre:series_index');
		$seriesIndex = $index !== null && is_numeric($index) ? (float)$index : null;

		$subjects = XmlUtil::texts($xp, $el('subject'));
		$genres = [];
		$genreMetas = $xp->query("$md/*[local-name()='meta'][@name='ebookreader:genre']/@content");
		foreach ($genreMetas === false ? [] : $genreMetas as $attr) {
			$g = XmlUtil::clean((string)$attr->nodeValue);
			if ($g !== null && !in_array($g, $genres, true)) {
				$genres[] = $g;
			}
		}
		$authoritative = $meta('ebookreader:version') !== null;
		$genreKeys = array_map('mb_strtolower', $genres);
		$tags = [];
		$plainSubjects = [];
		if ($authoritative || $genres !== []) {
			// written by this app: subjects that are no genre are tags
			foreach ($subjects as $s) {
				if (!in_array(mb_strtolower($s), $genreKeys, true) && !in_array($s, $tags, true)) {
					$tags[] = $s;
				}
			}
		} else {
			$plainSubjects = $subjects;
		}

		return new SidecarData(
			new BookMetadata(
				title: XmlUtil::text($xp, $el('title')),
				authors: $authors,
				series: $meta('calibre:series'),
				seriesIndex: $seriesIndex,
				description: $description,
				language: XmlUtil::text($xp, $el('language')),
				publisher: XmlUtil::text($xp, $el('publisher')),
				isbn: $isbn,
				publishedAt: $publishedAt,
				genres: $genres,
				tags: $tags,
				subjects: $plainSubjects,
			),
			$authoritative,
			$uuid,
		);
	}

	private static function hasElementChild(\DOMNode $node): bool {
		foreach ($node->childNodes as $c) {
			if ($c instanceof \DOMElement) {
				return true;
			}
		}
		return false;
	}

	// ------------------------------------------------------------------ build / write

	/**
	 * OPF 2.0 content for the given metadata (keys as in EditorService::metadataOf: title, authors, series, seriesIndex,
	 * description (HTML or text, written as plain text), language, publisher, isbn, publishedAt, genres, tags).
	 *
	 * @param array<string, mixed> $meta
	 */
	public static function build(array $meta, ?string $uuid = null): string {
		$doc = new \DOMDocument('1.0', 'UTF-8');
		$doc->formatOutput = true;
		$pkg = $doc->createElementNS(self::OPF_NS, 'package');
		$pkg->setAttribute('version', '2.0');
		$pkg->setAttribute('unique-identifier', 'uuid_id');
		$doc->appendChild($pkg);
		// created without namespace: libxml then writes them unprefixed and they inherit the default namespace of <package>
		$md = $doc->createElement('metadata');
		$md->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dc', self::DC_NS);
		$md->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:opf', self::OPF_NS);
		$pkg->appendChild($md);

		/**
		 * @param array<string, string> $nsAttrs attributes in the opf namespace
		 * @param array<string, string> $plainAttrs attributes without namespace
		 */
		$text = static function (string $name, string $value, array $nsAttrs = [], array $plainAttrs = []) use ($doc, $md): void {
			$e = $doc->createElementNS(self::DC_NS, 'dc:' . $name);
			foreach ($nsAttrs as $k => $v) {
				$e->setAttributeNS(self::OPF_NS, 'opf:' . $k, self::safe($v));
			}
			foreach ($plainAttrs as $k => $v) {
				$e->setAttribute($k, $v);
			}
			$e->appendChild($doc->createTextNode(self::safe($value)));
			$md->appendChild($e);
		};
		$metaTag = static function (string $name, string $content) use ($doc, $md): void {
			$e = $doc->createElement('meta');
			$e->setAttribute('name', $name);
			$e->setAttribute('content', self::safe($content));
			$md->appendChild($e);
		};
		$str = static function (mixed $v): ?string {
			if (!is_scalar($v)) {
				return null;
			}
			$s = trim(self::safe((string)$v));
			return $s === '' ? null : $s;
		};
		$list = static function (mixed $v) use ($str): array {
			$out = [];
			foreach (is_array($v) ? $v : [] as $x) {
				$s = $str($x);
				if ($s !== null && !in_array($s, $out, true)) {
					$out[] = $s;
				}
			}
			return $out;
		};

		$text('identifier', $uuid ?? self::uuid(), ['scheme' => 'uuid'], ['id' => 'uuid_id']);
		$title = $str($meta['title'] ?? null);
		if ($title !== null) {
			$text('title', $title);
		}
		foreach ($list($meta['authors'] ?? null) as $author) {
			$text('creator', $author, ['role' => 'aut']);
		}
		$description = $str(EditorUtil::htmlToText($str($meta['description'] ?? null)));
		if ($description !== null) {
			$text('description', $description);
		}
		$language = $str($meta['language'] ?? null);
		if ($language !== null) {
			$text('language', $language);
		}
		$publisher = $str($meta['publisher'] ?? null);
		if ($publisher !== null) {
			$text('publisher', $publisher);
		}
		$date = $str($meta['publishedAt'] ?? null);
		if ($date !== null) {
			$text('date', $date);
		}
		$isbn = $str($meta['isbn'] ?? null);
		if ($isbn !== null) {
			$text('identifier', $isbn, ['scheme' => 'ISBN']);
		}
		$genres = $list($meta['genres'] ?? null);
		$genreKeys = array_map('mb_strtolower', $genres);
		$tags = array_values(array_filter($list($meta['tags'] ?? null), static fn (string $t): bool => !in_array(mb_strtolower($t), $genreKeys, true)));
		foreach (array_merge($genres, $tags) as $subject) {
			$text('subject', $subject);
		}
		$series = $str($meta['series'] ?? null);
		if ($series !== null) {
			$metaTag('calibre:series', $series);
			$index = $meta['seriesIndex'] ?? null;
			if (is_numeric($index)) {
				$metaTag('calibre:series_index', rtrim(rtrim(sprintf('%.4F', (float)$index), '0'), '.'));
			}
		}
		foreach ($genres as $genre) {
			$metaTag('ebookreader:genre', $genre);
		}
		$metaTag('ebookreader:version', self::VERSION);

		$xml = $doc->saveXML();
		return $xml === false ? '' : $xml;
	}

	private static function safe(string $s): string {
		return EditorUtil::xmlSafe($s);
	}

	private static function uuid(): string {
		$b = random_bytes(16);
		$b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
		$b[8] = chr((ord($b[8]) & 0x3F) | 0x80);
		$h = bin2hex($b);
		return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
	}

	/**
	 * Writes the sidecar of a book. The file is only touched when its content changes.
	 *
	 * @param array<string, mixed> $meta see build()
	 * @param bool $createIfMissing false = only update an existing sidecar
	 * @return bool true if the sidecar holds this metadata afterwards (written or already identical, or nothing to do);
	 *              false if it could not be written (read-only folder or share)
	 */
	public function write(File $book, array $meta, bool $createIfMissing = true): bool {
		try {
			$parent = $book->getParent();
			$name = self::nameFor($book->getName());
			$existing = $this->findIn($parent, $book->getName());
			if ($existing === null && !$createIfMissing) {
				return true;
			}
			$uuid = null;
			$old = null;
			if ($existing !== null && $existing->getSize() <= self::MAX_BYTES) {
				$old = $existing->getContent();
				$uuid = is_string($old) ? self::parse($old)?->uuid : null;
			}
			$xml = self::build($meta, $uuid);
			if ($xml === '') {
				return false;
			}
			if ($existing !== null) {
				if ($old === $xml) {
					return true;
				}
				if (!$existing->isUpdateable()) {
					$this->logger->info('Sidecar is read-only: ' . $name, ['app' => 'ebookreader']);
					return false;
				}
				$this->guarded($existing->getPath(), static function () use ($existing, $xml): void {
					$existing->putContent($xml);
				});
				return true;
			}
			if (!$parent->isCreatable()) {
				$this->logger->info('Sidecar can not be created, folder is read-only: ' . $parent->getPath(), ['app' => 'ebookreader']);
				return false;
			}
			$this->guarded($parent->getPath() . '/' . $name, static function () use ($parent, $name, $xml): void {
				$parent->newFile($name, $xml);
			});
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Sidecar could not be written: ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
			return false;
		}
	}

	// ------------------------------------------------------------------ lifecycle

	/** Moves the sidecar of a book along when the book was renamed/moved. Skips if there is none or the target exists. */
	public function moveAlong(Folder $fromParent, string $fromName, Folder $toParent, string $toName): bool {
		return $this->transfer($fromParent, $fromName, $toParent, $toName, true);
	}

	/** Copies the sidecar of a book to the sidecar name of another book. Skips if there is none or the target exists. */
	public function copyAlong(Folder $fromParent, string $fromName, Folder $toParent, string $toName): bool {
		return $this->transfer($fromParent, $fromName, $toParent, $toName, false);
	}

	private function transfer(Folder $fromParent, string $fromName, Folder $toParent, string $toName, bool $move): bool {
		try {
			$sidecar = $this->findIn($fromParent, $fromName);
			if ($sidecar === null) {
				return false;
			}
			$targetName = self::nameFor($toName);
			$targetPath = $toParent->getPath() . '/' . $targetName;
			if ($sidecar->getPath() === $targetPath || $toParent->nodeExists($targetName)) {
				return false;
			}
			if ($move ? !$sidecar->isDeletable() : !$toParent->isCreatable()) {
				return false;
			}
			$this->guarded($sidecar->getPath(), function () use ($sidecar, $targetPath, $move): void {
				$this->guarded($targetPath, static function () use ($sidecar, $targetPath, $move): void {
					if ($move) {
						$sidecar->move($targetPath);
					} else {
						$sidecar->copy($targetPath);
					}
				});
			});
			return true;
		} catch (\Throwable $e) {
			$this->logger->info('Sidecar could not be ' . ($move ? 'moved' : 'copied') . ': ' . $e->getMessage(), ['app' => 'ebookreader']);
			return false;
		}
	}

	/** Deletes the sidecar of a book (goes to the trash bin like any file). */
	public function deleteFor(Folder $parent, string $bookName): bool {
		try {
			$sidecar = $this->findIn($parent, $bookName);
			if ($sidecar === null || !$sidecar->isDeletable()) {
				return false;
			}
			$this->guarded($sidecar->getPath(), static function () use ($sidecar): void {
				$sidecar->delete();
			});
			return true;
		} catch (NotFoundException) {
			return false;
		} catch (\Throwable $e) {
			$this->logger->info('Sidecar could not be deleted: ' . $e->getMessage(), ['app' => 'ebookreader']);
			return false;
		}
	}
}
