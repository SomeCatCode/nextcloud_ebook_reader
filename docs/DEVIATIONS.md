# Deviations from CONTRACTS.md

## Foundation (W0)

- `vite.config.ts`: `extractLicenseInformation` is disabled on Windows (`process.platform === 'win32'`). The
  `@nextcloud/vite-config` REUSE licence plugin loops forever there (dirname of a drive root never equals `/`),
  which hangs `vite build` at "rendering chunks". On Linux/CI it stays enabled (default).
- Vite output: `js/ebookreader-<entry>.mjs` (as contracted); chunks `js/*.chunk.mjs`; CSS goes to `css/` (added to .gitignore).
- Dependency versions adapted to what resolves: `@nextcloud/vue@^9.13`, `vite@^7.3` (peer of vite-config 2.5), `typescript@^5.9`,
  `vitest@^3`, `pinia@^3`, `vue-router@^4`, `eslint@^10` (required by `@nextcloud/eslint-config@9`), extra devDeps `@types/node`, `jsdom`, `@vue/test-utils`, `sass`.
- `@nextcloud/files` v4 exposes WebDAV constants from `@nextcloud/files/dav` (`defaultRemoteURL`, `defaultRootPath`); `davUrlForPath()` uses them.
- Extra format `fbz` (fb2.zip) registered as MIME `application/x-zip-compressed-fb2` in `Application::MIME_TYPES` (also in the preview regex). W1 owns RegisterMimeTypes and may change it.
- Extra public helpers beyond the contract: `Progress::toApi()`, `Progress::getLocatorArray()`, `Application::MIME_TYPES`,
  `SettingsService::defaultGenres()` (accepts genres.json as list of strings or objects with `name`/`en`), `GenreClassifier::classify()`,
  `RenameService::buildFilename()`, `ScannerService::scanUser()` (stub signatures owned by W1/W3, adjust freely).
- `SettingsService::get()` resolves a null `genreList` to the default list; `set()` merges partial settings and stores `null` if none given.
- `api.ts` additionally exports `fetchBookBlob`, `uploadCover`, `putProgressKeepalive`, `ApiError`. `ConflictError.current` = `data.current` of the 409 body (fallback: whole data).
- `PageController` calls `\OCP\Util::addScript('ebookreader', 'ebookreader-main')`; CSS is bundled by vite-config's ImportCSS plugin, so no addStyle.
- The stub `LoadViewerListener`/`CspListener`/`LoadFilesScriptsListener` have empty `handle()` bodies. `OCA\Viewer\Event\LoadViewer` and `OCA\Files\Event\LoadAdditionalScriptsEvent`
  are not in nextcloud/ocp (apps): Psalm suppresses UndefinedClass for them in psalm.xml.

## W5 (Bibliothek-Frontend)

- Icons: `vue-material-design-icons` is not installed; icons use `@mdi/js` paths via `NcIconSvgWrapper` (`@mdi/js` is a transitive dependency of `@nextcloud/vue`; consider adding it to package.json dependencies explicitly).
- `Progress.percentage` is interpreted as a fraction 0..1 (values > 1 are treated as percent) in `components/library/utils.ts::progressPercent`.
- Book detail "Show in Files" URL: `/apps/files/files/{fileId}?dir=<dir>&openfile=false`.
- Stores use the fixed initial-state key `loadState('ebookreader', 'settings')`.
- The LibraryView chunk is ~740 kB (NcSelect/FilePicker pulled in); acceptable for now.

## W2 (API-Backend)

