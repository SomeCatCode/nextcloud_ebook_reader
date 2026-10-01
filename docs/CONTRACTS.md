# Implementation Contracts (verbindlich für alle Worker)

Die fachliche Grundlage ist `PLAN.md`, insbesondere Abschnitt 11 mit den Korrekturen. Diese Datei legt die **technischen Verträge** fest. Wer davon abweichen muss, dokumentiert das in `docs/DEVIATIONS.md` mit Begründung. Signaturen anderer Worker werden nicht still geändert.

## 1. Grunddaten
- App-ID `ebookreader`, Namespace `OCA\EbookReader`, Lizenz AGPL-3.0-or-later, `<nextcloud min-version="34" max-version="34"/>`, PHP ≥ 8.2
- Jede PHP-Datei: `declare(strict_types=1);`, SPDX-Header `SPDX-FileCopyrightText: 2026 Felix Kurth` / `SPDX-License-Identifier: AGPL-3.0-or-later`
- Routen **nur per Attribut** (`#[ApiRoute]` für OCS, `#[FrontpageRoute]` für normale Controller), keine `appinfo/routes.php`
- Zeitstempel in DB und API: **Millisekunden** seit Epoch (int), ausgenommen `published_at` (String)
- Lokales PHP hat kein `zip`/`gd`: PHP-Tests laufen in Docker (`make test-php`, Image `php:8.3-cli` mit zip/gd; Composer via `composer:2`-Image)

## 2. Datenbank (Migration `Version1000Date20260930000000`)
Tabellen ohne `oc_`-Präfix angegeben. Keine FK-Constraints (Nextcloud-Konvention). Indexnamen ≤ 30 Zeichen.

**ebookreader_books**: id (bigint PK autoincr), user_id (string 64), file_id (bigint), format (string 8: epub|mobi|azw3|fb2|fbz|cbz|cbr), path (string 4000, nutzerrelativer Pfad zur Anzeige), size (bigint), title (string 512 null), authors (text null, JSON-Array), series (string 512 null), series_index (float null), description (text null, bereinigtes HTML), language (string 32 null), publisher (string 255 null), isbn (string 32 null), published_at (string 10 null), rating (smallint null), read_status (string 12, default `unread`), read_status_manual (bool default false), has_cover (bool), cover_etag (string 64 null), file_mtime (bigint), file_etag (string 64), added_at (bigint), updated_at (bigint), deleted_at (bigint null)
Indizes: unique(user_id,file_id) `ebr_books_uf`; (user_id,updated_at,id) `ebr_books_sync`; (file_id) `ebr_books_file`

**ebookreader_tags**: id, book_id (bigint), type (string 8: genre|tag), name (string 128), source (string 8: file|app)
Indizes: (book_id) `ebr_tags_book`; (type,name) `ebr_tags_tn`

**ebookreader_progress**: id, user_id, file_id, locator (text, JSON), percentage (float), device (string 64 null), client_updated_at (bigint), updated_at (bigint)
Indizes: unique(user_id,file_id) `ebr_prog_uf`; (user_id,updated_at,id) `ebr_prog_sync`

Entities/Mapper in `lib/Db/`: `Book`/`BookMapper`, `Tag`/`TagMapper`, `Progress`/`ProgressMapper` (QBMapper). `authors` wird im Entity als JSON-String gespeichert; Hilfsmethoden `getAuthorsArray(): array` / `setAuthorsArray(array)`.

## 3. Locator (JSON in `progress.locator`, Readium-kompatibel)
```json
{ "href": "OEBPS/ch03.xhtml", "type": "application/xhtml+xml", "title": "Kapitel 3",
  "locations": { "progression": 0.42, "totalProgression": 0.37, "position": 57, "cfi": "epubcfi(/6/8!/4/2/10)" } }
```
Comics: `href` = Zip-Eintrag der Seite, `locations.position` = 1-basierte Seitennummer. `cfi` ist eine Erweiterung (volles CFI von foliate-js).

