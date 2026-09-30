# Reader security (spike 1, PLAN 11)

## Findings in foliate-js (commit 78914ae)

- Book sections are rendered by `paginator.js` (one iframe) and `fixed-layout.js` (one iframe per page).
  Both load the section from a `blob:` URL created by the book object (`epub.js`, `mobi.js`, `fb2.js`,
  `comic-book.js` all use `URL.createObjectURL`). `blob:` URLs inherit the origin of the creator, i.e. the Nextcloud origin.
- Upstream sets `sandbox="allow-same-origin allow-scripts"` on these iframes (with a comment about WebKit bug 218086).
  `allow-same-origin` + `allow-scripts` on a same-origin document lets a script remove the sandbox and access
  `window.parent` / cookies / the Nextcloud API with the user's session. A crafted EPUB with `<script>` is therefore
  an XSS vulnerability if used unmodified. EPUB `<script>` items are also loaded as blob resources by `epub.js`.
- foliate needs same-origin DOM access from the parent (it styles the document, measures, adds listeners) but does not need scripts inside the iframe.

## Implemented setup (defense in depth)

1. **No scripts in section iframes.** Vendored copy patched (2 lines, marked `EBOOKREADER PATCH`, see VENDORED.md):
   `sandbox="allow-same-origin"`. No `allow-scripts`, `allow-popups`, `allow-top-navigation`, `allow-forms`.
2. **EPUB script resources are not loaded** (`book.transformTarget` `load` event: `allow = false` for script items).
3. **Per-section CSP meta** (`packages/reader-core/src/secure-sections.ts`): every HTML/XHTML section is re-serialised through
   `DOMParser` and gets `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src blob: data:; media-src blob: data:; style-src blob: 'unsafe-inline'; font-src blob: data:">`
   as first child of `<head>`. This blocks remote resource loading (tracking pixels, remote fonts/images = privacy) and script execution even if
   an iframe were ever granted `allow-scripts`. The parser-based insertion cannot be bypassed by a fake `<head>` in a comment (covered by unit tests).
   Applied by wrapping each `section.load()` (works for EPUB, MOBI, FB2, CBZ/CBR alike). SVG top-level sections are left as they are
   (a CSP meta is not honoured in SVG); they are protected by the sandbox only.
4. **Links**: click handling stays in foliate (`external-link` -> `window.open`, not triggered because reader-core does not forward it; internal links navigate inside the reader).
5. **Server CSP** (`lib/Listener/CspListener.php`): only on requests under `/apps/ebookreader`, `/apps/files`, `/apps/viewer`, `/f/` it adds `blob:` to
   frame-src, worker-src, img/media/font/style-src, `data:` to font-src, `blob:` to connect-src (reader-core `fetch`es the section blob to inject the CSP) and
   `'wasm-unsafe-eval'` (script-src) for libarchive.js. Blob documents inherit the page CSP, hence style/font/img blob: entries are required.
   No `unsafe-eval`, no remote domains.

## Known trade-offs

- **Safari/WebKit**: without `allow-scripts` events registered by the parent on the iframe document may not fire (WebKit bug 218086), which affects
  tap/keyboard forwarding and touch swipes. reader-core option `sandboxScripts: true` restores upstream behaviour; the CSP meta then remains as the only script barrier
  (SVG sections unprotected). Off by default; untested on Safari here.
- `style-src 'unsafe-inline'` is needed for foliate's injected styles; CSS in books can not load remote resources (default-src none).
- The CBR worker runs from a `blob:` URL (`worker-src blob:`) and the wasm from the app's own `js/` assets.

## Viewer integration (decision)

`nextcloud/viewer` master is still Vue 2 (`Vue.component(handler.component.name, ...)`), so a Vue 3 SFC can not be a handler component.
`src/viewer.ts` registers `OCA.Viewer.registerHandler({ id: 'ebookreader', mimes, component })` (fallback: `window._oca_viewer_handlers`) with a
Vue-2-compatible plain options component that renders a same-origin `<iframe src="/apps/ebookreader/read/{fileid}?embedded=1">` (only while `active`).
The reader SPA hides its close button when `embedded=1`. Benefits: single reader code path, Vue-version independent, reader CSP/sandbox rules identical.
The fallback "open the full-screen route instead" is not needed. Nextcloud's default `X-Frame-Options: SAMEORIGIN` and frame-ancestors allow this.

## CBR / libarchive.js

`libarchive.js` (MIT) worker bundle is imported with Vite `?raw` and the wasm with `?url` (`src/components/reader/libarchive.ts`, lazy chunk).
reader-core starts the worker from a `Blob` after replacing `import.meta.url` with the absolute wasm asset URL. Needed CSP: `worker-src blob:`,
`script-src 'wasm-unsafe-eval'`, `connect-src 'self'` (wasm fetch), all handled in `CspListener`.
