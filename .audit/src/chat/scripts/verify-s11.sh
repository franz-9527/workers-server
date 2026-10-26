#!/usr/bin/env bash
# The existing strict isolated PHP/MySQL/Redis gates remain authoritative.
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
bash "$root/chat/scripts/verify-s10.sh"
cd "$root"
node --test tools/test_chat_s11.js
echo 'CHAT_S11_ACCEPTANCE_PASSED (only for the exact checked-out revision)'
