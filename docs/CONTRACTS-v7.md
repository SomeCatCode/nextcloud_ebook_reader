# Contracts v7: natural volume order

**Order of volumes** (everywhere: SQL `sort=series`, series drill-in, `GET /series` (`coverFileIds`, `firstFileId`), `GET /books/{fileId}/next`, OPDS, dashboard): `series_index` ascending (volumes without a number last, also with `order=desc`), then title in natural order ("Vol 2" before "Vol 10", case-insensitive), then id.

**Volume number source:** metadata `series_index`; if it is missing and the book has a series, the number parsed from the title, then from the file name (`lib/Metadata/VolumeNumber.php`): `Vol. 10`, `Volume 12`, `v02`, `Band 3`, `Tome 4`, `Book 2`, `#7`, `No. 5`, then chapters (`Ch. 5`, `Chapter 5`, `Kapitel 5`, `Ep. 5`), `第3巻`, a trailing number (`Saga 10`, `Saga 10 (Digital)`), and for file names a leading number (`01 - Title.cbz`). Decimals (`10.5`) are kept, years (1900-2100) are no volume numbers. The parsed number is stored in `series_index` at indexing time and marked with `series_index_derived = true`; a metadata value, an app override or an edit in the editor wins (and clears the mark). Re-indexing recomputes it.

**Migration `Version1010Date20261027100000`:** `title_sort` (string 768, null) = lower-cased title (file name without title) with every run of digits zero-padded to 12 places (`SeriesOrder::sortKey`), `series_index_derived` (bool, default false). `BookMapper` keeps `title_sort` up to date on insert/update. The migration fills both for existing rows. `sort=title` uses `title_sort`, too (natural).

**Rename / organize:** `{series_index}` is zero-padded to the width of the highest volume number of the whole series (min. 2 digits: `01`..`09`, `10`; `001` from 100 volumes), decimals keep their fraction (`05.5`). `{series_index:raw}` is not padded, `{series_index:N}` pads to exactly N digits (as before).

**Capabilities:** `ebookreader.features` contains `series-order`. Not in the API: `series_index_derived` and `title_sort` are internal.
