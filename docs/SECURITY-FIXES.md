# Security fixes from the audit of 2026-10-01

Four independent reviewers covered access control, server-side file processing, the browser side, and configuration/operations. **Result:** no cross-user access (IDOR) and no XXE. The `/item` XSS reported as "high" is not exploitable because of `EmptyContentSecurityPolicy` (`default-src 'none'`); it is hardened anyway.

**Status: all items implemented (2026-10-01).** Deviations: A5 serves pages over 60 MB as before (404 via readEntry). B2 has unrar/bsdtar listings without sizes; only the entry cap and the staging byte cap apply there. B7: EditorUtil::references() throws on oversized documents; EpubEditor then keeps all resources and adds a warning.

Each fix gets a regression test where it can be unit-tested. Workers must not touch `CHANGELOG.md`; the integration step writes it.

## S-A: backend access, HTTP, jobs
| # | Fix | Files |
|---|---|---|
| A1 | `/item`: allowlist instead of blocklist. Images (no SVG), fonts and audio/video keep their type, everything else is `text/plain`. CSP `default-src 'none'; sandbox` via ContentSecurityPolicy (no EmptyContentSecurityPolicy). `Content-Disposition: inline; filename="…"` only for images, `attachment` otherwise. | lib/Controller/ItemController.php |
| A2 | **Download-disabled shares:** new `LibraryService::canReadContent(File): bool`. It returns false if the storage `instanceOfStorage(ISharedStorage)` and either the share has `getAttributes()?->getAttribute('permissions', 'download') === false` or `canSeeContent() === false`. Use it in all endpoints that hand out content or bytes: Comic pages/list, Item, Editor structure/save/saveAsCopy, Convert, and the client-download path (book JSON field `downloadable: false`, so the reader shows "View-only share – reading not allowed"). Covers stay allowed (thumbnail). | lib/Service/LibraryService.php (new method only), lib/Controller/{Comic,Item,Editor,Convert}Controller.php, lib/Http/BookSerializer.php, lib/ResponseDefinitions.php |
| A3 | Cover upload only with `$file->isUpdateable()`, otherwise 403, regardless of whether a cover exists. | lib/Controller/CoverController.php |
| A4 | Rate limits: Editor structure 60/min, save/metadata 30/min, bulk-tags 5/min, rename 30/min. Comic page 600/min. Cover POST 10/min. Cap bulk-tags at 100 files. | Controllers |
| A5 | Comic page: pixel cap (w×h ≤ 40 MP) before `imagecreatefromstring`, otherwise serve unscaled (or 413 if larger than 60 MB). Drop `immutable`, use `max-age=86400`. Cache name includes the MIME extension (L11). | lib/Controller/ComicController.php |
| A6 | Before `move()` in Organize/Rename: `isUpdateable() && isDeletable()`, otherwise status `failed` or 403. | lib/Service/OrganizeService.php, lib/Service/EditorService.php (rename only) |
| A7 | Settings: reader prefs allowlist of keys (the keys ReaderSettings.vue / ViewSettings uses) with type checks; drop unknown keys. | lib/Service/SettingsService.php |
| A8 | Jobs: RescanJob only queues (inline budget 0) and at most N users per run with a cursor in IAppConfig. ScanFileJob checks `userExists`. | lib/BackgroundJob/*.php, LibraryService::scanUser call |
| A9 | UserDeletedListener: try/catch per step, also delete comic-page cache files for fileIds with no remaining active row. | lib/Listener/UserDeletedListener.php |
| A10 | RegisterMimeTypes: atomic write (temp + rename, LOCK_EX). | lib/Migration/RegisterMimeTypes.php |

## S-B: parsers, archives, conversion
| # | Fix | Files |
|---|---|---|
| B1 | `EditorUtil::loadXml`: no `LIBXML_PARSEHUGE`. Reject documents with a DOCTYPE internal subset or `<!ENTITY` after load (`$dom->doctype?->internalSubset`). Same check in XmlUtil instead of `stripos` (covers UTF-16). Test: billion-laughs file is rejected. | lib/Editor/EditorUtil.php, lib/Metadata/XmlUtil.php |
| B2 | Archive tools: parse sizes from the listing (7z `-slt` Size, unrar `lt`/bsdtar `-tv` or a skip). Cap total uncompressed size at 2 GB and entries at 5000 pages, otherwise refuse with an exception. Apply in ComicArchive (pages/convert) and ConvertService::stage, plus a staging cap on written bytes. Use only absolute PATH entries. Force the format type where the tool allows it (7z `-t7z`/`-trar` by magic bytes). | lib/Service/ArchiveTools.php, lib/Metadata/ComicArchive.php, lib/Service/ConvertService.php |
| B3 | FB2: cap the file at 50 MB; cap the cover binary at 20 MB before base64_decode. | lib/Metadata/Fb2Extractor.php |
| B4 | MOBI: length checks before every `unpack`, no warnings on truncated EXTH. | lib/Metadata/MobiExtractor.php |
| B5 | SafeZip: entry count ≤ 100000. | lib/Metadata/SafeZip.php |
| B6 | Check EbookCoverProvider and CoverService for pixel caps (40 MP) and add them where missing. | lib/Preview/EbookCoverProvider.php, lib/Service/CoverService.php |
| B7 | Regex scans in EpubExtractor (guide cover) and EditorUtil::references: limit input to 2 MB and check `preg_last_error()`. On a failure in references(), do not delete orphans (warning instead). | lib/Metadata/EpubExtractor.php, lib/Editor/EditorUtil.php |

## S-C: browser, CSP, CI
| # | Fix | Files |
|---|---|---|
| C1 | Book links: listen for foliate `external-link`, call `preventDefault()`, allow only http/https/mailto, `window.open(url, '_blank', 'noopener,noreferrer')` after a confirmation dialog ("Open external link? <host>"). Fix docs/SECURITY-READER.md section 4. | packages/reader-core/src/reader.ts (+ event to the UI), src/views/ReaderView.vue |
| C2 | `injectCsp`: if the root is not `html` in the XHTML/HTML namespace, run sanitizeSvg-style cleaning (scripts, foreignObject, on*, javascript:) for every XML section, or replace the section with an HTML error page. Unit tests: XHTML-typed section with `<svg>` root and script. | packages/reader-core/src/secure-sections.ts (+ test) |
| C3 | Extend SECTION_CSP with `script-src 'none'; base-uri 'none'; form-action 'none'; frame-src 'none'; object-src 'none'`. | secure-sections.ts |
| C4 | BookDetails description through the strict `sanitizeDescription` (src/editor/sanitize.ts) instead of USE_PROFILES html. | src/components/library/BookDetails.vue |
| C5 | CspListener: path match with a boundary (`$path === $p` or `str_starts_with($path, $p.'/')`); Files/Viewer only when the Viewer is loaded. Keep it simple: ebookreader routes + `/apps/files` + `/apps/files/` + `/f/`, with the boundary. | lib/Listener/CspListener.php |
| C6 | Workflows: pin all actions to commit SHAs (comment with the tag); `permissions: contents: read` in ci.yml; release job `environment: appstore`; key step `umask 077` + `trap 'rm -f …' EXIT`; pass version/prerelease via `env:` instead of `${{ }}` in `run:`. | .github/workflows/*.yml |
| C7 | Makefile appstore: allowlist (`appinfo css dist img js l10n lib resources templates LICENSE README.md CHANGELOG.md openapi.json`) instead of excludes. | Makefile |
| C8 | scripts/update-foliate.mjs: `--ref <40-hex-sha>` required. | scripts/update-foliate.mjs |
| C9 | Update vitest to the current major version (dev audit), only if tests stay green without much effort; otherwise document it. | package.json |
