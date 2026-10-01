# Contracts, round 2 (tags, organise, formats)

This round builds on `docs/CONTRACTS.md`, which stays binding. This file only adds to it. Deviations go into `docs/DEVIATIONS.md` under the worker heading (`V2-A`, `V2-B`, `V2-C`).

## Goals
1. **Tags editable in the preview:** genres and tags can be added and removed directly in the book detail sidebar, and are saved immediately. Clicking a chip filters by it.
2. **Complex tag filter:** filter by several genres and tags combined (all of / any of) and exclude tags (NOT).
3. **Organise:** rename books and sort them into folders by a pattern, with a preview before applying (single book or selection). Additional sort keys in the library.
4. **CBR covers:** cover and metadata for CBR, server-side through an archive tool if one is installed, otherwise the browser generates the cover on first open and uploads it.
5. **Formats:** new formats CB7 (7z) and CBT (tar). Conversion between comic formats and comic → EPUB (fixed layout), with an explanation of the pros and cons of each format. Writing CBR is not possible (RAR is proprietary), and the UI explains that.

## API additions (OCS `/ocs/v2.php/apps/ebookreader/api/v1`)

### Filter (owner V2-A)
`GET /books` takes additionally:
- `include[]`: values of the form `genre:<name>`, `tag:<name>`, `author:<name>`, `series:<name>`, `format:<fmt>`
- `exclude[]`: same form; books that have ANY of these are excluded
- `match`: `all` (default, the book must have every include) or `any` (at least one)

The old single params `genre`, `tag`, `author`, `series`, `format` keep working: each counts as one more `include` entry.

`GET /facets` unchanged.

### Inline tags (existing)
`PATCH /books/{fileId}/metadata` with `{genres: string[], tags: string[]}`. This already exists (EditorService::saveMetadataOnly, which writes into the file when possible, otherwise to the DB). Response `{book, warnings}`.

### Organise (owner V2-A)
- `POST /organize/preview` with body `{fileIds: int[], pattern: string, targetFolder?: string}` → `{items: [{fileId, from, to, status: 'move'|'unchanged'|'conflict'|'error', message?}]}`
- `POST /organize/apply` with the same body → `{items: [...same, status 'moved'|'unchanged'|'failed'], moved: int, failed: int}`

Pattern rules:
- Placeholders: `{author}` (first author), `{authors}` (all, `, `), `{title}`, `{series}`, `{series_index}`, `{series_index:2}` (zero-padded to 2 digits), `{year}`, `{publisher}`, `{language}`, `{genre}` (first genre), `{format}`.
- `/` creates subfolders. The file extension is always appended automatically.
- Empty placeholders are removed together with a neighbouring separator. Recognised separators: ` - `, ` – `, `, `, `_`, and empty folder levels.
- Path components are sanitised per NC34 rules (IFilenameValidator or the same rules as RenameService) and capped at 255 bytes.
- `targetFolder` defaults to the first library folder of the user; it must lie inside the user's storage.
- Collisions with existing files or within the selection → status `conflict`; `apply` resolves them with ` (2)`, ` (3)`.
- Move via `Node::move` creating the folders. The book's `path` in the DB is updated, the fileId stays the same.
- Folders left empty after moving are removed only if they lie inside the library and are really empty.
- Limit 500 files per request.

### Formats and conversion (owner V2-C)
- `GET /convert/capabilities` → `{tools: {sevenZip: bool, unrar: bool, bsdtar: bool}, server: {read: string[], write: string[]}}` (formats the server can read and write)
- `GET /books/{fileId}/convert` → `{source: string, targets: [{format, mode: 'server'|'client'|'unavailable', reason?: string}]}`
- `POST /books/{fileId}/convert` with body `{target: string, deleteOriginal: bool}` → `{book: Book, fileId: int, path: string}` (server mode only; 409 if the target file already exists)
- Client mode: the browser reads the source via WebDAV and libarchive.js, writes the target (libarchive.js write: ZIP/7z/tar; EPUB via fflate), uploads it via WebDAV PUT, then calls `POST /scan`.
- Format keys: `cbz`, `cbr`, `cb7`, `cbt`, `epub` (comic → fixed-layout EPUB). CBR is always `unavailable` as a target, with a reason.

### Covers for CBR/CB7 (owner V2-C)
- Server side: `lib/Service/ArchiveTools.php` detects `7zz`/`7z`/`7za`, `unrar` and `bsdtar` (PATH + /usr/bin, /usr/local/bin; result cached per request). It offers `list(path): string[]` and `extract(path, entry): string` (via proc_open with an argument array, no shell, size limit, timeout).
- `CbrExtractor` (also used for cb7) reads ComicInfo.xml and the first image through ArchiveTools; if no tool is present it falls back to the filename only, as before. CBT works through PHP `PharData` without any tool.
- Client fallback: when the reader opens a CBR/CB7/CBT locally and `book.hasCover === false`, it scales the first page to about 600 px and sends it via `POST /apps/ebookreader/cover/{fileId}` (exists already).

## File ownership in this round
| Worker | Owns |
|---|---|
| V2-A backend tags/organise | `lib/Service/LibraryService.php` (only `findBooks`/`applyFilters`/`getFacets` and helpers they need), `lib/Service/BookQuery.php`, `lib/Controller/BooksController.php` (new params), new `lib/Service/OrganizeService.php`, new `lib/Controller/OrganizeController.php`, `lib/ResponseDefinitions.php` (additions only), tests for these, `openapi.json` |
| V2-B frontend library | `src/types.ts` and `src/services/api.ts` (additions for filter/organise), `src/stores/**`, `src/views/LibraryView.vue`, `src/components/library/**`, new `src/components/organize/**` |
| V2-C formats | `lib/Metadata/*` (incl. new CbrExtractor, MetadataService detectFormat), new `lib/Service/ArchiveTools.php`, new `lib/Service/ConvertService.php`, new `lib/Controller/ConvertController.php`, `lib/Controller/ComicController.php` (cbt/cb7/cbr pages when readable), `lib/AppInfo/Application.php` (MIME_TYPES), `lib/Migration/RegisterMimeTypes.php`, `lib/Capabilities.php`, `lib/Preview/*`, `packages/reader-core/**`, `src/services/bookSource.ts`, `src/views/ReaderView.vue` (only the cover fallback), `src/viewer.ts`, `src/files.ts`, new `src/convert/**` (incl. `convertApi.ts`, `types.ts`, `formats.ts` with pros/cons texts), new `src/components/convert/ConvertDialog.vue` (props `{ book: Book }`, emits `close`, `converted(fileId: number)`), tests for these |

V2-B embeds `ConvertDialog.vue` in `BookDetails` (button "Convert format"), using the props and emits above. Until V2-C delivers it, a typecheck error on that import is acceptable; the integration step fixes it.
