# foliate-js (vendored)

- Upstream: https://github.com/johnfactotum/foliate-js (MIT, see LICENSE)
- Commit: `78914aef4466eb960965702401634c2cb348e9b1` (main at vendoring time, 2026-09-30); also in `COMMIT`
- Update: `node scripts/update-foliate.mjs [--ref <sha>]` (re-copies the files and re-applies the patches below; fails loudly if a patch no longer matches)
- Included: view, paginator, fixed-layout, epub, epubcfi, mobi, fb2, comic-book, progress, overlayer, search, text-walker, tts, vendor/zip.js, vendor/fflate.js
- Not included: pdf.js (+ pdfjs), dict, opds, footnotes, quote-image, reader.js, ui/

## Patches (marked `// EBOOKREADER PATCH`)

1. `paginator.js`: section iframe `sandbox` is `allow-same-origin` only (upstream: `allow-same-origin allow-scripts`).
   `globalThis.__EBOOKREADER_SANDBOX` can override it (reader-core option `sandboxScripts`, WebKit workaround).
2. `fixed-layout.js`: same change for the fixed-layout iframes.
3. `view.js`: `makeBook` no longer imports `./pdf.js` (not vendored) and throws `UnsupportedTypeError` for PDFs.
   reader-core never calls `makeBook`; it builds books itself (`src/open-book.ts`).
