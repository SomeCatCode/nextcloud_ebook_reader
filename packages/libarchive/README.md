# libarchive worker (own build)

The browser unpacks CBR, CB7 and CBT with [libarchive.js](https://github.com/nika-begiashvili/libarchivejs) when the server has
no archive tool. The newest npm release (2.0.2, January 2024) embeds libarchive 3.7.2, which has known bugs in the RAR, 7z and tar
readers that were fixed later. This folder holds the same worker, rebuilt with current libraries:

| File | Content |
|---|---|
| `dist/libarchive.wasm` | libarchive 3.8.10, zlib 1.3.1, xz/liblzma 5.8.1, bzip2 1.0.8 (Emscripten port); **without OpenSSL** (only needed for AES encrypted ZIP) |
| `dist/worker-bundle.js` | the worker of libarchive.js 2.0.2 (`rollup`, production build) with the Emscripten glue of this build |
| `build/` | `Dockerfile` and `build.sh` used for the build |

The main thread API (`import { Archive } from 'libarchive.js'`) stays the npm package: its build from tag v2.0.2 is byte for byte
the same as the published file. `src/components/reader/libarchive.ts` and `src/editor/cbrToCbz.ts` import the worker and the wasm
from here.

Checked: all 14 test archives of libarchive.js (RAR v4/v5, 7z with LZMA/LZMA2/BZip2, tar with gz/bz2/xz, ZIP with
deflate/bzip2/lzma) unpack to the same bytes as with the npm build.

## Build

Requires Docker. The image pins Emscripten 3.1.51, the version libarchive.js 2.0.2 was built with (the worker code depends on the
generated glue).

```sh
git clone --depth 1 --branch v2.0.2 https://github.com/nika-begiashvili/libarchivejs.git
cd libarchivejs
cp <this repo>/packages/libarchive/build/Dockerfile lib/tools/docker/Dockerfile
cp <this repo>/packages/libarchive/build/build.sh lib/tools/build.sh
docker build -t ebr-libarchive-wasm lib/tools/docker
docker run --rm -v "$PWD":/var/local ebr-libarchive-wasm     # writes src/webworker/wasm-gen/libarchive.{js,wasm}
npm ci --ignore-scripts
npx rollup --config ./rollup.config.js --environment BUILD:production
cp dist/worker-bundle.js dist/libarchive.wasm <this repo>/packages/libarchive/dist/
```

To update libarchive, change the version in the `Dockerfile` (and check the release notes for new configure options).

## Licenses

libarchive.js: MIT. libarchive: BSD-2-Clause. zlib: zlib license. liblzma: 0BSD. bzip2: bzip2 license (BSD-style).
