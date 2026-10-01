<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getBookId()
 * @method void setBookId(int $bookId)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getSource()
 * @method void setSource(string $source)
 */
class Tag extends Entity {
	use MarksFieldsOnCreate;

	public const TYPE_GENRE = 'genre';
	public const TYPE_TAG = 'tag';
	public const SOURCE_FILE = 'file';
	public const SOURCE_APP = 'app';

	protected int $bookId = 0;
	protected string $type = self::TYPE_TAG;
	protected string $name = '';
	protected string $source = self::SOURCE_FILE;

	public function __construct() {
		$this->markAllFieldsUpdated();
		$this->addType('bookId', 'integer');
	}
}