- Psalm type aliases must start with `EbookReader` (app id CamelCase), not `Ebookreader`: `EbookReaderBook`, `EbookReaderProgress`, `EbookReaderLocator`, `EbookReaderFacets`, `EbookReaderSettings`, `EbookReaderSyncResult`, `EbookReaderBookList`, ... in `lib/ResponseDefinitions.php` (W3: `@psalm-import-type EbookReaderBook from \OCA\EbookReader\ResponseDefinitions`).
- OCS controllers extend `OCA\EbookReader\Http\AbstractOCSController` (must end in `OCSController` for openapi-extractor); constructor `(IRequest $request, ?string $userId, ...)`, helper `uid()`. W3 may reuse it.
- `BookSerializer` (public service, autowired): `serialize()`, `serializeWithProgress()`, `serializeMany()` (batch tags via TagMapper and progress via ProgressMapper; one `getFileForUser` per book for `editable`). `SyncCursor` in `lib/Http`.
- Sync response has an extra `hasMore` flag; each list capped at 500 per call. Books in `books` carry `progress` too.
- Progress batch: per item `status` may additionally be `error` (with `error` message, e.g. file not accessible / invalid locator); max 100 items.
- `ProgressService` constructor: `(ProgressMapper, BookMapper, ITimeFactory)`. `remapAfterEdit`: mapped to null => `href` becomes `''`, `cfi`/`progression`/`position` dropped, `totalProgression` kept. Locator `href` must be non-empty on PUT unless `locations.totalProgression` is set (`href: ''` = only the overall position is known).
- Read status and progress are coupled both ways. An accepted progress write derives the status (>= 0.98 finished, 0 unread, otherwise reading) and clears `read_status_manual`, except that a hand-set `reading` survives a 0 % write. `PATCH /books/{fileId}/app-data` with `readStatus` sets `read_status_manual` and writes the progress via `ProgressService::applyReadStatus`: finished = 1.0 (`{href:'', locations:{totalProgression:1}}`, row created if missing), unread = 0.0 (`{href:'', locations:{position:1, totalProgression:0}}`, no row created), reading unchanged; the row gets `clientUpdatedAt = max(now, old + 1)`, `updatedAt = now`, `device = null`. `GET /progress/recent` skips rows at 0 %.
- Read-status auto update only bumps book `updated_at` when the status actually changes.
- `ProgressService` has an optional fourth constructor argument `?AnnotationMapper`. `remapAfterEdit` remaps the locators of the live annotations of all users like the progress rows (href mapped, `cfi` dropped, removed item: `href` `''` and only `totalProgression` kept; the annotation and its text/note stay) and gives them a new `updatedAt` for `/sync`, `clientUpdatedAt` unchanged.
- Cover POST additionally needs the book in the user's library; if the book already has a cover the file must be updateable. Updates has_cover/cover_etag on ALL rows of that fileId. Response `{coverEtag}` (JSON, not OCS).
- Settings PUT is a partial update (absent keys unchanged; `genreList: null` resets to default).

### Requests for Foundation / integration
- `lib/Capabilities.php`: openapi-extractor fails with "Unable to read capabilities docs" - add `@psalm-type` docblock, e.g. `@return array{ebookreader: array{apiVersion: int, apiStable: bool, formats: list<string>, editor: bool}}` on `getCapabilities()` (only remaining extractor error; `openapi.json` is generated with all 10 W2 routes anyway). Run: `php -d extension=zip -d extension=gd -d extension=fileinfo vendor/bin/generate-spec`.
- `psalm.xml`: `OCP\Files\IRootFolder` extends server-only `OC\Hooks\Emitter` -> add `<MissingDependency><errorLevel type="suppress"><referencedClass name="OC\Hooks\Emitter"/></errorLevel></MissingDependency>` (I used a class-level `@psalm-suppress` in SettingsController meanwhile). PHPUnit mocks of IRootFolder need a stub of that interface (see SettingsControllerTest::setUpBeforeClass; could move to tests/bootstrap.php).
- `#[UserRateLimit]` used on write endpoints; nothing to register.

## W6 (Editor-Frontend)

- `src/editor/cbrToCbz.ts` has its own libarchive.js init (packages/reader-core had no helper yet): the worker source is fetched (`?url` asset), patched so `libarchive.wasm` resolves to the bundled wasm asset, and started from a blob URL. Requires CSP `worker-src blob:` (W4/CspListener). If reader-core exports a shared loader, replace `initArchive()`.
- Rename dialog sends the full file name incl. extension in `RenameRequest.name` (W3 should accept/keep the extension).
- Summary strings are translated dynamically (`t('ebookreader', s, vars)` in `computeChangeSummary` callers); they will not be picked up by l10n string extraction.
- Editor uses `execCommand` for the minimal rich-text editor (deprecated but universally supported); output is sanitised via DOMPurify allowlist (`src/editor/sanitize.ts`).
- Files actions ("Open in E-Book Reader", "Edit e-book") are registered for the mimetypes incl. `application/x-zip-compressed-fb2`; neither is default.

