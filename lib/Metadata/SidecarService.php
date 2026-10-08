<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

use OCA\EbookReader\Editor\EditorUtil;
use OCA\EbookReader\Service\SettingsService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Sidecar metadata files ("Begleitdatei"): a Calibre compatible OPF 2.0 file for a book. Two layouts exist (user setting
 * "sidecarLocation"):
 *  - "beside" (default): a hidden file next to the book, named "." + <full book file name> + ".opf" (".Golden Boy 01.cbz.opf")
 *  - "meta": one hidden folder per directory, "<folder>/.meta/<full book file name>.opf" (".meta/Golden Boy 01.cbz.opf")
 *
 * Reading looks in both places (the configured one first). Writing goes to the configured place; a sidecar found in the
 * other place is moved there (never duplicated). The layout is the one the owner of the folder chose: the sidecar lives in
 * the owner's files. Empty ".meta" folders are removed when the last sidecar leaves.
 *
 * Writing uses the DOM (escaping), reading uses XmlUtil::load (no DTD / entities) and a size cap.
 */
class SidecarService {
	public const MAX_BYTES = 1024 * 1024;
	public const VERSION = '1';
	public const OPF_NS = 'http://www.idpf.org/2007/opf';
	public const DC_NS = 'http://purl.org/dc/elements/1.1/';

	public const LOCATION_BESIDE = 'beside';
	public const LOCATION_META = 'meta';
	/** Name of the hidden folder that holds the sidecars of its parent folder in the "meta" layout */
	public const META_DIR = '.meta';

	/** Name of a sidecar: a dot, a book file name with a known extension, ".opf" */
	private const NAME_PATTERN = '/^\.(?<book>.+\.(?:epub|mobi|azw3|fb2|fbz|fb2\.zip|cbz|cbr|cb7|cbt))\.opf$/is';
	/** Name of a sidecar inside a ".meta" folder: a book file name with a known extension, ".opf" */
	private const META_NAME_PATTERN = '/^(?<book>.+\.(?:epub|mobi|azw3|fb2|fbz|fb2\.zip|cbz|cbr|cb7|cbt))\.opf$/is';

	/** @var array<string, int> paths of sidecars this process is writing/moving/deleting right now (event loop guard) */
	private static array $guard = [];

	public function __construct(
		private LoggerInterface $logger,
		private ?SettingsService $settings = null,
	) {
	}

	// ------------------------------------------------------------------ names

	public static function nameFor(string $bookName): string {
		return '.' . $bookName . '.opf';
	}

	public static function isSidecarName(string $name): bool {
		return preg_match(self::NAME_PATTERN, $name) === 1;
	}

	/** Name of the sidecar of a book inside a ".meta" folder. */
	public static function metaNameFor(string $bookName): string {
		return $bookName . '.opf';
	}

	/** File name of the book a sidecar in a ".meta" folder belongs to, null if the name is not a sidecar name. */
	public static function bookNameOfMeta(string $sidecarName): ?string {
		if (preg_match(self::META_NAME_PATTERN, $sidecarName, $m) !== 1) {
			return null;
		}
		return $m['book'];
	}

	/**
	 * For a node that is a sidecar in either layout: the folder that holds its book and the book's file name; null if the
	 * node is no sidecar. (The book itself may not exist.)
	 *
	 * @return ?array{0: Folder, 1: string}
	 */
	public static function bookOf(File $sidecar): ?array {
		if (!str_ends_with(strtolower($sidecar->getName()), '.opf')) {
			return null; // cheap check first: this runs for every file event
		}
		try {
			$parent = $sidecar->getParent();
			if ($parent->getName() === self::META_DIR) {
				$book = self::bookNameOfMeta($sidecar->getName());
				return $book === null ? null : [$parent->getParent(), $book];
			}
			$book = self::bookNameOf($sidecar->getName());
			return $book === null ? null : [$parent, $book];
		} catch (\Throwable) {
			return null;
		}
	}

