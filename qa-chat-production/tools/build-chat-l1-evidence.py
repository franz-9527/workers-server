#!/usr/bin/env python3
"""Merge isolated automation evidence with real L1 device/channel evidence."""
import argparse
import importlib.util
import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]

def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

RELEASE = load("chat_release", ROOT / "tools" / "check-chat-release.py")
REAL = load("chat_l1_real", ROOT / "tools" / "check-chat-l1-real.py")

AUTOMATION_CHECKS = (
    "release_profiles",
    "go_tests",
    "go_race",
    "frontend_s11",
    "frontend_s12",
    "frontend_governance",
    "mysql57",
    "redis",
    "production_upgrade",
    "recovery_gate",
    "source_clean",
)

def valid_ref(value):
    return isinstance(value, str) and 5 <= len(value.strip()) <= 512

def read_json(path):
    target = pathlib.Path(path)
    if target.stat().st_size > 1048576:
        raise ValueError("evidence too large")
    value = json.loads(target.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise ValueError("evidence object required")
    return value

def validate_automation(data, revision):
    missing = []
    if data.get("schema") != 1:
        missing.append("schema")
    if data.get("profile") != "text-automation":
        missing.append("profile")
    if data.get("environment") != "isolated":
        missing.append("isolated_environment")
    if data.get("revision") != revision or not re.fullmatch(r"[0-9a-f]{40}", revision or ""):
        missing.append("exact_revision")
    if data.get("go_version") != "go1.27.3":
        missing.append("go_version")
    if data.get("database_version") != RELEASE.expected_database_version():
        missing.append("database_version")
    if data.get("source_verified") is not True:
        missing.append("source_verified")
    if not valid_ref(data.get("run_reference")):
        missing.append("run_reference")

    checks = data.get("checks") if isinstance(data.get("checks"), dict) else {}
    for name in AUTOMATION_CHECKS:
        if checks.get(name) is not True:
            missing.append(name)

    cases = data.get("cases")
    if (
        not isinstance(cases, list)
        or len(cases) != len(set(cases))
        or set(cases) != set(RELEASE.TEXT_CASES)
    ):
        missing.append("text_case_coverage")
    return missing

def compact(*values):
    text = "; ".join(str(value).strip() for value in values if isinstance(value, str) and value.strip())
    return text[:512]

def build(automation, real, revision, authorization_reference=""):
    auto_missing = validate_automation(automation, revision)
    real_missing = REAL.validate(real, revision)
    if auto_missing:
        raise ValueError("automation:" + ",".join(auto_missing))
    if real_missing:
        raise ValueError("real:" + ",".join(real_missing))

    runtime = real["runtime"]
    checks = real["checks"]
    auto_ref = automation["run_reference"]

    evidence = RELEASE.template("text")
    evidence.update(
        revision=revision,
        environment="test",
        recovery_epoch=runtime["recovery_epoch"],
        go_version=automation["go_version"],
        database_version=automation["database_version"],
        release_authorized=bool(authorization_reference),
        authorization_reference=authorization_reference.strip(),
    )

    for case in RELEASE.TEXT_CASES:
        evidence["cases"][case].update(
            status="passed",
            revision=revision,
            evidence=auto_ref,
        )

    evidence["automation"].update(
        status="passed",
        revision=revision,
        evidence=auto_ref,
        race=True,
        mysql57=True,
        php_version="8.3.36",
        redis=True,
        source_verified=True,
    )

    evidence["devices"].update(
        status="passed",
        revision=revision,
        evidence=compact(*(row.get("evidence", "") for row in real["devices"]), real["ui"].get("evidence", "")),
        physical_devices=[
            {
                "id": row["id"],
                "platform": row["platform"],
                "physical": True,
                "passed": True,
            }
            for row in real["devices"]
        ],
        weak_network=True,
        large_text=True,
        dark_mode=True,
        safe_area=True,
    )

    evidence["deployment"].update(
        status="passed",
        revision=revision,
        evidence=compact(auto_ref, runtime.get("evidence", ""), checks["wss_reconnect"].get("evidence", "")),
        actual_sha=revision,
        source_dirty=False,
        nonroot_processes=["serve", "worker"],
        private_internal_port=True,
        php_routes_preserved=True,
        rollback_passed=True,
        authenticated_wss=True,
    )

    evidence["recovery"].update(
        status="passed",
        revision=revision,
        evidence=compact(
            auto_ref,
            checks["authorization_revocation"].get("evidence", ""),
            checks["recovery_isolation"].get("evidence", ""),
        ),
        independent_increment_complete=True,
        old_permissions_denied=True,
        recall_not_revived=True,
        notification_not_resent=True,
        wallet_not_recharged=True,
        gap_blocks_access=True,
    )

    evidence["text_channels"].update(
        status="passed",
        revision=revision,
        evidence=compact(
            checks["dual_device_text"].get("evidence", ""),
            checks["wechat_text_safety"].get("evidence", ""),
        ),
        simulated=False,
        platform_safety=True,
        wechat_text_safety=True,
    )

    failures = RELEASE.validate(evidence, revision, "text")
    expected = [] if authorization_reference else ["human_release_authorization"]
    if failures != expected:
        raise ValueError("merged:" + ",".join(failures))
    return evidence

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--automation", required=True)
    parser.add_argument("--real", required=True)
    parser.add_argument("--revision", required=True)
    parser.add_argument("--authorization-reference", default="")
    parser.add_argument("--output")
    args = parser.parse_args()

    evidence = build(
        read_json(args.automation),
        read_json(args.real),
        args.revision,
        args.authorization_reference,
    )
    payload = json.dumps(evidence, ensure_ascii=False, indent=2) + "\n"
    if args.output:
        pathlib.Path(args.output).write_text(payload, encoding="utf-8")
        print(json.dumps({
            "status": "l1_evidence_complete" if args.authorization_reference else "ready_for_human_authorization",
            "revision": args.revision,
            "output": args.output,
            "formal_release_ready": False,
        }, ensure_ascii=False))
    else:
        sys.stdout.write(payload)
    return 0

if __name__ == "__main__":
    try:
        sys.exit(main())
    except (OSError, ValueError, TypeError, KeyError, json.JSONDecodeError) as exc:
        print(json.dumps({
            "status": "blocked",
            "code": "CHAT_L1_EVIDENCE_MERGE_INVALID",
            "reason": str(exc)[:512],
            "formal_release_ready": False,
        }, ensure_ascii=False))
        sys.exit(2)