## W4 (Reader-Frontend)

- foliate-js is vendored with 3 marked patches (sandbox without `allow-scripts` in paginator/fixed-layout, PDF import removed); see `packages/reader-core/vendor/foliate-js/VENDORED.md` and `docs/SECURITY-READER.md`.
- Extra files: `packages/reader-core/{index.ts,foliate.d.ts,src/*}`, `scripts/update-foliate.mjs`, `src/components/reader/{ReaderIcon,ReaderToc,ReaderSettings}.vue`, `src/components/reader/libarchive.ts`, `docs/SECURITY-READER.md`.
- `ReaderHandle` additions over CONTRACTS 6: `setTypography`, `getInfo`, `goToCfi`, `clearSearch`, `on()` (returns unsubscribe), extra events `tap` and `key`; `open(file, format, initialLocator?)`. `goTo` returns boolean.
- No new npm packages. `vite.config.ts`/`package.json` untouched (libarchive worker/wasm are bundled through `?raw`/`?url` imports).
- ReaderView reads/writes reader settings through `getSettings`/`putSettings` (full `reader` object) with extra keys `comicSpread`, `comicRtl`, `comicZoom`; `fontSize` is a percentage (values <= 40 are treated as px/16).
- Viewer handler embeds `/read/{fileid}?embedded=1` in an iframe (Viewer is Vue 2). `CspListener` now takes `IRequest` (autowired) and only relaxes CSP on ebookreader/files/viewer routes.
- Request to Foundation/W5: none required. Route `/read/:fileId` may be opened with `?embedded=1`.

## W3 (Editor-Backend)

- No new composer/npm packages, no `Application.php` changes needed (controllers use attribute routes, services are autowired). `Fb2Editor` is instantiated manually in `EditorService` (its optional `Fb2Genres` constructor argument is not autowirable).
- `EditorService` has an extra public method `bulkTags(userId, body)` (used by `POST /books/bulk-tags`) and `EditorService` needs `MetadataService::extractLocal()` (W1) for the post-write verification; the editors additionally re-open their own output in `write()`.
- Editors are self-contained (own zip/xml helpers in `lib/Editor/EditorUtil.php`, `ZipWriter.php`), they do not depend on W1's `SafeZip`/`HtmlSanitizer`. The service uses `HtmlSanitizer::sanitize()` for the DB description.
- Extra exceptions in `lib/Editor` (`EditorException` with HTTP status as code, `InvalidEditRequestException` 400, `EditConflictException` 409, `EditForbiddenException` 403); `EditorController` maps them to `DataResponse(['message'], status)`; 413 too large, 415 unsupported, 423 locked.
- Structure additions: toc nodes carry an extra `href` (zip path) for EPUB nodes that point at non-spine files; FB2 toc node `itemId`s are section paths (`b0/s3/s1`), only top-level sections (`b0/sN`) are reorderable/removable items.
- `EditRequest.metadata` semantics: only keys that are present are written; `genres`/`tags` are always written together (subjects/keywords). The service always sends the merged full metadata for `saveMetadataOnly`.
- EPUB: `dc:description` is written as plain text (HTML -> text), DB keeps the sanitized HTML (service re-applies it after re-index). Duplicate spine idrefs are dropped when the order changes. If a book has no editable nav/ncx, a toc edit yields a warning.
- FB2: only integer series numbers; genres map via `resources/fb2-genres.json` (label/code -> code), unmapped labels go to `<keywords>`; genres are never left empty (`unrecognised` fallback).
- Locking: shared lock while reading/writing the temp file, released before `putContent()` (which takes its own exclusive lock); the etag is re-checked right before writing.
- Copy target: same folder if creatable, otherwise the user's root folder; name `<name> (bearbeitet).<ext>`.
- `rename()` accepts a name with or without extension (W6 sends the full name), keeps the original extension (`.fb2.zip` compound handled).
- `ItemController` serves only cbz/epub entries; HTML/SVG/XML are delivered as `text/plain` with an empty CSP (script safety). Route `GET /item/{fileId}?id=`; for EPUB `id` may be a zip path or a manifest id.
- psalm: `MissingDependency` on `OCP\Files\IRootFolder` (`OC\Hooks\Emitter`) in `EditorService` - same psalm.xml suppression as noted by W2 fixes it. `EditorController` suppresses `InvalidReturnType/InvalidReturnStatement` (shared `guard()` helper).
- epubcheck was not run (Java 25 present, but no epubcheck jar available offline); roundtrip is covered by PHPUnit (mimetype first/stored, all entries + META-INF preserved, unchanged nav bytes).

