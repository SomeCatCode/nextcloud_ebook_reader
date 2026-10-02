# Contributing

Bug reports and feature requests are welcome as [issues](https://github.com/SomeCatCode/nextcloud_ebook_reader/issues). For larger changes, please open an issue first so we can agree on the approach.

## Development setup

Requirements: Docker, Node.js 22 or later, PHP 8.2 or later with `zip` and `gd`.

```sh
make build && make dev-up && make enable
```

Nextcloud 34 then runs on http://localhost:8080 (credentials in `docker/docker-compose.yml`). `npm run watch` rebuilds the frontend on changes.

## Checks

All of these run in CI and must pass before a pull request is merged:

| Command | Purpose |
|---|---|
| `make test-php` | PHPUnit (in the PHP test container) |
| `make test-js` | Vitest |
| `make lint` | ESLint, vue-tsc, `php -l`, Psalm |
| `composer run cs:check` / `cs:fix` | PHP code style (php-cs-fixer) |
| `make openapi` | regenerate `openapi.json` after API changes (commit the result) |
| `make appstore` | build the installable package in `build/artifacts/` |

## Project layout

```text
lib/                   PHP backend (controllers, services, metadata, editor, background jobs)
src/                   Vue 3 frontend
packages/reader-core/  framework-independent reader core based on foliate-js
tests/                 PHPUnit tests and generated test books (tests/fixtures/generate.php)
docs/                  security notes, release process, API contracts
```

## Conventions

- Keep every change listed in `CHANGELOG.md` under `## [Unreleased]`.
- Files use LF line endings.
- New entities must mark all fields on creation (see `lib/Db/MarksFieldsOnCreate.php`); MariaDB in strict mode rejects inserts that skip NOT NULL columns.
- Book content is untrusted: never render book HTML outside the sandboxed reader, and follow [docs/SECURITY-READER.md](docs/SECURITY-READER.md).
- Translations: UI strings use `t('ebookreader', …)` with English source text.
