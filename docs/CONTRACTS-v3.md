# Contracts round 3: large files (300–400 MB)

`docs/CONTRACTS.md`, `CONTRACTS-v2.md` and `SECURITY-FIXES.md` still apply. This round adds the following on top.

## Goals
- Nothing slow runs synchronously inside a web request: no long editor saves and no conversions in the request.
- Real progress is shown to the user.
- Archives on non-local storage (WebDAV, SMB, S3, encryption) are copied at most once per file version.
- Writing metadata into a ZIP does not re-pack it: unchanged entries are copied raw.
- The reader no longer downloads EPUBs as a whole.
- Browser fallbacks for large files only start after a warning.

## 1. Async tasks (owner L-A)

Table `ebookreader_tasks`, created by a NEW migration `Version1002Date20261001120000`:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | primary key |
| `user_id` | string(64) | |
| `file_id` | bigint | |
| `type` | string(16) | `edit` \| `convert` |
| `status` | string(12) | `queued` \| `running` \| `done` \| `failed` |
| `progress` | float | 0..1 |
| `step` | string(255) | short English description, e.g. "Writing page 180 of 400" |
| `request` | text | JSON |
| `result` | text | JSON, nullable |
| `error` | string(1000) | nullable |
| `created_at`, `updated_at` | bigint | ms |

Index `ebr_tasks_user` on (`user_id`, `status`).

**`TaskService`**
- `create(userId, fileId, type, request): Task`
- `run(taskId): void` executes the task.
- A progress callback writes progress/step to the DB at most once per second, and at the end.
- `get(userId, taskId)` returns 404 for tasks of other users.
- Cleanup: tasks in `done`/`failed` older than 24 h are deleted by `CleanupTombstonesJob`.

**Running a task**
- The OCS controller first validates the request synchronously (permissions, etag, size, read access) and creates the task.
- It returns **202** with `{taskId}`.
- After that the task runs in the same PHP process once the response has been sent: `ignore_user_abort(true)`, `set_time_limit(0)`, `fastcgi_finish_request()` if available, via a shutdown handler or after `Response` output.
  - Find a clean way in the NC34 AppFramework, e.g. `Response::callback`/`CallbackResponse`, or `register_shutdown_function` plus `fastcgi_finish_request`. Document the choice in DEVIATIONS.
  - In addition a `RunTaskJob` (QueuedJob) is queued as a fallback. It does nothing if the task is no longer `queued`. To avoid double runs, `queued` → `running` is an atomic UPDATE with a WHERE on status.
- The environment variable/config `ebookreader.async_inline = false` (IAppConfig `async_inline`, default true) turns off the inline run. Then only the job runs.

**API**
- `PUT /api/v1/books/{fileId}/structure` with query `async=1`: 202 `{taskId}` instead of the synchronous result. Without `async` the endpoint behaves as before (compatibility).
- `POST /api/v1/books/{fileId}/convert` with query `async=1`: the same.
- `GET /api/v1/tasks/{taskId}` returns `{id, fileId, type, status, progress, step, result, error, createdAt, updatedAt}`.
  - `result` for edit is `{book, warnings}`, for convert `{book, fileId, path}`.
- `GET /api/v1/tasks?active=1` returns the running and queued tasks of the user, for a hint after a page reload.
- A conflict (etag) or a permission error discovered during the run → status `failed` with `error` = a short message, plus `result.code` = 409/403/413/422.

Editors and the converter report progress via an optional callback `?callable(float $fraction, string $step)` in `write()` / `convert()` (signature extension; existing calls stay valid).

## 2. Raw-copy ZIP updates (owner L-A)
- Metadata-only writes for CBZ and EPUB: copy the source to a temp file (or use the local path from the archive cache), open it with `ZipArchive`, replace or add only `ComicInfo.xml` / the OPF with `addFromString` (OPF stored deflate, mimetype untouched), then `close()`. libzip copies unchanged entries raw, without recompression.
- Then validate as before and `putContent`.
- Measurable in a test: a CBZ with a large, already compressed page keeps the `compressedSize` and CRC of every page.

