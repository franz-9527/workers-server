#!/usr/bin/env bash
# Package a clean, already-built Linux amd64 chatd from the same release checkout.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
out=$(realpath -m -- "${1:-release-dist}")
sha=$(git rev-parse HEAD)
stage=$(mktemp -d)
trap 'rm -rf -- "$stage"' EXIT
mkdir -p "$stage/payload" "$out"
install -m 0755 chat/bin/chatd "$stage/payload/chatd"
"$stage/payload/chatd" version > "$stage/payload/version.json"
python3 - "$stage/payload/version.json" "$sha" <<'PY'
import json, sys
with open(sys.argv[1]) as f:
    v = json.load(f)
if (v.get("source_revision"), v.get("source_dirty"), v.get("goos"), v.get("goarch")) != (sys.argv[2], "false", "linux", "amd64"):
    raise SystemExit("Go binary is not the clean Linux amd64 release candidate")
PY
git archive --format=tar HEAD:chat deploy/bt-run.sh deploy/bt-site.sh deploy/production-runtime.php deploy/update-production.sh deploy/init-production-recovery.php deploy/web-update.php deploy/nginx.conf.example chat.production.example.json helpers/preflight.py helpers/images/inspect.py helpers/media/inspect.py | tar -xf - -C "$stage"
# No signing private key, site configuration, runtime files or PHP overlay is packaged.
git show HEAD:server/VERSION > "$stage/PHP-VERSION"
printf '%s\n' "$sha" > "$stage/SOURCE-COMMIT.txt"
(
  cd "$stage"
  find . -type f ! -name SHA256SUMS.txt -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS.txt
  sha256sum --strict -c SHA256SUMS.txt
  archive="$out/workers-chat-go-linux-amd64-${sha:0:12}.zip"
  [[ ! -e "$archive" ]] || { echo 'Remove the previous Go archive before repackaging' >&2; exit 2; }
  zip -qr "$archive" .
)
