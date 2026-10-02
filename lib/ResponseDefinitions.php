<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader;

/**
 * @psalm-type EbookReaderLocations = array{
 *     progression?: float,
 *     totalProgression?: float,
 *     position?: int,
 *     cfi?: string,
 * }
 *
 * @psalm-type EbookReaderLocator = array{
 *     href: string,
 *     type?: string,
 *     title?: string,
 *     locations?: EbookReaderLocations,
 * }
 *
 * @psalm-type EbookReaderProgress = array{
 *     fileId: int,
 *     locator: EbookReaderLocator,
 *     percentage: float,
 *     device: ?string,
 *     clientUpdatedAt: int,
 *     updatedAt: int,
 * }
 *
 * @psalm-type EbookReaderBook = array{
 *     fileId: int,
 *     format: string,
 *     path: string,
 *     size: int,
 *     title: ?string,
 *     authors: list<string>,
 *     series: ?string,
 *     seriesIndex: ?float,
 *     description: ?string,
 *     language: ?string,
 *     publisher: ?string,
 *     isbn: ?string,
 *     publishedAt: ?string,
 *     genres: list<string>,
 *     tags: list<string>,
 *     rating: ?int,
 *     readStatus: 'unread'|'reading'|'finished',
 *     hasCover: bool,
 *     coverEtag: ?string,
 *     mtime: int,
 *     addedAt: int,
 *     updatedAt: int,
 *     editable: bool,
 *     downloadable: bool,
 *     overrides: list<string>,
 *     hasSidecar: bool,
 *     progress: ?EbookReaderProgress,
 * }
 *
 * @psalm-type EbookReaderFacetEntry = array{name: string, count: int}
 *
 * @psalm-type EbookReaderFacets = array{
 *     genres: list<EbookReaderFacetEntry>,
 *     tags: list<EbookReaderFacetEntry>,
 *     authors: list<EbookReaderFacetEntry>,
 *     series: list<EbookReaderFacetEntry>,
 *     formats: list<EbookReaderFacetEntry>,
 *     missing: array{genre: int, tag: int, author: int, series: int, description: int, cover: int, language: int},
 * }
 *
 * @psalm-type EbookReaderSettings = array{
 *     libraryFolders: list<string>,
 *     reader: array<string, mixed>,
 *     filenamePattern: string,
 *     genreList: ?list<string>,
 *     metadataWriteMode: string,
 *     metadataTarget: string,
 * }
 *
 * @psalm-type EbookReaderSyncResult = array{
 *     books: list<EbookReaderBook>,
 *     deleted: list<int>,
 *     progress: list<EbookReaderProgress>,
 *     cursor: string,
 *     hasMore: bool,
 * }
 *
 * @psalm-type EbookReaderBookList = array{
 *     books: list<EbookReaderBook>,
 *     total: int,
 * }
 *
 * @psalm-type EbookReaderSmartQuery = array{
 *     include: list<string>,
 *     exclude: list<string>,
 *     match: 'all'|'any',
 *     search: string,
 *     status: ?string,
 *     sort: string,
 *     order: 'asc'|'desc',
 * }
 *
 * @psalm-type EbookReaderShelf = array{
 *     id: int,
 *     name: string,
 *     type: 'manual'|'smart',
 *     query: ?EbookReaderSmartQuery,
 *     count: int,
 *     coverFileIds: list<int>,
 *     sortOrder: int,
 *     createdAt: int,
 *     updatedAt: int,
 * }
 *
 * @psalm-type EbookReaderSeries = array{
 *     name: string,
 *     count: int,
 *     readCount: int,
 *     coverFileIds: list<int>,
 *     firstFileId: int,
 *     lastAddedAt: int,
 * }
 *
 * @psalm-type EbookReaderOrganizeItem = array{
 *     fileId: int,
 *     from: string,
 *     to: string,
 *     status: 'move'|'unchanged'|'conflict'|'error'|'moved'|'failed',
 *     message?: string,
 * }
 *
 * @psalm-type EbookReaderOrganizePreview = array{
 *     items: list<EbookReaderOrganizeItem>,
 * }
 *
 * @psalm-type EbookReaderOrganizeResult = array{
 *     items: list<EbookReaderOrganizeItem>,
 *     moved: int,
 *     failed: int,
 * }
 *
 * @psalm-type EbookReaderProgressBatchResult = array{
 *     fileId: int,
 *     status: 'ok'|'conflict'|'error',
 *     progress: ?EbookReaderProgress,
 *     error?: string,
 * }
 */
class ResponseDefinitions {
}