	/** Whether a file is a sidecar of either layout. */
	public static function isSidecarFile(File $file): bool {
		return self::bookOf($file) !== null;
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

	// ------------------------------------------------------------------ layout

	/** The layout the owner of the node chose ("beside" when unknown). */
	public function locationFor(Node $node): string {
		if ($this->settings === null) {
			return self::LOCATION_BESIDE;
		}
		try {
			$uid = $node->getOwner()?->getUID();
			return $uid === null || $uid === '' ? self::LOCATION_BESIDE : $this->settings->sidecarLocation($uid);
		} catch (\Throwable) {
			return self::LOCATION_BESIDE;
		}
	}

	/** @return list<string> both layouts, the configured one first */
	private function layouts(Node $node): array {
		return $this->locationFor($node) === self::LOCATION_META
			? [self::LOCATION_META, self::LOCATION_BESIDE]
			: [self::LOCATION_BESIDE, self::LOCATION_META];
	}

	/** The ".meta" folder of a folder; null if there is none (and $create is false or it can not be created). */
	private function metaDir(Folder $parent, bool $create = false): ?Folder {
		try {
			if ($parent->nodeExists(self::META_DIR)) {
				$node = $parent->get(self::META_DIR);
				return $node instanceof Folder ? $node : null;
			}
		} catch (\Throwable) {
			return null;
		}
		if (!$create || !$parent->isCreatable()) {
			return null;
		}
		$dir = null;
		$this->guarded($parent->getPath() . '/' . self::META_DIR, static function () use ($parent, &$dir): void {
			$dir = $parent->newFolder(self::META_DIR);
		});
		return $dir;
	}

	/** Removes the ".meta" folder of a folder once it is empty (it goes to the trash bin like any folder). */
	public function cleanupMeta(Folder $parent): void {
		$dir = $this->metaDir($parent);
		if ($dir !== null) {
			$this->dropIfEmpty($dir);
		}
	}

	/** Removes a ".meta" folder that holds nothing (any other folder is left alone). */
	private function dropIfEmpty(Folder $dir): void {
		try {
			if ($dir->getName() !== self::META_DIR || $dir->getDirectoryListing() !== [] || !$dir->isDeletable()) {
				return;
			}
			$this->guarded($dir->getPath(), static function () use ($dir): void {
				$dir->delete();
			});
		} catch (\Throwable $e) {
			$this->logger->info('Empty .meta folder not removed: ' . $e->getMessage(), ['app' => 'ebookreader']);
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
		return $this->locate($parent, $bookName)[0] ?? null;
	}

	/**
	 * The sidecar of a book in either layout (the configured layout wins when both exist) and the layout it was found in.
	 *
	 * @return ?array{0: File, 1: string}
	 */
	public function locate(Folder $parent, string $bookName): ?array {
		foreach ($this->layouts($parent) as $layout) {
			$node = $layout === self::LOCATION_META ? $this->findMeta($parent, $bookName) : $this->findBeside($parent, $bookName);
			if ($node !== null) {
				return [$node, $layout];
			}
		}
		return null;
	}

	private function findBeside(Folder $parent, string $bookName): ?File {
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

	private function findMeta(Folder $parent, string $bookName): ?File {
		$dir = $this->metaDir($parent);
		if ($dir === null) {
			return null;
		}
		$name = self::metaNameFor($bookName);
		try {
			if (!$dir->nodeExists($name)) {
				return null;
			}
			$node = $dir->get($name);
		} catch (\Throwable) {
			return null;
		}
		return $node instanceof File && $node->isReadable() ? $node : null;
	}

	/**
	 * The sidecars of all books in a folder from its directory listing (what a library scan has at hand): book file name
	 * => sidecar. Looks into the ".meta" folder with one listing; where both layouts hold a sidecar the configured one wins.
	 *
	 * @param iterable<Node> $children getDirectoryListing() of $folder
	 * @return array<string, File>
	 */
	public function inListing(Folder $folder, iterable $children): array {
		$beside = [];
		$meta = [];
		foreach ($children as $child) {
			if ($child instanceof File) {
				$book = self::bookNameOf($child->getName());
				if ($book !== null) {
					$beside[$book] = $child;
				}
			} elseif ($child instanceof Folder && $child->getName() === self::META_DIR) {
				try {
					foreach ($child->getDirectoryListing() as $entry) {
						$book = $entry instanceof File ? self::bookNameOfMeta($entry->getName()) : null;
						if ($book !== null && $entry instanceof File) {
							$meta[$book] = $entry;
						}
					}
				} catch (\Throwable $e) {
					$this->logger->info('.meta folder not listed: ' . $e->getMessage(), ['app' => 'ebookreader']);
				}
			}
		}
		if ($meta === []) {
			return $beside;
		}
		if ($beside === []) {
			return $meta;
		}
		$preferMeta = $this->locationFor($folder) === self::LOCATION_META;
		return $preferMeta ? $meta + $beside : $beside + $meta;
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
	 * Writes the sidecar of a book to the configured place (see locationFor()). The file is only touched when its content
	 * changes; a sidecar that exists in the other layout is moved to the configured place when it is written.
	 *
	 * @param array<string, mixed> $meta see build()
	 * @param bool $createIfMissing false = only update an existing sidecar
	 * @param bool $verifyState true = the existing sidecar must still be in $expectedState (see etagOf()); the metadata the
	 *                          caller passes is based on that state. Two users of a shared folder write the same file.
	 * @throws SidecarChangedException $verifyState is set and the sidecar changed in the meantime
	 * @return bool true if the sidecar holds this metadata afterwards (written or already identical, or nothing to do);
	 *              false if it could not be written (read-only folder or share)
	 */
	public function write(File $book, array $meta, bool $createIfMissing = true, ?string $expectedState = null, bool $verifyState = false): bool {
		try {
			$parent = $book->getParent();
			$bookName = $book->getName();
			$layout = $this->locationFor($parent);
			$located = $this->locate($parent, $bookName);
			$existing = $located[0] ?? null;
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
					$this->logger->info('Sidecar is read-only: ' . $existing->getName(), ['app' => 'ebookreader']);
					return false;
				}
				if ($verifyState && self::stateOf($existing) !== $expectedState) {
					throw new SidecarChangedException('Sidecar changed: ' . $existing->getName());
				}
				if (($located[1] ?? $layout) !== $layout) {
					// the node object follows the move; if it can not move, it is updated where it is
					$this->place($existing, $parent, $bookName, $layout, true);
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
			$container = $parent;
			$name = self::nameFor($bookName);
			if ($layout === self::LOCATION_META) {
				$container = $this->metaDir($parent, true);
				if ($container === null) {
					$this->logger->info('The .meta folder can not be created: ' . $parent->getPath(), ['app' => 'ebookreader']);
					return false;
				}
				$name = self::metaNameFor($bookName);
			}
			$this->guarded($container->getPath() . '/' . $name, static function () use ($container, $name, $xml): void {
				$container->newFile($name, $xml);
			});
			return true;
		} catch (SidecarChangedException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->warning('Sidecar could not be written: ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
			return false;
		}
	}

	// ------------------------------------------------------------------ lifecycle

	/**
	 * Moves the sidecar of a book along when the book was renamed/moved (to the configured layout of the target folder).
	 * Skips if there is none or the target exists.
	 */
	public function moveAlong(Folder $fromParent, string $fromName, Folder $toParent, string $toName): bool {
		return $this->transfer($fromParent, $fromName, $toParent, $toName, true);
	}

	/** Copies the sidecar of a book to the sidecar name of another book. Skips if there is none or the target exists. */
	public function copyAlong(Folder $fromParent, string $fromName, Folder $toParent, string $toName): bool {
		return $this->transfer($fromParent, $fromName, $toParent, $toName, false);
	}

	/**
	 * Moves the sidecar of a book to the given layout ("beside" or "meta") when it is in the other one (setting change).
	 * Does nothing if there is none, it is there already, or the target place holds a sidecar of the book already.
	 *
	 * @return bool whether a sidecar was moved
	 */
	public function relocate(File $book, string $layout): bool {
		try {
			$parent = $book->getParent();
			$name = $book->getName();
			$located = $this->locate($parent, $name);
			if ($located === null || $located[1] === $layout) {
				return false;
			}
			$here = $layout === self::LOCATION_META ? $this->findMeta($parent, $name) : $this->findBeside($parent, $name);
			if ($here !== null) {
				return false;
			}
			return $this->place($located[0], $parent, $name, $layout, true);
		} catch (\Throwable $e) {
			$this->logger->info('Sidecar could not be relocated: ' . $e->getMessage(), ['app' => 'ebookreader']);
			return false;
		}
	}

	private function transfer(Folder $fromParent, string $fromName, Folder $toParent, string $toName, bool $move): bool {
		try {
			$sidecar = $this->findIn($fromParent, $fromName);
			if ($sidecar === null) {
				return false;
			}
			$layout = $this->locationFor($toParent);
			// a target that has a sidecar in either layout keeps it
			$targetName = $layout === self::LOCATION_META ? self::metaNameFor($toName) : self::nameFor($toName);
			if ($layout === self::LOCATION_META) {
				$dir = $this->metaDir($toParent);
				if ($dir !== null && $dir->nodeExists($targetName)) {
					return false;
				}
			} elseif ($toParent->nodeExists($targetName)) {
				return false;
			}
			$other = $this->locate($toParent, $toName);
			if ($other !== null) {
				return false;
			}
			return $this->place($sidecar, $toParent, $toName, $layout, $move);
		} catch (\Throwable $e) {
			$this->logger->info('Sidecar could not be ' . ($move ? 'moved' : 'copied') . ': ' . $e->getMessage(), ['app' => 'ebookreader']);
			return false;
		}
	}

	/**
	 * Moves or copies a sidecar node to the sidecar place of the book $toName in $toParent, in the given layout.
	 * The caller made sure that the target is free.
	 */
	private function place(File $sidecar, Folder $toParent, string $toName, string $layout, bool $move): bool {
		$meta = $layout === self::LOCATION_META;
		$targetName = $meta ? self::metaNameFor($toName) : self::nameFor($toName);
		$containerPath = $toParent->getPath() . ($meta ? '/' . self::META_DIR : '');
		$targetPath = $containerPath . '/' . $targetName;
		if ($sidecar->getPath() === $targetPath) {
			return false;
		}
		if ($move ? !$sidecar->isDeletable() : !$toParent->isCreatable()) {
			return false;
		}
		if ($meta && $this->metaDir($toParent) === null && !$toParent->isCreatable()) {
			return false;
		}
		$source = $sidecar->getPath();
		$oldContainer = $move ? $sidecar->getParent() : null;
		$this->guarded($source, function () use ($sidecar, $toParent, $targetPath, $move, $meta): void {
			$this->guarded($targetPath, function () use ($sidecar, $toParent, $targetPath, $move, $meta): void {
				if ($meta && $this->metaDir($toParent, true) === null) {
					throw new \RuntimeException('The .meta folder can not be created');
				}
				if ($move) {
					$sidecar->move($targetPath);
				} else {
					$sidecar->copy($targetPath);
				}
			});
		});
		if ($oldContainer !== null) {
			// the last sidecar left its .meta folder
			$this->dropIfEmpty($oldContainer);
		}
		return true;
	}

	/** Deletes the sidecar of a book, wherever it is (goes to the trash bin like any file); removes an emptied .meta folder. */
	public function deleteFor(Folder $parent, string $bookName): bool {
		$deleted = false;
		try {
			foreach ($this->layouts($parent) as $layout) {
				$sidecar = $layout === self::LOCATION_META ? $this->findMeta($parent, $bookName) : $this->findBeside($parent, $bookName);
				if ($sidecar === null || !$sidecar->isDeletable()) {
					continue;
				}
				$this->guarded($sidecar->getPath(), static function () use ($sidecar): void {
					$sidecar->delete();
				});
				$deleted = true;
			}
		} catch (NotFoundException) {
			return $deleted;
		} catch (\Throwable $e) {
			$this->logger->info('Sidecar could not be deleted: ' . $e->getMessage(), ['app' => 'ebookreader']);
		}
		if ($deleted) {
			$this->cleanupMeta($parent);
		}
		return $deleted;
	}
}
