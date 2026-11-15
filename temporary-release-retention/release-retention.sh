#!/usr/bin/env bash
# Release retention helper for BaoTa chat deployments.
# Destructive cleanup is intentionally limited to confirmed-running test releases.
chat_prune_test_releases(){
  local site=${1:-} bin=${2:-} state=${3:-} confirmed_sha=${4:-}
  [[ "$site" == test ]] || return 0
  [[ "$confirmed_sha" =~ ^[a-f0-9]{40}$ ]] || return 0

  local release_root="$bin/releases" current='' deployed='' running='' sha='' dir=''
  local -A keep=()
  if [[ ! -d "$release_root" || -L "$release_root" ]]; then
    echo 'CHAT_DEPLOY_RELEASE_PRUNE_SKIPPED: invalid releases directory'
    return 0
  fi

  current=$(readlink "$bin/current" 2>/dev/null || true)
  if [[ "$current" =~ ^releases/([a-f0-9]{40})$ ]]; then keep["${BASH_REMATCH[1]}"]=1; fi
  deployed=$(cat "$bin/deployed-sha" 2>/dev/null || true)
  [[ "$deployed" =~ ^[a-f0-9]{40}$ ]] && keep["$deployed"]=1
  running=$(cat "$state/running-sha" 2>/dev/null || true)
  [[ "$running" =~ ^[a-f0-9]{40}$ ]] && keep["$running"]=1

  # Never clean before the newly deployed target is actually the running target.
  [[ "$running" == "$confirmed_sha" ]] || return 0

  while IFS=' ' read -r _ sha; do
    [[ "$sha" =~ ^[a-f0-9]{40}$ ]] && keep["$sha"]=1
  done < <(find "$release_root" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %f\n' 2>/dev/null | sort -nr | awk 'NR<=5{print}')

  for dir in "$release_root"/*; do
    [[ -d "$dir" && ! -L "$dir" ]] || continue
    sha=${dir##*/}
    [[ "$sha" =~ ^[a-f0-9]{40}$ ]] || continue
    [[ -n ${keep[$sha]+x} ]] && continue
    rm -rf -- "$dir"
    echo "CHAT_DEPLOY_RELEASE_PRUNED: $sha"
  done
}
