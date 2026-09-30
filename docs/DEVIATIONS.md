# Deviations from CONTRACTS.md

## Foundation (W0)

- `vite.config.ts`: `extractLicenseInformation` is disabled on Windows (`process.platform === 'win32'`). The
  `@nextcloud/vite-config` REUSE licence plugin loops forever there (dirname of a drive root never equals `/`),
  which hangs `vite build` at "rendering chunks". On Linux/CI it stays enabled (default).
- Vite output: `js/ebookreader-<entry>.mjs` (as contracted); chunks `js/*.chunk.mjs`; CSS goes to `css/` (added to .gitignore).
- Dependency versions adapted to what resolves: `@nextcloud/vue@^9.13`, `vite@^7.3` (peer of vite-config 2.5), `typescript@^5.9`,
  `vitest@^3`, `pinia@^3`, `vue-router@^4`, `eslint@^10` (required by `@nextcloud/eslint-config@9`), extra devDeps `@types/node`, `jsdom`, `@vue/test-utils`, `sass`.
- `@nextcloud/files` v4 exposes WebDAV constants from `@nextcloud/files/dav` (`defaultRemoteURL`, `defaultRootPath`); `davUrlForPath()` uses them.
- Extra format `fbz` (fb2.zip) registered as MIME `application/x-zip-compressed-fb2` in `Application::MIME_TYPES` (also in the preview regex). W1 owns RegisterMimeTypes and may change it.
- Extra public helpers beyond the contract: `Progress::toApi()`, `Progress::getLocatorArray()`, `Application::MIME_TYPES`,
  `SettingsService::defaultGenres()` (accepts genres.json as list of strings or objects with `name`/`en`), `GenreClassifier::classify()`,
  `RenameService::buildFilename()`, `ScannerService::scanUser()` (stub signatures owned by W1/W3, adjust freely).
- `SettingsService::get()` resolves a null `genreList` to the default list; `set()` merges partial settings and stores `null` if none given.
- `api.ts` additionally exports `fetchBookBlob`, `uploadCover`, `putProgressKeepalive`, `ApiError`. `ConflictError.current` = `data.current` of the 409 body (fallback: whole data).
- `PageController` calls `\OCP\Util::addScript('ebookreader', 'ebookreader-main')`; CSS is bundled by vite-config's ImportCSS plugin, so no addStyle.
- The stub `LoadViewerListener`/`CspListener`/`LoadFilesScriptsListener` have empty `handle()` bodies. `OCA\Viewer\Event\LoadViewer` and `OCA\Files\Event\LoadAdditionalScriptsEvent`
  are not in nextcloud/ocp (apps): Psalm suppresses UndefinedClass for them in psalm.xml.