## 3. Local archive cache (owner L-A)
- `lib/Service/ArchiveCache.php` provides `localPath(File $file): string` and `release()`.
- For local storage without encryption it returns the original path, no copy.
- Otherwise it copies once to `<tempBaseDir>/ebookreader-cache/<fileId>-<etag>.<ext>` (atomic: temp + rename) and returns that path.
- LRU limit via IAppConfig `archive_cache_mb` (default 2048). Cleanup when the limit is exceeded, deleting the oldest by atime/mtime first, and in `CleanupTombstonesJob`.
- Files of other etags of the same fileId are removed immediately.
- Concurrency: `flock` on a lock file per entry.
- Used by: ComicController, ItemController, EditorService (localSource), ConvertService, MetadataService::extract (only if it fits the cache limit, otherwise as today).

## 4. EPUB streaming (owner L-B, server parts by L-A)
- L-A provides `GET /apps/ebookreader/archive/{fileId}/entries` (FrontpageRoute, `NoCSRFRequired`, `NoAdminRequired`, `canReadContent`).
  - It returns JSON `{etag, entries: [{name, size}]}` for epub, cbz and fbz, with ETag/304 and `private, max-age=300`.
  - It uses the archive cache.
- ItemController serves the bytes of an entry, as today (`/item/{fileId}?id=…&v=<etag>`).
  - Its rate limit must allow normal reading: at least 1200/min.
  - It also allows fbz.
- L-B builds the reader-core `RemoteZipSource` `{kind: 'remote-zip', name, entries, loadEntry(name): Promise<Blob>}` plus a loader for foliate (`entries`, `loadText`, `loadBlob(name, type)`, `getSize`).
  - It caches loaded entries per session in an LRU of about 50 MB.
  - It is used for EPUB (incl. the DRM check) and FBZ.
  - It falls back to the full download if `entries` fails.

## 5. Frontend (owner L-B)
- EditorView: a structure save is sent with `async=1` and then polled via `GET /tasks/{id}` (every 1 s, later 2 s).
  - The progress dialog shows `step` and `progress` as a real bar.
  - After `done` it reloads as today; on `failed` it shows the error, and for code 409 the conflict dialog.
  - Leaving the page is allowed. A hint says the task keeps running on the server.
- ConvertDialog: server conversions with `async=1` and polling, using the same progress component (`src/components/common/TaskProgress.vue`).
- Library: when `GET /tasks?active=1` returns tasks, show a small banner ("1 task running…") with progress. Poll every 3 s while tasks are active.
- Client fallbacks that download the whole file (CBR/CB7 without server tools in the reader, client conversion, CBR→CBZ in the editor): if `book.size` is over 50 MB, show a confirmation dialog first.
  - Text: the file is X MB and will be downloaded completely and unpacked in the browser; installing `7z` on the server does this on the server instead.
  - Buttons: Continue / Cancel.

## 6. Scan (owner L-A)
- Interactive inline indexing (`scanUserInteractive`) only processes files ≤ 50 MB. Larger files are always queued.
- The FileEventListener is unchanged (already > 20 MB → job).

## 7. Docs (owner L-A)
README section "Große Bibliotheken / große Dateien":
- recommended cron
- `occ background-job:worker` (NC 27+), with an example systemd unit
- `archive_cache_mb`, `async_inline`, `max_edit_size_mb`
- installing 7z (Debian/Ubuntu `p7zip-full` or `7zip`, Alpine `7zip`, Docker hint)

## File ownership
| Worker | Owns |
|---|---|
| L-A | `lib/**` (except where L-B is named), new migration, `lib/Db/Task*`, `TaskService`, `ArchiveCache`, `TaskController`, `ArchiveController`, `RunTaskJob`, changes to EditorController/ConvertController/ComicController/ItemController/EditorService/ConvertService/Editors/MetadataService/LibraryService (scanUserInteractive only)/CleanupTombstonesJob, `tests/Unit/**`, `README.md`, `openapi.json` |
| L-B | `packages/reader-core/**`, `src/**` (incl. `types.ts`, `api.ts`), new `src/components/common/TaskProgress.vue` |
| Both | `CHANGELOG.md`: only append under `## [Unreleased]`, using German entries; edit with a fresh read right before writing, so you don't overwrite the other worker's entries |
