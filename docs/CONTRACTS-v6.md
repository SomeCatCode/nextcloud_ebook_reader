# Contracts round 6: long strip comics

The earlier contracts (v1 to v5, SECURITY-FIXES) still apply. Same rules as the book flags in [CONTRACTS-v4.md](CONTRACTS-v4.md) section 5. Timestamps are milliseconds.

A long strip comic (webtoon) is one tall vertical strip split into images. Clients show it as one seamless vertical scroll (pages stacked without gaps) instead of paging; spread and reading direction do not apply.

**Migration `Version1009Date20261025100000`:** bool columns on `ebookreader_books` (default false): `longstrip` (effective value), `longstrip_file` (recognised in the file at the last indexing), `longstrip_manual` (set in the app, survives re-indexing). Existing rows get false; the file value is filled in at the next indexing of the book (`occ ebookreader:scan <user> --force` for books that did not change).

**Book JSON** (every endpoint returning `Book`, including `GET /sync`) gains:

| Field | Type | Meaning |
|---|---|---|
| `longstrip` | bool | effective value: the manual one if `longstripManual`, else the one recognised in the file |
| `longstripManual` | bool | the flag was set in the app and survives re-indexing |

**From the file** (CBZ/CBR/CB7/CBT, ComicInfo.xml): `Format` containing "Webtoon", "Web Toon", "Long Strip" or "Vertical" (case-insensitive), or a `Tags`/`Genre` entry that is exactly one of these. Other formats are never long strips by file.

**`PATCH /books/{fileId}/app-data`** new optional fields:
- `longstrip`: `true` or `false`, always manual (`longstripManual = true`). `null` → 400.
- `resetLongstrip: true`: drops the manual value, the file's value applies again. Not together with `longstrip` (400).

**`PATCH /books/app-data`** (bulk) accepts the same two fields.

**Filter term** `layout:longstrip` (include/exclude of `GET /books`, `GET /series`, smart shelves). Excluding it keeps every other book. Other `layout:` names are dropped by the parser.

**`GET /facets`** gains `layouts: FacetEntry[]` with the single entry `{name: 'longstrip', count}`.

**Progress:** the locator of a long strip uses `href` = page entry name, `locations.position` = page number (1-based) and, unlike paged comics, `locations.progression` = scroll position inside that page (0..1), so a position inside a tall page is restored. `totalProgression` = (page index + progression) / page count. Paged clients can ignore `progression`.

**Conversion** carries a manual long strip flag to the new book.

## Round 7: read-next suggestions

`GET /ocs/v2.php/apps/ebookreader/api/v1/books/{fileId}/suggestions?limit=4` (OCS, like every endpoint). Capability feature `suggestions` in `ebookreader.features` (server 0.12.0 planned). `limit` 1..10, default 4; other values are clamped. Unknown `fileId` → 404.

Response `data`: `{ "next": Book|null, "similar": Book[] }`. `Book` is the normal serialized book including `progress`, like `GET /books/{fileId}/next`.

**`next`:** the next volume in reading order after the book (same rules as `GET /books/{fileId}/next` / `LibraryService::nextVolume`). `null` for the last volume, for books without a series and when that volume is already `finished`.

**`similar`:** up to `limit` books, best first.
- Candidates: books with `readStatus = unread` (other users' shared books count as the user's books), never the book itself, never a book of the same series (series compared case-insensitive and trimmed).
- At most one book per other series: the first unread volume of that series in reading order. The series ranks by its best scored volume. Books without a series count one by one.
- Score of a candidate:

| Rule | Points |
|---|---|
| per shared author (case-insensitive) | +3 |
| per shared genre (case-insensitive name) | +2 |
| per shared tag (case-insensitive name) | +1 |
| same primary language (`en-US` and `en_GB` are both `en`) | +1 |
| same format family (comic: cbz/cbr/cb7/cbt; otherwise ebook) | +1 |

- Only score > 0 qualifies. Ties: more recently added (`addedAt`) first, then title (case-insensitive), then id.
- The server scores in one SQL query (limited to the 300 best rows before the one-per-series reduction), so large libraries stay cheap. Only the first 12 authors, 12 genres and 12 tags of the source book count.

**Android app (Room):** same algorithm locally. Show it when a book becomes finished (end reached or status `finished`) and in the details of finished books. Gate server calls on the feature `suggestions` (or server version >= 0.12.0). Offline and for local libraries compute it from Room.
