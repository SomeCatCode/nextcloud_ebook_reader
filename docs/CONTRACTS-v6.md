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
