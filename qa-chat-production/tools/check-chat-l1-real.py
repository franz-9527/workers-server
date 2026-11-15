#!/usr/bin/env python3
"""Validate real-device and real-channel evidence for L1 text chat."""
import argparse
import json
import pathlib
import re
import sys

CHECKS = (
    "dual_device_text",
    "wss_reconnect",
    "history_backfill",
    "wechat_text_safety",
    "authorization_revocation",
    "recovery_isolation",
)

def valid_revision(value):
    return isinstance(value, str) and re.fullmatch(r"[0-9a-f]{40}", value) is not None

def valid_epoch(value):
    return isinstance(value, str) and re.fullmatch(r"[0-9a-f]{64}", value) is not None

def evidence_text(row):
    value = row.get("evidence") if isinstance(row, dict) else None
    return isinstance(value, str) and 5 <= len(value.strip()) <= 512

def template(revision):
    if not valid_revision(revision):
        raise ValueError("revision")
    return {
        "schema": 1,
        "profile": "text-real",
        "revision": revision,
        "environment": "test",
        "runtime": {
            "running_sha": "",
            "source_dirty": False,
            "recovery_epoch": "",
            "evidence": "",
        },
        "devices": [
            {"id": "", "platform": "ios", "physical": True, "passed": False, "evidence": ""},
            {"id": "", "platform": "android", "physical": True, "passed": False, "evidence": ""},
        ],
        "ui": {
            "weak_network": False,
            "dark_mode": False,
            "large_text": False,
            "safe_area": False,
            "evidence": "",
        },
        "checks": {
            name: {"status": "pending", "evidence": "", "notes": ""}
            for name in CHECKS
        },
    }

def validate(data, revision):
    missing = []
    if not isinstance(data, dict):
        return ["evidence_object"]
    if data.get("schema") != 1:
        missing.append("schema")
    if data.get("profile") != "text-real":
        missing.append("profile")
    if data.get("environment") != "test":
        missing.append("test_environment_only")
    if not valid_revision(revision) or data.get("revision") != revision:
        missing.append("exact_revision")

    runtime = data.get("runtime") if isinstance(data.get("runtime"), dict) else {}
    if (
        runtime.get("running_sha") != revision
        or runtime.get("source_dirty") is not False
        or not valid_epoch(runtime.get("recovery_epoch"))
        or not evidence_text(runtime)
    ):
        missing.append("runtime_binding")

    devices = data.get("devices")
    if not isinstance(devices, list):
        devices = []
    good_devices = [
        row for row in devices
        if isinstance(row, dict)
        and isinstance(row.get("id"), str) and row.get("id").strip()
        and row.get("platform") in ("ios", "android")
        and row.get("physical") is True
        and row.get("passed") is True
        and evidence_text(row)
    ]
    if (
        len(good_devices) < 2
        or len({row["id"] for row in good_devices}) < 2
        or {"ios", "android"} - {row["platform"] for row in good_devices}
    ):
        missing.append("two_real_devices")

    ui = data.get("ui") if isinstance(data.get("ui"), dict) else {}
    if (
        not all(ui.get(name) is True for name in ("weak_network", "dark_mode", "large_text", "safe_area"))
        or not evidence_text(ui)
    ):
        missing.append("real_device_ui")

    checks = data.get("checks") if isinstance(data.get("checks"), dict) else {}
    for name in CHECKS:
        row = checks.get(name) if isinstance(checks.get(name), dict) else {}
        if row.get("status") != "passed" or not evidence_text(row):
            missing.append(name)

    return missing

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--template", action="store_true")
    parser.add_argument("--evidence")
    parser.add_argument("--revision", required=True)
    args = parser.parse_args()
    if args.template:
        print(json.dumps(template(args.revision), ensure_ascii=False, indent=2))
        return 0
    if not args.evidence:
        parser.error("--evidence is required unless --template is used")
    path = pathlib.Path(args.evidence)
    if path.stat().st_size > 262144:
        raise ValueError("evidence too large")
    data = json.loads(path.read_text(encoding="utf-8"))
    missing = validate(data, args.revision)
    print(json.dumps({
        "status": "blocked" if missing else "ready_for_l1_evidence_merge",
        "profile": "text-real",
        "revision": args.revision,
        "missing": missing,
        "formal_release_ready": False,
    }, ensure_ascii=False))
    return 1 if missing else 0

if __name__ == "__main__":
    try:
        sys.exit(main())
    except (OSError, ValueError, TypeError, json.JSONDecodeError):
        print('{"status":"blocked","code":"CHAT_L1_REAL_EVIDENCE_INVALID","formal_release_ready":false}')
        sys.exit(2)
