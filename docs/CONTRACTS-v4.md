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

## 4. Annotations: highlights, notes, bookmarks (round 5)

For the web reader and the Android app. Same rules as above (OCS, `#[NoAdminRequired]`, everything scoped by the session user, `#[UserRateLimit]` on writes). Timestamps are milliseconds since the epoch.

**Migration `Version1005Date20261003000000`:** table `ebookreader_annotations` with `id`, `user_id`, `file_id`, `type`, `uuid` (unique per user, `ebr_annot_uuid`), `locator` (JSON text), `text`, `note`, `color`, `created_at`, `updated_at` (server time, drives the sync cursor), `client_updated_at` (last-write-wins clock), `deleted` (0/1 tombstone). Indexes on (`user_id`, `file_id`) and (`user_id`, `updated_at`).

**Annotation JSON**
```
{ uuid, fileId, type: 'highlight'|'note'|'bookmark', locator, text: string|null, note: string|null,
  color: 'yellow'|'green'|'blue'|'pink'|'purple'|null, createdAt, updatedAt, clientUpdatedAt, deleted: bool }
```
- `locator` has the shape of the progress locator (`{href, type?, title?, locations?: {progression?, totalProgression?, position?, cfi?}}`, max 4096 bytes, `href` required).
- Highlights and notes of reflowable books carry the **range CFI** of the text in `locations.cfi` (that is what the web reader draws). `href` is the section id, `totalProgression` the position in the book at creation time (used for sorting when no CFI comparison is possible).
- Bookmarks of reflowable books carry the point CFI of the start of the page, bookmarks of comics `locations.position` (1-based page index) and the page name in `href`.
- `text` max 2000 characters, `note` max 10000 characters (limits count characters, not bytes). A highlight with a non-empty `note` is shown as a note; `type: 'note'` is what the web reader creates when the note is written together with the selection.
- Max 5000 live annotations per book and user.

**Endpoints**

| Endpoint | Behaviour |
|---|---|
| `GET /books/{fileId}/annotations` | `{annotations: Annotation[]}` without tombstones, oldest first. |
| `POST /books/{fileId}/annotations` | Body `{type, locator, uuid?, text?, note?, color?, clientUpdatedAt?, createdAt?}`. **Upsert by uuid** (server generates one when missing). `createdAt` keeps the original time of an annotation made offline (clamped to now). Returns the Annotation. A tombstoned uuid with a newer `clientUpdatedAt` is revived. |
| `PATCH /annotations/{uuid}` | Body `{locator?, text?, note?, color?, clientUpdatedAt?}`. Missing = unchanged, empty string for `note`/`color` clears. `404` for unknown or deleted annotations. |
| `DELETE /annotations/{uuid}?clientUpdatedAt=` | Sets the tombstone and returns it (idempotent). |

- **Conflicts:** last write wins on `clientUpdatedAt` (default: server time; values more than 5 minutes ahead are clamped to now). A write with an older `clientUpdatedAt` than the stored one is rejected with **409** and `{current: Annotation}`; equal or newer wins. This applies to POST (upsert), PATCH and DELETE.
- **Access:** `404` when the file is not accessible, `403` when the content may not be read (share with download disabled). `400` for invalid type, color, uuid (must be a UUID), locator or lengths, and when the uuid already belongs to another book.
- The user always comes from the session; `user_id` is never accepted from the client.

**Sync:** `GET /sync` gains `annotations: Annotation[]` (with tombstones, `deleted: true`, ordered by `updatedAt`, `id`; max 500 per call, `hasMore` covers it). The cursor has a third part `a` (older cursors without it start the annotations from the beginning). Clients apply rows by `uuid`: `deleted: true` removes the local row, otherwise they replace it unless their own `clientUpdatedAt` is newer. Capability flag: `ebookreader.annotations: true` in `/ocs/v2.php/cloud/capabilities`.

**Cleanup:** deleting a user removes all annotations. When a book tombstone is finally purged (`CleanupTombstonesJob`, 30 days) its annotations for that user go with it. Annotation tombstones are purged after 90 days; an offline client that comes back later than that can recreate its annotations with the same uuid.

## 5. Book flags and "continue the series" (round 6)

For the web UI and the Android app. Same rules as above. Timestamps are milliseconds.

