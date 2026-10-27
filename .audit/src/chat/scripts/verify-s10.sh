#!/usr/bin/env bash
# Same isolated PHP/MySQL/Redis/version gates and full S01-S09 regression suite;
# go test ./... additionally runs real S10 WSS/HTTP equivalence and lifecycle.
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
bash "$root/chat/scripts/verify-s09.sh"
echo 'CHAT_S10_ACCEPTANCE_PASSED (only for the exact checked-out revision)'
