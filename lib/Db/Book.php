<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getFormat()
 * @method void setFormat(string $format)
 * @method string getPath()
 * @method void setPath(string $path)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getAuthors()
 * @method void setAuthors(?string $authors)
 * @method string|null getSeries()
 * @method void setSeries(?string $series)
 * @method float|null getSeriesIndex()
 * @method void setSeriesIndex(?float $seriesIndex)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getLanguage()
 * @method void setLanguage(?string $language)
 * @method string|null getPublisher()
 * @method void setPublisher(?string $publisher)
 * @method string|null getIsbn()
 * @method void setIsbn(?string $isbn)
 * @method string|null getPublishedAt()
 * @method void setPublishedAt(?string $publishedAt)
 * @method int|null getRating()
 * @method void setRating(?int $rating)
 * @method string getReadStatus()
 * @method void setReadStatus(string $readStatus)
 * @method bool getReadStatusManual()
 * @method void setReadStatusManual(bool $manual)
 * @method bool getHasCover()
 * @method void setHasCover(bool $hasCover)
 * @method string|null getCoverEtag()
 * @method void setCoverEtag(?string $etag)
 * @method int getFileMtime()
 * @method void setFileMtime(int $mtime)
 * @method string getFileEtag()
 * @method void setFileEtag(string $etag)
 * @method int getAddedAt()
 * @method void setAddedAt(int $addedAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method int|null getDeletedAt()
 * @method void setDeletedAt(?int $deletedAt)
 */
class Book extends Entity {
	public const STATUS_UNREAD = 'unread';
	public const STATUS_READING = 'reading';
	public const STATUS_FINISHED = 'finished';

	protected string $userId = '';
	protected int $fileId = 0;
	protected string $format = '';
	protected string $path = '';
	protected int $size = 0;
	protected ?string $title = null;
	/** JSON array string */
	protected ?string $authors = null;
	protected ?string $series = null;
	protected ?float $seriesIndex = null;
	protected ?string $description = null;
	protected ?string $language = null;
	protected ?string $publisher = null;
	protected ?string $isbn = null;
	protected ?string $publishedAt = null;
	protected ?int $rating = null;
	protected string $readStatus = self::STATUS_UNREAD;
	protected bool $readStatusManual = false;
	protected bool $hasCover = false;
	protected ?string $coverEtag = null;
	protected int $fileMtime = 0;
	protected string $fileEtag = '';
	protected int $addedAt = 0;
	protected int $updatedAt = 0;
	protected ?int $deletedAt = null;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('size', 'integer');
		$this->addType('seriesIndex', 'float');
		$this->addType('rating', 'integer');
		$this->addType('readStatusManual', 'boolean');
		$this->addType('hasCover', 'boolean');
		$this->addType('fileMtime', 'integer');
		$this->addType('addedAt', 'integer');
		$this->addType('updatedAt', 'integer');
		$this->addType('deletedAt', 'integer');
	}

	/** @return list<string> */
	public function getAuthorsArray(): array {
		$raw = $this->getAuthors();
		if ($raw === null || $raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		return array_values(array_map('strval', $decoded));
	}

	/** @param list<string> $authors */
	public function setAuthorsArray(array $authors): void {
		$this->setAuthors($authors === [] ? null : json_encode(array_values($authors), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
	}
}
