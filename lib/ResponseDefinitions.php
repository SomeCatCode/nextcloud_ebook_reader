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
 * }
 *
 * @psalm-type EbookReaderSettings = array{
 *     libraryFolders: list<string>,
 *     reader: array<string, mixed>,
 *     filenamePattern: string,
 *     genreList: ?list<string>,
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
 * @psalm-type EbookReaderProgressBatchResult = array{
 *     fileId: int,
 *     status: 'ok'|'conflict'|'error',
 *     progress: ?EbookReaderProgress,
 *     error?: string,
 * }
 */
class ResponseDefinitions {
}
