# Nextcloud Playground

[Nextcloud Playground](https://github.com/ateeducacion/nextcloud-playground) runs a complete Nextcloud in the browser (PHP as WebAssembly, SQLite in memory). The file `blueprint.json` in this folder starts it with the E-Book Reader and six sample books:

**[Try the E-Book Reader in Nextcloud Playground](https://ateeducacion.github.io/nextcloud-playground/?blueprint-url=https://raw.githubusercontent.com/SomeCatCode/nextcloud_ebook_reader/playground/blueprint.json)**

The playground signs in as `admin` (password `admin`) and opens the library. Nothing leaves the browser; closing the tab deletes everything.

## How it works

The blueprint loads its files from the branch `playground` of this repository. `raw.githubusercontent.com` sends CORS headers; GitHub release downloads do not, so the playground cannot load them directly.

| File on the branch `playground` | Source |
|---|---|
| `blueprint.json` | copy of `playground/blueprint.json` |
| `ebookreader.zip` | the app package of the latest release, as ZIP (the playground installs ZIP files only) |
| `books/*` | the sample books, made with `playground/fetch-books.py` |
| `README.md` | copy of this file |

The release workflow (`.github/workflows/release.yml`, job `playground`) updates the branch after every release that is not a pre-release. The workflow `playground.yml` does the same on request (Actions → Playground → Run workflow). The branch always has one commit; every update replaces it.

To change the sample books:

1. Run `python3 playground/fetch-books.py <directory>`.
2. Copy the files to `books/` on the branch `playground` and commit.

## Limits of the playground

- The playground supports Nextcloud 30 to 33. The E-Book Reader needs 33.
- No server-side tools (`proc_open`): RAR and 7z comics open in the browser only, conversion on the server does not work.
- No previews of Nextcloud Files; the library covers of the app work.
- Background jobs run only while a page is open.

## Sample books and licenses

| Book | Author | License |
|---|---|---|
| Alice's Adventures in Wonderland | Lewis Carroll | Public domain, [Project Gutenberg #11](https://www.gutenberg.org/ebooks/11) |
| Frankenstein | Mary Shelley | Public domain, [Project Gutenberg #84](https://www.gutenberg.org/ebooks/84) |
| Die Verwandlung | Franz Kafka | Public domain, [Project Gutenberg #22367](https://www.gutenberg.org/ebooks/22367) |
| Pepper&Carrot, episodes 1–3 | [David Revoy](https://www.davidrevoy.com) | [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/), [www.peppercarrot.com](https://www.peppercarrot.com) |

The Gutenberg EPUBs are unchanged and keep the Project Gutenberg license text. The Pepper&Carrot pages are unchanged; the CBZ files add a `ComicInfo.xml` with title, author and license.
