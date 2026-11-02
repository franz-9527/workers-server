#!/bin/bash
set -euo pipefail
PY=/opt/python/cp313-cp313/bin/python
"$PY" --version
getconf GNU_LIBC_VERSION
yum install -y libjpeg-turbo-devel zlib-devel libwebp-devel
"$PY" -m pip install 'setuptools==80.9.0' 'wheel==0.45.1' 'packaging==25.0' 'scikit-build-core==0.11.6' 'nanobind==2.9.2' 'cmake==3.31.6' 'ninja==1.11.1.4'
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
"$PY" -m venv /tmp/chat-verify
/tmp/chat-verify/bin/python -m pip install --no-index --find-links=wheelhouse --require-hashes -r wheelhouse/requirements.lock
/tmp/chat-verify/bin/python tests/python313_centos7.py
