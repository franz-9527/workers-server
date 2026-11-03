#!/bin/sh
set -eu
cd "$(dirname "$0")"
export GOTOOLCHAIN=local
export CGO_ENABLED=0
required="go$(awk '$1 == "go" {print $2}' go.mod)"
if [ "$(go env GOVERSION)" != "$required" ]; then
    printf '%s\n' "请使用go.mod锁定的工具链: $required" >&2
    exit 2
fi
revision=$(git rev-parse --verify HEAD)
dirty=false
if [ -n "$(git status --porcelain)" ]; then dirty=true; fi
mkdir -p bin
go mod verify
go build -mod=readonly -trimpath -buildvcs=false \
    -ldflags="-buildid= -X github.com/franz-9527/workers/chat/internal/bootstrap.sourceRevision=$revision -X github.com/franz-9527/workers/chat/internal/bootstrap.sourceDirty=$dirty" \
    -o bin/chatd ./cmd/chatd