**Migration `Version1006Date20261005150000`:** nullable columns on `ebookreader_books`: `completion` string(12), `age_rating` smallint, `age_rating_file` smallint, `age_rating_manual` bool (default false). Existing rows keep NULL (unknown / no rating).

**Book JSON** (every endpoint returning `Book`, including `GET /sync`) gains:

| Field | Type | Meaning |
|---|---|---|
| `completion` | `'ongoing' \| 'completed' \| null` | app data per book, never written into the file; null = unknown |
| `ageRating` | `0 \| 6 \| 12 \| 16 \| 18 \| null` | effective rating ("from N years"): the manual value if `ageRatingManual`, else the one read from the file; null = none |
| `ageRatingManual` | bool | the rating was set in the app (also an explicit "none") and survives re-indexing |

**Age rating from the file** (on every (re-)index, stored in `age_rating_file`): ComicInfo.xml `AgeRating` (CBZ/CBR/CB7/CBT) and EPUB 3 `<meta property="schema:typicalAgeRange">`. Named ComicInfo values map by a table: Everyone, Early Childhood, G, E → 0; Kids to Adults → 6; Everyone 10+/E10+, PG, Teen → 12; T+, M, MA15+ → 16; Mature 17+, Adults Only 18+, R18+, X18+ → 18; Unknown, Rating Pending → null. Other text with a number ("16+", "FSK 12", "PEGI 7", "Ages 10-14") and `typicalAgeRange` ("12-", "7-12", "16+") use the (lower) age rounded UP to the next level (13 → 16, 17 → 18, > 18 → 18). Anything else → null.

**`PATCH /books/{fileId}/app-data`** new optional fields (absent = unchanged):
- `completion`: `"ongoing"`, `"completed"` or `null` (clears).
- `ageRating`: `0|6|12|16|18` or `null`. Any value given here is manual (`ageRatingManual = true`), `null` means "explicitly no rating" and also overrides the file.
- `resetAgeRating: true`: drops the manual value, the file's value applies again. Not together with `ageRating` (400).
- Invalid values → 400. A real change bumps `updatedAt` (so `/sync` delivers it); sending the current value again changes nothing.

**`PATCH /books/app-data`** (bulk, max 500): body `{fileIds: int[], completion?, ageRating?, resetAgeRating?}` with the same semantics; at least one field (400 otherwise). Returns `{updated, unchanged, failed: [{fileId, error: 'not_found'|'failed'}]}`.

**Filter terms** (include/exclude of `GET /books`, `GET /series`, smart shelf queries; `type:name` like the others):
- `completion:ongoing`, `completion:completed`, `completion:unknown` (= not set). Excluding `ongoing` keeps unknown books.
- `age:0|6|12|16|18`: exactly this rating. `age:none`: no rating. `age:<=N` (N one of the levels; spaces are dropped, `age:<= 12` becomes `age:<=12`): rated N or lower, **unrated books do not match**; excluding it keeps unrated books. Other names are ignored (dropped by the parser).

**`GET /facets`** gains `completion: FacetEntry[]` (names `ongoing`, `completed`, `unknown`) and `ageRatings: FacetEntry[]` (names `0`, `6`, `12`, `16`, `18`, `none`), always in this order with zeros included. Counts for `age:<=N` are the sum of the levels up to N (computed by the client).

**Reading order of a series** (shared by the endpoints below): volumes with the same series name (case-insensitive, trimmed), ordered by `seriesIndex` ascending (no index last), then title in natural order (file name without a title), then `fileId`. "Next" skips other copies of the same volume (same non-null index, e.g. EPUB and CBZ of volume 3).

**`GET /books/{fileId}/next`** returns `{book: Book|null}`: the volume after this one in reading order (regardless of its read status); null for the last volume or without a series. 404 for an unknown book.

**`GET /progress/recent`** gains `upNext: [{previousFileId, book}]`: for every series whose most recently read volume (by progress `updatedAt`, among the rows the endpoint reads anyway) is finished, the next volume after it that is not finished, if its status is `unread`. Series whose latest volume is still being read are skipped (that volume is in `books`), as are books already in `books`. At most `limit` entries, most recent first. Costs one extra query (all volumes of the affected series).

**Conversion** carries `completion` and a manual age rating to the new book.
