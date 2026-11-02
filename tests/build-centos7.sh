#!/bin/bash
set -euo pipefail
PY=/opt/python/cp313-cp313/bin/python
"$PY" --version
getconf GNU_LIBC_VERSION
yum install -y libjpeg-turbo-devel zlib-devel devtoolset-11-gcc devtoolset-11-gcc-c++
export CC=/opt/rh/devtoolset-11/root/usr/bin/gcc
export CXX=/opt/rh/devtoolset-11/root/usr/bin/g++
"$PY" -m pip install 'setuptools==80.9.0' 'wheel==0.45.1' 'packaging==25.0' 'scikit-build-core==0.12.0' 'nanobind==2.11.0' 'pybind11==3.0.2' 'cmake==3.31.6' 'ninja==1.11.1.4'
curl --fail --location --proto '=https' --tlsv1.2 https://storage.googleapis.com/downloads.webmproject.org/releases/webp/libwebp-1.6.0.tar.gz -o /tmp/libwebp-1.6.0.tar.gz
echo 'e4ab7009bf0629fd11982d4c2aa83964cf244cffba7347ecd39019a9e38c4564  /tmp/libwebp-1.6.0.tar.gz' | sha256sum -c -
tar -xzf /tmp/libwebp-1.6.0.tar.gz -C /tmp
(cd /tmp/libwebp-1.6.0 && ./configure --prefix=/tmp/chat-native --enable-libwebpmux --enable-libwebpdemux --disable-static --disable-gl --disable-sdl && make -j2 && make install)
export CFLAGS="-I/tmp/chat-native/include"
export LDFLAGS="-L/tmp/chat-native/lib -static-libstdc++ -static-libgcc"
export LD_LIBRARY_PATH="/tmp/chat-native/lib:${LD_LIBRARY_PATH:-}"
export PKG_CONFIG_PATH="/tmp/chat-native/lib/pkgconfig:${PKG_CONFIG_PATH:-}"
mkdir -p /tmp/chat-sdists /tmp/chat-built wheelhouse
"$PY" -m pip download --no-deps --no-build-isolation --no-binary=:all: --require-hashes -r chat/helpers/images/requirements-py313-sources.lock -d /tmp/chat-sdists
for src in /tmp/chat-sdists/*.tar.gz; do
  CMAKE_BUILD_PARALLEL_LEVEL=2 MAX_CONCURRENCY=2 "$PY" -m pip wheel --no-deps --no-build-isolation "$src" -w /tmp/chat-built
done
for whl in /tmp/chat-built/*.whl; do
  auditwheel repair --plat manylinux2014_x86_64 "$whl" -w wheelhouse
done
"$PY" - <<'PY'
import hashlib, pathlib
root = pathlib.Path("wheelhouse")
lines = ["# Built and auditwheel-checked for CPython 3.13 / glibc 2.17 / x86_64."]
for prefix, requirement in (("pillow-", "Pillow==13.0.0"), ("zxing_cpp-", "zxing-cpp==3.1.1")):
    files = list(root.glob(prefix + "*.whl"))
    assert len(files) == 1, files
    lines.append(requirement + " --hash=sha256:" + hashlib.sha256(files[0].read_bytes()).hexdigest())
(root / "requirements.lock").write_text("\n".join(lines) + "\n")
PY
mkdir -p wheelhouse/licenses
cp /tmp/libwebp-1.6.0/COPYING wheelhouse/licenses/libwebp-COPYING
find /usr/share/doc -maxdepth 2 -path '/usr/share/doc/libjpeg-turbo*/*' -type f -iname '*license*' -exec cp {} wheelhouse/licenses/ \;
"$PY" -m venv /tmp/chat-verify
/tmp/chat-verify/bin/python -m pip install --no-index --find-links=wheelhouse --require-hashes -r wheelhouse/requirements.lock
env -u LD_LIBRARY_PATH /tmp/chat-verify/bin/python tests/python313_centos7.py