## 4. PHP-Service-Verträge (Foundation legt Klassen mit diesen Signaturen an, Owner implementiert)
```php
// lib/Metadata/BookMetadata.php (DTO, public readonly properties, alle nullable außer arrays)
final class BookMetadata { title, authors: string[], series, seriesIndex: ?float, description, language, publisher, isbn, publishedAt, genres: string[], tags: string[], subjects: string[] /* unklassifizierte dc:subject */, coverData: ?string /* Bildbytes */, coverMime: ?string }
// lib/Metadata/ExtractorInterface.php
interface ExtractorInterface { public function supports(string $format): bool; public function extract(string $localPath): BookMetadata; }
// lib/Metadata/MetadataService.php
public function detectFormat(string $filename, string $mime): ?string;   // null = kein E-Book
public function extract(\OCP\Files\File $file, string $format): BookMetadata;

// lib/Service/LibraryService.php  (Owner W1)
public function indexFile(string $userId, \OCP\Files\File $file, bool $force = false): ?Book;
public function removeFile(string $userId, int $fileId): void;           // setzt deleted_at
public function reindexFileForAllUsers(int $fileId): void;                // nach Bearbeitung
public function isInLibrary(string $userId, \OCP\Files\Node $node): bool;
public function scanUser(string $userId): int;                             // reiht QueuedJobs ein, gibt Anzahl zurück
public function findBooks(string $userId, BookQuery $q): array;           // ['books'=>Book[], 'total'=>int]
public function getBook(string $userId, int $fileId): Book;               // wirft DoesNotExistException
public function getFacets(string $userId): array;                          // siehe API /facets
public function getTags(int $bookId): array;                               // Tag[]
public function setTags(int $bookId, string $type, array $names, string $source): void;
public function getFileForUser(string $userId, int $fileId): \OCP\Files\File; // wirft NotFoundException
// lib/Service/BookQuery.php: DTO mit search, format, genre, tag, author, series, status, sort, order, limit, offset
// lib/Service/CoverService.php (Owner W1)
public function storeCover(int $fileId, string $imageData): string;       // speichert small(200px)+large(600px) JPEG in IAppData Ordner "covers", gibt etag zurück
public function getCover(int $fileId, string $size): ?\OCP\Files\SimpleFS\ISimpleFile;
public function deleteCover(int $fileId): void;
// lib/Service/SettingsService.php (Owner Foundation)
public function get(string $userId): array; public function set(string $userId, array $settings): array;
// Keys: libraryFolders (string[], default ['/Books']), reader (object), filenamePattern (default '{author} - {title}'), genreList (string[]|null = Default aus resources/genres.json), metadataWriteMode ('background' Standard | 'immediate' | 'never')
// lib/Service/ProgressService.php (Owner W2)
public function get(string $userId, int $fileId): ?Progress;
public function put(string $userId, int $fileId, array $locator, float $percentage, ?string $device, int $clientUpdatedAt): array; // ['status'=>'ok'|'conflict','progress'=>Progress]
public function remapAfterEdit(int $fileId, array $itemMap): void;        // Owner W3 ruft auf; itemMap alt-href => neu-href|null
// lib/Editor/* (Owner W3): BookEditorInterface { supports(string $format): bool; readStructure(string $localPath, string $format): array; write(string $srcPath, string $dstPath, EditRequest $req): array /* ['warnings'=>string[], 'itemMap'=>array] */ }
// lib/Service/EditorService.php (Owner W3)
public function getStructure(string $userId, int $fileId, string $parts = 'all'): array; // parts='metadata': nur DB, kein Dateizugriff (partial=true, items/toc leer)
public function save(string $userId, int $fileId, array $request): array;  // ['book'=>Book,'warnings'=>string[]]
public function saveMetadataOnly(string $userId, int $fileId, array $metadataPatch): array; // für Detailansicht/Bulk-Tagging; Rückgabe + writeQueued. Schreibmodus (Setting metadataWriteMode): background (Standard: DB sofort, Datei per WriteMetadataJob), immediate, never. MOBI/AZW3/CBR/CB7/CBT und never -> nur DB, source=app, geänderte Felder landen in books.overrides
public function writePendingMetadata(string $userId, int $fileId): bool; // WriteMetadataJob: schreibt die DB-Metadaten in die Datei, falls sie abweichen
public function resetOverrides(string $userId, int $fileId, ?string $field): Book; // Override aufheben, Wert wieder aus der Datei lesen
public function rename(string $userId, int $fileId, ?string $name, bool $usePattern): Book;
```

## 5. HTTP-API

OCS-Basis: `/ocs/v2.php/apps/ebookreader/api/v1`. Alle Endpunkte haben `#[NoAdminRequired]`, Antworten laufen über `DataResponse` im OCS-Envelope. Der Frontend-Client sendet `OCS-APIRequest: true` und `requesttoken`, eine Android-App später Basic Auth mit App-Passwort.

