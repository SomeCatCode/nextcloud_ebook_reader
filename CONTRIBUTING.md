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
| `npm run l10n:check` | translation files: placeholders, plural forms, missing and stale keys (see below) |
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
- Translations: UI strings use `t('ebookreader', …)` / `n('ebookreader', …)` with English source text, see below.

## Translations

The app is not in Nextcloud's Transifex pipeline; translations live in the repository:

```text
l10n/<lang>.json              translations (edit this file)
l10n/<lang>.js                generated from the json file, loaded by Nextcloud for the frontend
translationfiles/source.json  generated catalogue of all source strings (do not edit)
```

Languages: `de` (informal, "du"), `de_DE` (formal, "Sie"), `es`, `ja`. English is the source language.

- Write texts as literals: `t('ebookreader', 'Saved {count} books', { count })`, `n('ebookreader', '%n book', '%n books', count)` in Vue/TS, `$this->l10n->t('Author: %s', [$name])` / `->n(…)` in PHP. The extractor can not see texts passed in variables; for wrappers that forward a variable, mark the line with `// l10n-ignore` and keep the literal calls elsewhere (see `tr()` in `src/editor/useEditorState.ts`).
- Translations keep every placeholder of the source (`{name}`, `%s`, `%1$s`, `%d`, `%n`). Plural entries use the key `_<singular>_::_<plural>_` and an array with one entry per plural form (two for de/es, one for ja); the singular form may drop `%n`.

| Command | Purpose |
|---|---|
| `npm run l10n:extract` | scan `src/`, `lib/` and `appinfo/info.xml`, update `translationfiles/source.json`, sort the json files and regenerate `l10n/<lang>.js` (add `-- --prune` to remove stale keys) |
| `npm run l10n:check` | validate without writing; lists missing and stale keys per language |
| `npm run l10n:missing <lang>` | print the missing keys of a language as JSON (English text as value) to translate and paste into `l10n/<lang>.json` |

CI runs `npm run l10n:check`. It fails on malformed files, wrong plural forms, placeholder mismatches and `.js` files out of sync with their `.json`; missing translations and an outdated catalogue are warnings, so a pull request that adds strings does not have to translate them. After adding or changing strings run `npm run l10n:extract` and commit the result; to fill gaps, run `npm run l10n:missing de`, translate, paste into `l10n/de.json` and run `npm run l10n:extract` again.
