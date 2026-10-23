#!/usr/bin/env python3
"""Check staged repository contents after isolated validation has been cleaned up."""
from pathlib import Path
import subprocess
import sys

root = Path(__file__).resolve().parents[1]
expected = {
    "README.md",
    ".gitignore",
    "tools/check-clean-state.py",
    ".github/workflows/actions-housekeeping.yml",
}
result = subprocess.run(
    ["git", "ls-files", "--stage", "-z"], cwd=root, check=True, capture_output=True
)
actual = set()
invalid = []
for entry in result.stdout.split(b"\0"):
    if not entry:
        continue
    metadata, raw_path = entry.split(b"\t", 1)
    mode, _, stage = metadata.split()
    path = raw_path.decode("utf-8", errors="surrogateescape")
    actual.add(path)
    if mode not in (b"100644", b"100755") or stage != b"0":
        invalid.append(path)

unexpected = sorted(actual - expected)
missing = sorted(expected - actual)
if unexpected or missing or invalid:
    for label, paths in (("Unexpected tracked file", unexpected),
                         ("Missing infrastructure file", missing),
                         ("Invalid mode or unresolved merge", sorted(set(invalid)))):
        for path in paths:
            print(f"{label}: {path!r}", file=sys.stderr)
    print("Stage cleanup changes with git add -A before checking.", file=sys.stderr)
    sys.exit(1)

print(f"Clean-state check passed: {len(expected)} infrastructure files in the Git index.")