## W1 (Bibliothek-Backend)

- **MIME types (RegisterMimeTypes):** NC 34 (ocp/core) has no app API for MIME types. Core stable34 already maps `epub`, `mobi`, `fb2`, `cbz`
  (`application/comicbook+zip`) and `cbr` (`application/comicbook+rar`); only `azw3` and `fbz` are missing. The repair step adds only the extensions
  that `IMimeTypeDetector::getAllMappings()` does not know into `config/mimetypemapping.json` (merge, never override, idempotent) and calls
  `IMimeTypeLoader::updateFilecache()`. It needs a writable config dir; otherwise it only warns. `.fb2.zip` has the extension `zip`, so it is
  recognised by file name in `MetadataService::detectFormat()`, not by MIME.
  - Consequence: comics really carry the MIME `application/comicbook+zip|rar` in Nextcloud, not `application/vnd.comicbook+...` from
    `Application::MIME_TYPES` / PLAN 6.2. `detectFormat()` accepts both and prefers the extension.
  - **Request (Foundation/integration):** change `Application::MIME_TYPES` cbz/cbr to `application/comicbook+zip` / `application/comicbook+rar`, use
    `\OCA\EbookReader\Preview\EbookCoverProvider::MIME_REGEX` as the preview regex in `registerPreviewProvider()` (it accepts both spellings), and
    give the Viewer handler (W4) `application/comicbook+zip`, `application/comicbook+rar` in addition to the existing types.
- **composer.json (Foundation):** please add `"ext-zip": "*"`, `"ext-gd": "*"`, `"ext-dom": "*"`, `"ext-libxml": "*"`, `"ext-mbstring": "*"` to `require`
  (psalm only loads extension stubs listed there: without them psalm reports `ZipArchive`/`GdImage` as undefined). The runtime needs zip + gd.
- **Unit tests without a server:** `nextcloud/ocp` references `OC\Hooks\Emitter` (IRootFolder) and doctrine/dbal constants (IQueryBuilder), neither is
  installed. `tests/Unit/Service/OcHooksEmitterStub.php` declares minimal stubs; `require_once` it in any test that mocks `IRootFolder`, a mapper or
  the query builder (W2 controllers tests may need the same, or Foundation moves it into `tests/bootstrap.php`).
- **Contract additions (all optional/backwards compatible):**
  - `GenreClassifier::classify(array $subjects, ?string $userId = null)`; without user id only the default list is used.
  - `LibraryService::scanUser(string $userId, bool $inline = false, ?callable $progress = null)`; `LibraryService::removeFileForAllUsers(int)`,
    `moveFolder()`, `queueFolder()`, `walkLibrary()`, `isPathInLibrary()`, `getNodeForUser()`. `ScannerService::scanUser()` delegates to LibraryService.
  - `MetadataService::extractLocal(string $path, string $format, ?string $displayName)` (never throws for unreadable books: falls back to the file name;
    DRM protected MOBI/AZW3 are indexed by file name only). `BookMetadata::with(array $changes)`.
  - Shared helpers for W3: `Metadata\SafeZip` (open/read/find/names/size/close, static `isSafeName()`, `resolve($baseDir, $href)`), `Metadata\XmlUtil`
    (`load()` rejects `<!ENTITY`, LIBXML_NONET), `Metadata\HtmlSanitizer::sanitize()/toText()`, `Metadata\ImageUtil::mime()`, `GenreClassifier::normalise()`.
    FB2 genre code -> German label map: `resources/fb2-genres.json` (`{code: {de, en}}`); default genres: `resources/genres.json` (`{name, en}`).
  - Fixtures: `tests/fixtures/generate.php` (deterministic; `ebr_generate_fixtures($dir)`), output in `tests/fixtures/books/`
    (epub2, epub3, epub3-collection, book.fb2, book.fb2.zip, comic.cbz, plain.cbz, book.mobi, drm.mobi).