**Book JSON:**
```json
{ "fileId": 123, "format": "epub", "path": "/Books/x.epub", "size": 1234, "title": "…", "authors": ["…"], "series": null, "seriesIndex": null,
  "description": "<p>…</p>", "language": "de", "publisher": null, "isbn": null, "publishedAt": "2020",
  "genres": ["Fantasy"], "tags": ["Lieblingsbuch"], "rating": 4, "readStatus": "reading",
  "hasCover": true, "coverEtag": "abc", "mtime": 1727…, "addedAt": 1727…, "updatedAt": 1727…,
  "editable": true, "overrides": ["title"] /* nur in der App geänderte Felder, überleben ein Neuindizieren */, "progress": null }
```
**Progress JSON:** `{ "fileId", "locator", "percentage", "device", "clientUpdatedAt", "updatedAt" }`

| Methode + Pfad | Body / Query | Antwort `data` | Owner |
|---|---|---|---|
| GET `/books` | search, format, genre, tag, author, series, status, sort=title\|author\|series\|rating\|added\|read, order=asc\|desc, limit(50, max 200), offset | `{books: Book[], total}` | W2 |
| GET `/books/{fileId}` | | `Book` | W2 |
| PATCH `/books/{fileId}/app-data` | `{rating?, readStatus?}` | `Book` | W2 |
| PATCH `/books/{fileId}/metadata` | Teil-Metadaten (inkl. genres/tags) → `EditorService::saveMetadataOnly` | `{book, warnings, writeQueued}` | W3 |
| DELETE `/books/{fileId}/overrides` | `field?` (eines von title, authors, series, seriesIndex, description, language, publisher, isbn, publishedAt; ohne = alle) | `Book` | W3 |
| POST `/books/bulk-tags` | `{fileIds[], addGenres[], removeGenres[], addTags[], removeTags[]}` | `{updated, failed:[{fileId,error}], writeQueued}` | W3 |
| GET `/facets` | | `{genres:[{name,count}], tags:[…], authors:[…], series:[…], formats:[…]}` | W2 |
| GET `/sync` | cursor (opak, leer = alles) | `{books: Book[], deleted: int[] /*fileIds*/, progress: Progress[], cursor}` | W2 |
| GET `/progress/{fileId}` | | `Progress` oder 404 | W2 |
| PUT `/progress/{fileId}` | `{locator, percentage, device?, clientUpdatedAt}` | 200 `Progress` bzw. **409** `{current: Progress}` | W2 |
| POST `/progress/batch` | `{items:[{fileId, locator, percentage, device, clientUpdatedAt}]}` | `{results:[{fileId, status:'ok'\|'conflict', progress}]}` | W2 |
| GET `/progress/recent` | limit(10) | `{books: Book[]}` | W2 |
| GET/PUT `/settings` | siehe SettingsService | settings | W2 |
| POST `/scan` | | `{queued}` | W2 |
| GET `/books/{fileId}/structure` | `parts=all\|metadata` (Standard all; metadata liest die Datei nicht) | Structure (unten) | W3 |
| PUT `/books/{fileId}/structure` | EditRequest | `{book, warnings}`, **409** bei etag-Konflikt | W3 |
| POST `/books/{fileId}/rename` | `{name?, usePattern?}` | `Book` | W3 |

Nicht-OCS-Controller (`#[FrontpageRoute]`):
- GET `/cover/{fileId}?size=small|large` liefert `FileDisplayResponse` mit ETag und `Cache-Control: private` (W2). POST `/cover/{fileId}` nimmt einen rohen Bild-Body an, für CBR-Cover aus dem Client, CSRF erforderlich (W2).
- GET `/item/{fileId}?id=<itemId>` liefert Rohinhalt eines Zip-Eintrags (Comic-Seiten-Thumbnails im Editor), nur für cbz/epub (W3).
- GET `/`, `/read/{fileId}`, `/edit/{fileId}` rendern alle das Template `main` (PageController, Foundation). Der Initial State `settings` wird mitgegeben.

**Structure:**
```json
{ "fileId", "format", "etag", "editable", "capabilities": {"metadata":true,"cover":true,"content":true,"toc":true,"writesFile":true},
  "metadata": {title, authors[], series, seriesIndex, description, language, publisher, isbn, publishedAt, genres[], tags[]},
  "items": [{"id":"ch1","label":"Kapitel 1","href":"OEBPS/ch1.xhtml","kind":"chapter|page","linear":true,"size":1234}],
  "toc": [{"id":"t1","label":"Kapitel 1","itemId":"ch1","fragment":null,"children":[]}], "warnings": [],
  "partial": false /* true bei parts=metadata: items und toc leer */ }
```
Item-IDs: EPUB = Manifest-`idref` des Spine-Eintrags; CBZ = Zip-Eintragsname; FB2 = Pfad `b0/s3/s1` (Body/Section-Index).

