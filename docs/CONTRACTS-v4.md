# Contracts round 4: shelves, series, upload, tag hierarchy

The earlier contracts (v1 to v3, SECURITY-FIXES) still apply. OCS base `/ocs/v2.php/apps/ebookreader/api/v1`; all endpoints `#[NoAdminRequired]`, everything scoped by user, write endpoints with a `#[UserRateLimit]`.

## 1. Shelves (owner B = backend)

**Migration `Version1004Date20261002090000`** creates two tables:

`ebookreader_shelves`

| Column | Type / content |
|---|---|
| `id` | |
| `user_id` | string(64) |
| `name` | string(255) |
| `type` | string(8): `manual` or `smart` |
| `query` | text, JSON, smart shelves only |
| `sort_order` | int |
| `created_at`, `updated_at` | bigint, ms |

Index `ebr_shelves_user`.

`ebookreader_shelf_books`

| Column | Type / content |
|---|---|
| `id` | |
| `shelf_id` | |
| `file_id` | bigint |
| `position` | int |
| `added_at` | bigint |

Unique index `ebr_shelfb_sf` on (`shelf_id`, `file_id`), index `ebr_shelfb_file` on (`file_id`).

**Shelf JSON:**
```
{ id, name, type, query: SmartQuery|null, count, coverFileIds: number[] /*max 4*/, sortOrder, createdAt, updatedAt }
```

**SmartQuery** is the library filter state:
```
{ include: string[] /*"type:name"*/, exclude: string[], match: 'all'|'any', search: string, status: string|null, sort: string, order: 'asc'|'desc' }
```
Validation: max 4 KB, known keys only, at most 50 terms.

**Endpoints**

| Endpoint | Behaviour |
|---|---|
| `GET /shelves` | `{shelves: Shelf[]}` sorted by `sortOrder`, then name. `count` for smart shelves = current hit count. |
| `POST /shelves` | Body `{name, type, query?}` returns `Shelf`. Name 1–255 characters, unique per user (case-insensitive), max 200 shelves per user. |
| `PATCH /shelves/{id}` | Body `{name?, query?, sortOrder?}` returns `Shelf`. |
| `DELETE /shelves/{id}` | Books stay, only the assignments are removed. |
| `POST /shelves/{id}/books` | Body `{fileIds: int[]}` (max 500), manual shelves only. Only books from the user's own library (non-deleted book rows). Returns `{added, skipped}`. |
| `DELETE /shelves/{id}/books` | Body `{fileIds}`, returns `{removed}`. |
| `PUT /shelves/{id}/books/order` | Body `{fileIds}`: new order for a manual shelf. |

**Book list filter:** `GET /books` takes the new include/exclude term `shelf:<id>`.
- On a manual shelf it filters by assignment.
- On a smart shelf it is resolved server-side to that shelf's saved query, combined with the remaining filters (AND).
- An `include` of `shelf:<id>` on a manual shelf combined with `sort=shelf` sorts by `position`.

**Cleanup:**
- Book tombstoned or deleted: remove its assignments. Because tombstones can come back, the assignments are only removed in `CleanupTombstonesJob` together with the row.
- `UserDeletedListener` removes the user's shelves.

## 2. Series (owner B)

`GET /series` takes the same filter parameters as `GET /books`.
- It returns `{series: [{name, count, readCount, coverFileIds: number[] /*max 3, lowest seriesIndex first*/, firstFileId, lastAddedAt}]}`.
- Sorting: name (natural), or `sort=added` by `lastAddedAt`.
- At most 2000 series.

`GET /books` new parameter `inSeries=0|1`: `0` returns only books without a series, `1` only books with one. The existing `series:<name>` term shows the volumes of one series. With `sort=series` they are ordered by `series_index`, then title.

## 3. Tag hierarchy (owner B)

- Hierarchy separator `/` in genre and tag names, e.g. `Fantasy/High Fantasy`. Up to 5 levels, spaces around `/` are trimmed.
- New term form `tag:Fantasy/*` (and `genre:…/*`): matches `Fantasy` itself and everything below `Fantasy/`, case-insensitive. Works in include and exclude.
- `GET /facets` returns flat names as before. The frontend builds the tree. Counts for parent nodes are computed in the frontend as the number of distinct books where possible, otherwise as a sum marked "≈". Backend extension `facets.tagTree` is not needed.

## 4. Upload (owner F = frontend)

- Upload via `@nextcloud/upload` (`getUploader()`), with chunking so 300–400 MB work. If the package is missing, install it with `npm install @nextcloud/upload` and pin the version.
  - Target folder: first library folder or one chosen via the FilePicker; existing files are not overwritten (rename to "name (2).ext" or ask).
  - Allowed extensions only: epub mobi azw3 fb2 fbz cbz cbr cb7 cbt.
- Entry points:
  - drag & drop onto the library (overlay "Drop to add to your library")
  - "Upload" button in the navigation and the empty state
- Progress per file and overall; cancel possible.
- After the upload, the server indexes the files via the existing file events. The frontend reloads the library and the facets after completion; for files over 20 MB the book appears with a delay, so it shows a toast "Large files are being indexed in the background".

## 5. Frontend (owner F)

**Navigation section "Shelves"**
- Manual and smart shelves with count and an icon (smart = filter icon).
- Actions: create, rename, delete with confirmation, reorder via drag & drop or up/down.
- "Save current filter as smart shelf" in the FilterBar, offered when filters are active.

**Viewing a shelf**
- A manual shelf: `include=shelf:<id>`, `sort=shelf`.
- A smart shelf: applies the saved query as the filter state and shows the shelf name as the heading. If the user changes the filter, it offers "Update shelf".

**Adding books to shelves**
- Book details: "Add to shelf" (multi-select of manual shelves, "+ New shelf").
- Selection toolbar: "Add to shelf…" and, inside a manual shelf, "Remove from shelf".
- Drag & drop of cards onto a shelf in the navigation is nice to have.

**Series view**
- Grid toggle "Group series" (persisted in localStorage with try/catch).
- When on: series cards on top (`/series` with the current filters), stacked covers, name, "n volumes" and read progress n/m. Below them the books without a series (`inSeries=0`, paginated as before).
- Clicking a series card shows its volumes (`include=series:<name>`, `sort=series`) with a back button.

**Tag hierarchy**
- Genres and tags in the navigation as a collapsible tree.
- Tri-state applies per node: on a parent node it produces `tag:Name/*`.
- The chips show full names; the FilterBar shows `Fantasy/*` as "Fantasy (+ sub)".

**Plumbing**
- Types and API functions in `src/types.ts` / `src/services/api.ts`.
- Vitest for:
  - tree building from flat names
  - query building for shelf/series/hierarchy terms
  - the upload extension and conflict rules

## Ownership

| Owner | Files |
|---|---|
| B | `lib/**`, `tests/Unit/**`, `openapi.json`, README section "Regale" (German) |
| F | `src/**`, `packages/**` (not expected), `package.json`/lock (only for `@nextcloud/upload`) |

Both add German CHANGELOG entries under `[Unreleased]`. Re-read the file right before editing. The version in `info.xml` + `package.json` is set to 0.5.0 by B (new migration).