- **Behaviour notes:** the file listener only indexes for the user whose path (`/uid/files/...`) the event happened in (shares to other users are picked
  up by `ebookreader:scan` / RescanJob every 6 h). Folder delete/rename updates paths/tombstones by prefix; a folder moved into the library queues a
  `ScanFileJob` with the folder id (the job walks it). Tombstoning of vanished files during a scan is skipped when a library folder could not be listed.
  `occ ebookreader:scan` indexes inline by default, `--queue` only enqueues jobs. Cover of CBR books is never cleared by a re-index (client posts it).
- `EbookCoverProvider::isAvailable()` takes `OCP\Files\FileInfo` (as in `IProviderV2`; the stub's `File` type was not contravariant).
- Registrations wanted in `Application.php`: none beyond the existing ones (all listeners/jobs/commands/preview provider are already registered).

## V2-A
- `targetFolder` (organise) must lie inside one of the user's library folders (stricter than "inside the user's storage"), otherwise 400; books moved outside the library would be tombstoned by the next scan. Missing folders below it are created on apply.
- Filter conditions are built inside `LibraryService::entryCondition()` with the query builder (IN / NOT IN sub-selects instead of literal EXISTS); no SQL-level unit test (needs a real DB), parsing is covered by `BookQueryTest`.
- Organise status `error` is also returned for unknown fileIds; `failed` includes "no free file name". New `OrganizeException` (lib/Service) maps to HTTP 400.

## V2-B
- `BookDetails.vue` imports `../convert/ConvertDialog.vue`, which V2-C delivers. Until it exists, `npm run build` fails on that import (vue-tsc does not, because of the `*.vue` shim). Nothing was created at that path.
- `BookFormat` in `src/types.ts` gained `cb7` and `cbt`. This makes `ReaderView.vue(576)` fail typecheck (`ReaderFormat` lacks `cb7`/`cbt`); that file is owned by V2-C.
- Old single-value store API (`setFilter`, `toggleFilter`, `FilterKey`, `filters.genre` etc.) is removed. Replaced by `termState`, `setTermState`, `cycleTerm`, `onlyTerm`, `setMatch`, `setStatus`. `sort`/`order` stay separate refs next to `filters` (not inside it).
- `match` is only sent to the API when there are at least 2 includes.
- URL query keys: `include`, `exclude` (repeated `type:name`), `match=any`, `q`, `status`, `sort`, `order`; defaults omitted. When opening the library without a query, the persisted store state is written to the URL instead of being reset.
- `BookDetails` emits `organize(fileId)` and `converted(fileId)`; `LibraryView` hosts `OrganizeDialog` and handles the reload.
- Sorting needed no change (all six keys already existed); no series grouping added.

## V2-C

- **CBT without PharData:** the contract says CBT is read through `PharData`. Implemented as own minimal tar reader/writer (`lib/Metadata/TarArchive.php`: ustar, pax `path`, GNU long names, base-256 sizes, checksum check) because it needs no `phar` extension, reads by offset (`fseek`) instead of loading a manifest, and rejects unsafe names itself. Cross-checked against `PharData` in the unit tests (both directions).
- **Own client-side writers instead of libarchive.js write:** `Archive.write()` sizes its output buffer as "sum(file size + 128 bytes) + 128" per call. Uncompressed tar/zip/7z headers need more than that (tar: 512 byte header + padding per file), so writing fails ("written bytes don't match file size", output size 0) as soon as the compression filter is `none` or the data is incompressible (verified with libarchive.js 2.0.2 in Node). libarchive.js is therefore used for reading only (cbz/cbr/cb7/cbt in the browser). `src/convert/archiveWriters.ts` writes tar (ustar + pax) and 7z (Copy method, one folder, no compression); both were verified with GNU tar, bsdtar and 7-Zip 23 (`7z t`). ZIP and EPUB use `fflate` as planned.
- **Mime types:** cb7 = `application/x-cb7`, cbt = `application/x-cbt` (not in NC core, merged into `config/mimetypemapping.json` by the existing `RegisterMimeTypes`, which already only adds missing extensions). The repair step runs on app upgrade, so **the integration step has to bump `<version>` in `appinfo/info.xml`** for existing installations.
- **Request (V2-A / integration): `lib/Service/LibraryService.php::indexFile()`** clears a cover when the extractor returns none, except for `cbr`. Change `$format !== 'cbr'` to `!in_array($format, ['cbr', 'cb7', 'cbt'], true)` so a browser-generated cover of a CB7 (no 7z on the server) is not deleted on re-index. Not edited by V2-C (not owned).
- **Request (V2-A / W3): `EditorService::DB_ONLY`** should contain `cb7` and `cbt` next to `cbr` (metadata edits for these formats can only go into the database). Not edited by V2-C.
- **Extractors:** `ComicInfoParser` (new, shared by `CbzExtractor`, `CbrExtractor` and `ConvertService`) holds the ComicInfo parsing that was inline in `CbzExtractor`. `CbrExtractor` handles cbr, cb7 and cbt; without a usable tool it returns empty metadata (not an exception), so `MetadataService` falls back to the file name without a warning in the log. `MetadataService` takes an optional third constructor argument `?ArchiveTools`, `EbookCoverProvider` and `ComicController` get `ArchiveTools` injected (autowired).
- **Preview provider:** CBR/CB7 previews are only offered when a tool can read them; the provider regex (and `Application::PREVIEW_MIME_REGEX`) include `x-cb7` / `x-cbt`.
- **ArchiveTools:** detection scans `PATH` plus `/usr/bin`, `/usr/local/bin`, `/opt/homebrew/bin`, `/snap/bin` (and `C:\Program Files-Zip` on Windows) for `7zz`/`7z`/`7za`, `unrar`, `bsdtar`. Output of the child process goes to a temp file (portable, no pipe deadlocks, size watched while it runs); timeout 30 s (600 s for creating a 7z), max 60 MB output. Entry names containing wildcard characters (`* ? [ ]`), newlines or starting with `@` are dropped from listings because the tools interpret them (7z `@listfile`, wildcards). Reading order of tools: CBR `unrar`, `7z`, `bsdtar`; CB7 `7z`, `bsdtar`. 7z is the only tool used for writing (CB7).
- **ComicController:** pages of CBT are served without any tool, CBR/CB7 only if `ArchiveTools::canRead()`; otherwise 404 and the client downloads the file (as before). The page list of tool based formats reports `size: 0` per page.
- **Conversion:** pages are renamed to zero padded `0001.ext` (at least 4 digits, `jpeg` becomes `jpg`) in all targets, so any reader sorts them correctly; `ComicInfo.xml` is kept byte for byte, or generated from the book when the source had none (CBZ/CBT/CB7 targets). Rating, read status, app tags and the reading position (href remapped by page index, EPUB: `OEBPS/pages/NNNN.xhtml`) are carried to the new book, for every user who has the original in their library (shared folder), not only the acting user: the new file is indexed for them right away and rating, status, completion, manual age rating, app tags, reading position (`clientUpdatedAt` kept, new `updatedAt`) and annotations (moved to the new file id, same uuid, locator mapped like the position, new `updatedAt`) follow. Users who cannot see the new file or do not have it in their library keep their data on the old file id. `ConvertService` has an optional last constructor argument `?AnnotationMapper`. Edits that exist only in the database (e.g. a renamed CBR) are not merged into an existing ComicInfo.xml. Server limit for sources: 2 GB (413, the browser then converts).
- **Targets list:** includes all five formats; the current format is `unavailable` ("This is the current format"), CBR always ("RAR can only be written with proprietary software"), a target whose file name exists is `unavailable` ("A file with this name already exists"), a folder without create permission as well. Non-comic books get an empty `targets` list. The English reasons are translated in the UI by `translateReason()` (`src/convert/formats.ts`).
- **Client mode details:** the dialog uses `POST /scan` after the WebDAV PUT (`If-None-Match: *`, plus a HEAD check before) and takes the new file id from the `OC-FileId` response header; the original is only deleted when the new book was seen in the library (`GET /books/{id}`, up to 6 s). Rating, read status and position are carried over via the existing API; app tags are not (the client cannot tell app tags from file tags).
- **Client cover fallback:** `ReaderHandle.getCover()` (new, reader-core) returns the first page of a comic; `bookSource.ts` renders it (max 600 px wide, JPEG 0.85) and POSTs it with `requesttoken` from `@nextcloud/auth`. Only for a locally opened CBR/CB7/CBT with `hasCover === false`; if the server delivers the pages itself (tool or CBT) the server cover from indexing is used.
- **ReaderView.vue:** besides the cover call, the initial `isComic` guess now includes cb7/cbt.
- **OpenAPI:** `generate-spec` rewrote `openapi.json` (owned by V2-A) completely, with the three new convert routes; re-run it in the integration step.
- **Tests:** a few existing tests were adapted (`ComicControllerTest` constructor, `MetadataServiceTest::testCbrUsesFilenameOnly` now pins "no tool installed" via `new ArchiveTools([])`, because 7z reads a ZIP renamed to .cbr and the dev machine has 7z).

## L-A

- **Inline run after the response:** `TaskService::scheduleInline()` registers a `register_shutdown_function()` that calls `ignore_user_abort(true)`, `set_time_limit(0)`, `fastcgi_finish_request()` and then `TaskService::run()`. The controller sends the 202 through the normal AppFramework path; PHP runs shutdown functions after the response has been echoed, and `fastcgi_finish_request()` flushes it to the client before the task starts. No AppFramework hook was used: `OCP\AppFramework\Http\ICallbackResponse` (CallbackResponse) streams the body itself and would keep the request open, and there is no public post-response event in NC 34. Without `fastcgi_finish_request()` (mod_php, CLI) nothing runs inline, because the client would wait for the whole task: the step is set to "Waiting for background job" and `RunTaskJob` does the work (cron or `occ background-job:worker`). `async_inline=false` (IAppConfig, default true) disables the inline run on FPM as well. `RunTaskJob` is always queued as the fallback; `TaskService::run()` claims the task with an atomic `UPDATE ... WHERE status = 'queued'`, so only one runner executes it.
- Progress is written at most once per second; finished tasks drop their (possibly large) `request` JSON. A task in `running` without update for 6 hours is marked failed by `CleanupTombstonesJob` (process died); `done`/`failed` tasks are deleted after 24 hours. `GET /tasks?active=1` returns `{tasks: [...]}`; the `active` flag is always on (finished tasks are only available by id).
- The etag/permission/size/format checks of `EditorService::save()` run synchronously at create time (`validateSave()`, HTTP 409/403/413/415/422) and again at write time inside the task (`save()`), where a failure becomes `status = failed` with `result.code`. The view-only check (`canReadContent`) is repeated inside the task as well.
- **Annotations of tombstoned books** (round 9): `CleanupTombstonesJob` (new constructor argument `IRootFolder`) drops the shelf assignments of a purged tombstone as before but the annotations only if the file no longer exists below any `/<user>/files` (trash bin does not count; a failing lookup counts as "exists"). It also sweeps annotations without a book row (`AnnotationMapper::findWithoutBook`) and deletes those whose file is gone. Reading progress is still never deleted by the job. Decision: no reason column on the book row; "the file is gone" is checked when it matters, which also covers files deleted while the row was already purged.
- **`meta_updated_at`** on `ebookreader_books` (migration `Version1007Date20261007100000`): see `docs/CONTRACTS-v5.md`, section 4. `BookMapper` overrides `insert`/`update` to maintain it, so editor, cover upload and indexing are covered without each writer remembering it.
- **Constructor changes:** `EditorService`, `ConvertService` (before the optional `ComicWriter`), `MetadataService` (optional, last), `EditorController`/`ConvertController` (`TaskService`), `ComicController` (`ArchiveCache` replaces `ITempManager`), `ItemController` (`ArchiveCache` replaces `ITempManager`), `CleanupTombstonesJob`, `UserDeletedListener` (`TaskMapper`). Existing tests were adapted.
- **ArchiveCache:** a path returned by `localPath()` holds a shared `flock` on `<entry>.lock` until `release($path)`; cleanup/other-etag removal takes a non-blocking exclusive lock and skips entries in use (so the cache can temporarily exceed its limit while files are being read). The lock state is static per process. Local, unencrypted storage returns the original path without any lock. `MetadataService::extract()` uses the cache only if the file is local or fits `archive_cache_mb`, otherwise it copies to a temp file as before; for local files it now reads in place.
- **Raw copy:** metadata-only writes for CBZ/EPUB copy the source file and replace only `ComicInfo.xml` / the OPF via `addFromString` (deflate) in the copy (`EditorUtil::replaceInCopy()`); entries with unsafe names are deleted from the copy as the old rewrite dropped them. FB2/FBZ keep the old path (single document). `write()` of all editors takes an optional `?callable $progress` (fraction 0..1, English step); `EditorService` scales the editor part to 0..0.9 and reports verification/saving afterwards.
- `/item/{fileId}` now has `UserRateLimit(1200, 60)` (it had none) and serves `fbz` (`.fbz`, `.fb2.zip`). The new `GET /apps/ebookreader/archive/{fileId}/entries` (limit 120/min) answers 304 when `If-None-Match` matches; its ETag is `md5('entries|' . fileEtag)`, the JSON field `etag` is the file etag.
- Tests need `tests/Unit/Service/DoctrineStubs.php` (constants only) for the mapper-level claim test because doctrine/dbal is not installed in the dev environment.

## Book flags and continue the series

- **Completion is per book app data** (like rating/read status): column `completion`, never read from or written into the file, not part of the `overrides` mechanism.
- **Age rating is file metadata with a manual override, but not an `overrides` field.** `Book::OVERRIDABLE_FIELDS` drives the editor (comparisons, write-back into EPUB/ComicInfo/sidecar, `POST .../reset-overrides`); the age rating is never written into files, so adding it there would make the editor treat it as writable metadata. Instead three columns: `age_rating` (effective), `age_rating_file` (value of the last index, always refreshed) and `age_rating_manual`. Indexing calls `Book::applyFileAgeRating()` (also while a WriteMetadataJob is pending), which only changes the effective value when it is not manual. Setting a value in the app (including "none") makes it manual; `resetAgeRating` restores the stored file value without re-reading the file. The API exposes `ageRating` and `ageRatingManual`, not the file value.
- Only ComicInfo.xml `AgeRating` and EPUB 3 `schema:typicalAgeRange` are read (both cheap, the documents are parsed anyway). Calibre custom columns, FB2 and MOBI have no common field and are not read. Unclear named ratings map to common equivalents (ESRB Teen ~ USK 12), bare ages round up so a mapped rating is never lower than the source.
- **Filter syntax** `age:<=N` (not `maxage:N`) so it fits the existing `type:name` parser and the URL/smart shelf formats; `age:none` and `completion:unknown` cover NULL.
- **Bulk edit:** completion and age rating use a new light endpoint `PATCH /books/app-data` (database only, no task) instead of extending `POST /books/bulk-metadata`, which writes files and may run as a task. The bulk dialog sends both requests when both kinds of sections are ticked.
- **Up next** is computed in `ProgressController::recent` from the progress rows it already loads (one extra query for the volumes of the affected series), so `upNext` costs nothing when no recently read series volume is finished. `GET /books/{fileId}/next` returns the plain next volume (any status); the reader prompt and the "Next volume" button in the details use it.
- **Reader:** the "Continue with Volume n" prompt appears once per opened book when the position reaches 98 % (the server's finished threshold) and the book has a series. `App.vue` keys the reader/editor component by route path, so going from `/read/1` to `/read/2` mounts a fresh reader.
- The new migration needs a version bump in `appinfo/info.xml` before an existing installation runs it (done in the release preparation).
