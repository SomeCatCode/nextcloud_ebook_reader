# libarchive.js 2.0.2 wasm with current libarchive (nextcloud ebookreader build, see packages/libarchive/README.md)
FROM emscripten/emsdk:3.1.51

WORKDIR /opt

ADD https://github.com/libarchive/libarchive/releases/download/v3.8.10/libarchive-3.8.10.tar.gz /opt
ADD https://github.com/madler/zlib/releases/download/v1.3.1/zlib-1.3.1.tar.gz /opt
ADD https://github.com/tukaani-project/xz/releases/download/v5.8.1/xz-5.8.1.tar.gz /opt
ADD https://sourceware.org/pub/bzip2/bzip2-1.0.8.tar.gz /opt

RUN tar xf libarchive-3.8.10.tar.gz && tar xf zlib-1.3.1.tar.gz && tar xf xz-5.8.1.tar.gz && tar xf bzip2-1.0.8.tar.gz && rm -f *.tar.gz

ENV CPPFLAGS="-I/usr/local/include/ -I/opt/zlib-1.3.1 -I/opt/bzip2-1.0.8"
ENV LDLIBS="-lz -lbz2"
ENV LDFLAGS="-L/usr/local/lib"

WORKDIR /opt/zlib-1.3.1
RUN emconfigure ./configure --static && emmake make && emmake make install

WORKDIR /opt/bzip2-1.0.8
RUN emmake make libbz2.a CC=emcc AR=emar RANLIB=emranlib && cp libbz2.a /usr/local/lib/ && cp bzlib.h /usr/local/include/

WORKDIR /opt/xz-5.8.1
RUN emconfigure ./configure --disable-assembler --enable-threads=no --enable-static=yes --disable-shared \
      --disable-xz --disable-xzdec --disable-lzmadec --disable-lzmainfo --disable-scripts --disable-doc && \
    emmake make && emmake make install

# no OpenSSL: only needed for encrypted ZIP (AES) and some digests, not for comics
ENV EMCC_CFLAGS="-s USE_BZIP2=1"
WORKDIR /opt/libarchive-3.8.10
RUN emconfigure ./configure --enable-static --disable-shared --disable-bsdtar --disable-bsdcat --disable-bsdcpio \
      --disable-bsdunzip --enable-posix-regex-lib=libc --disable-xattr --disable-acl --without-nettle --without-openssl \
      --without-mbedtls --without-lzo2 --without-cng --without-lz4 --without-zstd --without-xml2 --without-expat && \
    emmake make && emmake make install
ENV EMCC_CFLAGS=""

WORKDIR /var/local/lib/tools
CMD ["bash", "/var/local/lib/tools/build.sh"]
