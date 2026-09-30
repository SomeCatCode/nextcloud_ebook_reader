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
- `ProgressService` constructor: `(ProgressMapper, BookMapper, ITimeFactory)`. `remapAfterEdit`: mapped to null => `href` becomes `''`, `cfi`/`progression`/`position` dropped, `totalProgression` kept. Locator `href` must be non-empty on PUT.
- Read-status auto update only bumps book `updated_at` when the status actually changes.
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
