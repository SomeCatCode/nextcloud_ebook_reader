<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Metadata;

/** Safe XML helpers (XXE protection: no network, no entity substitution, no DTD entities). */
final class XmlUtil {
	/** Parses XML defensively; returns null if it is not well-formed or declares entities. */
	public static function load(string $xml): ?\DOMDocument {
		if ($xml === '') {
			return null;
		}
		if (str_starts_with($xml, "\xEF\xBB\xBF")) {
			$xml = substr($xml, 3);
		}
		if (stripos($xml, '<!ENTITY') !== false) {
			return null;
		}
		$prev = libxml_use_internal_errors(true);
		try {
			$doc = new \DOMDocument();
			$doc->resolveExternals = false;
			$doc->substituteEntities = false;
			$ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
			return $ok ? $doc : null;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($prev);
		}
	}

	/** Trimmed, whitespace-collapsed text of the first node of the query, or null if empty. */
	public static function text(\DOMXPath $xp, string $query, ?\DOMNode $ctx = null): ?string {
		$nodes = $ctx === null ? $xp->query($query) : $xp->query($query, $ctx);
		if ($nodes === false || $nodes->length === 0) {
			return null;
		}
		return self::clean((string)$nodes->item(0)?->textContent);
	}

	/** @return list<string> */
	public static function texts(\DOMXPath $xp, string $query, ?\DOMNode $ctx = null): array {
		$nodes = $ctx === null ? $xp->query($query) : $xp->query($query, $ctx);
		$out = [];
		if ($nodes === false) {
			return $out;
		}
		foreach ($nodes as $n) {
			$t = self::clean((string)$n->textContent);
			if ($t !== null) {
				$out[] = $t;
			}
		}
		return $out;
	}

	/** Collapses whitespace; null if empty. */
	public static function clean(string $s): ?string {
		$t = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
		return $t === '' ? null : $t;
	}

	/** Serialises the child nodes of an element (inner markup). */
	public static function innerXml(\DOMNode $node): string {
		$out = '';
		foreach ($node->childNodes as $child) {
			$s = $node->ownerDocument->saveXML($child);
			$out .= $s === false ? '' : $s;
		}
		return $out;
	}

	/** Splits a comma/semicolon separated list into trimmed unique values. */
	public static function splitList(?string $s): array {
		if ($s === null) {
			return [];
		}
		$out = [];
		foreach (preg_split('/[,;]/u', $s) ?: [] as $part) {
			$p = self::clean($part);
			if ($p !== null && !in_array($p, $out, true)) {
				$out[] = $p;
			}
		}
		return $out;
	}
}