**EditRequest:** `{ etag, saveAsCopy, metadata?, cover?: {source:"upload", data:"<base64>"} | {source:"item", itemId}, order?: itemId[] /*neue Reihenfolge ohne entfernte*/, removed?: itemId[], toc?: TocNode[] }`

## 6. Frontend
- Vite über `@nextcloud/vite-config` (`createAppConfig`) mit den Entries `main` (SPA: Bibliothek/Reader/Editor mit vue-router, History-Base `generateUrl('/apps/ebookreader/')`), `viewer` (Viewer-Handler) und `files` (File Actions). Die Ausgabe landet in `js/ebookreader-<entry>.mjs`.
- Vue 3 + `@nextcloud/vue` v9 + Pinia. TypeScript.
- **Geteilte Dateien, Owner Foundation:** `src/types.ts` (Book, Progress, Locator, Structure, EditRequest, Settings) und `src/services/api.ts` (typisierte Funktionen für jeden Endpunkt; OCS-Unwrap `res.data.ocs.data`; 409 als typisierter Fehler `ConflictError` mit `current`).
- `packages/reader-core/` (Owner W4) ist framework-freies TS mit dem Export `createReader(container: HTMLElement, opts)`. Rückgabe ist ein `ReaderHandle` mit `open(fileOrBlob, format)`, `goTo(locator|href)`, `next()`, `prev()`, `setTheme()`, `setLayout()`, `getToc()`, `search()`, `destroy()` und den Events `relocate(locator, percentage)`, `ready`, `error`. foliate-js liegt vendored unter `packages/reader-core/vendor/foliate-js/` mit gepinntem Commit und `scripts/update-foliate.mjs`. Der Import erfolgt per relativem Pfad, npm-Workspaces gibt es erst später.
- Buchdatei laden: `@nextcloud/files` `davRemoteURL + davRootPath + book.path` per `fetch` als Blob.
- Keine Vorab-Abhängigkeitsinstallation durch Worker: `package.json` wird von der Foundation vollständig angelegt. Braucht ein Worker weitere Pakete, dokumentiert er sie in DEVIATIONS.md und installiert sie mit `npm install <pkg>`. Das ist erlaubt, aber sparsam einzusetzen.

## 7. Datei-Ownership (niemand editiert fremde Dateien, außer ausdrücklich erlaubt)
| Worker | Besitzt |
|---|---|
| Foundation (W0) | appinfo/, lib/AppInfo/, lib/Db/, lib/Migration/Version*, lib/Controller/PageController.php, lib/Service/SettingsService.php, lib/Capabilities.php, lib/Service/BookQuery.php, alle Stubs, composer.json, package.json, vite.config.ts, tsconfig.json, eslint/psalm/phpunit-Konfig, templates/, src/main.ts, src/router.ts, src/App.vue, src/types.ts, src/services/api.ts, docker/, Makefile, .github/, .gitignore, README.md |
| W1 Bibliothek-Backend | lib/Metadata/*, lib/Service/{LibraryService,CoverService,ScannerService,GenreClassifier}.php, lib/Listener/{FileEventListener,UserDeletedListener}.php, lib/BackgroundJob/*, lib/Command/*, lib/Preview/*, lib/Migration/RegisterMimeTypes.php, resources/genres.json, tests/Unit/{Metadata,Service/Library*}, tests/fixtures/* + tests/fixtures/generate.php |
| W2 API-Backend | lib/Controller/{Books,Facets,Sync,Progress,Settings,Scan,Cover}Controller.php, lib/Service/ProgressService.php, lib/ResponseDefinitions.php, lib/Http/*, openapi.json, tests/Unit/Controller/*, tests/Unit/Service/ProgressServiceTest.php |
| W3 Editor-Backend | lib/Editor/*, lib/Service/{EditorService,RenameService}.php, lib/Controller/{Editor,Item}Controller.php, tests/Unit/Editor/* |
| W4 Reader-Frontend | packages/reader-core/**, src/views/ReaderView.vue, src/components/reader/**, src/viewer.ts, src/services/progressSync.ts, lib/Listener/{CspListener,LoadViewerListener}.php |
| W5 Bibliothek-Frontend | src/views/LibraryView.vue, src/components/library/**, src/stores/** |
| W6 Editor-Frontend | src/views/EditorView.vue, src/components/editor/**, src/editor/**, src/files.ts, lib/Listener/LoadFilesScriptsListener.php |

`lib/AppInfo/Application.php` registriert schon alle Listener, Jobs, Commands, Capabilities und den Preview-Provider (Foundation). Zusätzliche Registrierungen trägt der Worker als Wunsch in DEVIATIONS.md ein. Der Integrations-Schritt übernimmt sie.
