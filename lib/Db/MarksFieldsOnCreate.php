<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Db;

/**
 * Entity::setter() only marks a field as updated when the new value differs from the current one,
 * so setting a property to its default (e.g. Tag::setType('tag')) left it out of the INSERT and
 * NOT NULL columns without a database default failed. New instances therefore mark every field;
 * Entity::fromRow() resets the marks for loaded rows, so updates stay minimal.
 */
trait MarksFieldsOnCreate {
	protected function markAllFieldsUpdated(): void {
		foreach (array_keys(get_object_vars($this)) as $field) {
			if ($field !== 'id' && !str_starts_with($field, '_')) {
				$this->markFieldUpdated($field);
			}
		}
	}
}
