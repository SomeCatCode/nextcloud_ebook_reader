<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Editor;

/**
 * Validates item ids / order of an EditRequest against the existing items.
 */
final class EditRequestValidator {
	/**
	 * @param list<string> $ids existing item ids in current order
	 * @return array{order: list<string>, removed: list<string>, changed: bool}
	 * @throws InvalidEditRequestException
	 */
	public static function resolve(array $ids, EditRequest $req): array {
		$known = array_fill_keys($ids, true);

		$removed = [];
		foreach ($req->removed ?? [] as $id) {
			if (!isset($known[$id])) {
				throw new InvalidEditRequestException('Unknown item id in "removed": ' . $id);
			}
			$removed[$id] = true;
		}

		$remaining = array_values(array_filter($ids, static fn (string $id): bool => !isset($removed[$id])));
		$removedList = array_map('strval', array_keys($removed));

		if ($req->order === null) {
			return ['order' => $remaining, 'removed' => $removedList, 'changed' => $removed !== []];
		}

		$seen = [];
		foreach ($req->order as $id) {
			if (!isset($known[$id])) {
				throw new InvalidEditRequestException('Unknown item id in "order": ' . $id);
			}
			if (isset($removed[$id])) {
				throw new InvalidEditRequestException('Removed item id in "order": ' . $id);
			}
			if (isset($seen[$id])) {
				throw new InvalidEditRequestException('Duplicate item id in "order": ' . $id);
			}
			$seen[$id] = true;
		}
		if (count($seen) !== count($remaining)) {
			throw new InvalidEditRequestException('"order" must contain every remaining item exactly once.');
		}
		$order = $req->order;
		return ['order' => $order, 'removed' => $removedList, 'changed' => $removed !== [] || $order !== $remaining];
	}
}
