#!/bin/sh
# Build a reviewable artifact; this script never uploads, switches current or restarts a process.
set -eu
cd "$(dirname "$0")/.."
if [ "$#" -ne 1 ] || [ ! -d "$1" ]; then
    printf '%s\n' '用法: package.sh 已存在的绝对产物目录' >&2
    exit 2
fi
case "$1" in /*) ;; *) exit 2 ;; esac
if [ -n "$(git status --porcelain)" ]; then
    printf '%s\n' '正式构建需要干净的完整源码提交' >&2
    exit 2
fi
sh ./build.sh
out="$1"
cp bin/chatd "$out/chatd"
./bin/chatd version > "$out/version.json"
python3 - "$out" <<'PY'
import hashlib,json,pathlib,sys
out=pathlib.Path(sys.argv[1])
version=json.loads((out/"version.json").read_bytes())
if version["source_dirty"]!="false" or version["source_revision"]=="unknown" or version["go_version"]!="go1.27.3":
    raise SystemExit("invalid release provenance")
manifest={"schema":1,"version":version,"php":"8.3.36","database":"2.7.0","files":{}}
for p in [pathlib.Path("bin/chatd"),pathlib.Path("helpers/images/inspect.py"),pathlib.Path("helpers/images/requirements.lock")]:
    manifest["files"][str(p)]={"bytes":p.stat().st_size,"sha256":hashlib.sha256(p.read_bytes()).hexdigest()}
(out/"manifest.json").write_text(json.dumps(manifest,indent=2)+"\n")
PY
