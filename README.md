# E-Book Reader for Nextcloud

Library, reader and editor for e-books and comics (EPUB, MOBI, AZW3, FB2, CBZ, CBR) inside Nextcloud,
with reading-progress sync and metadata/content editing.
Licence: AGPL-3.0-or-later. Target: Nextcloud 34, PHP >= 8.2.

See `PLAN.md` for the project plan and `docs/CONTRACTS.md` for the technical contracts.

## Development

Requirements: Docker, Node >= 22.

| Command | Purpose |
|---|---|
| `make build` | install npm deps and build the frontend into `js/` |
| `npm run watch` | rebuild the frontend on change |
| `make dev-up` / `make dev-down` | start / stop Nextcloud 34 + MariaDB + Redis on http://localhost:8080 (admin/admin) |
| `make enable` | `occ app:enable ebookreader` (after the first start) |
| `make dev-reset` | wipe volumes and restart |
| `make test-php` | PHPUnit inside a PHP 8.3 container (with zip/gd) |
| `make test-js` | Vitest |
| `make lint` | ESLint, vue-tsc, php -l, Psalm |
| `make openapi` | regenerate `openapi.json` |
| `make appstore` | create `build/artifacts/ebookreader.tar.gz` |
