#!/usr/bin/env python3
import importlib.util
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

BUILD = load("chat_l1_builder", ROOT / "tools" / "build-chat-l1-evidence.py")
REVISION = "a" * 40
EPOCH = "b" * 64

def automation():
    return {
        "schema": 1,
        "profile": "text-automation",
        "revision": REVISION,
        "environment": "isolated",
        "go_version": "go1.27.3",
        "database_version": BUILD.RELEASE.expected_database_version(),
        "source_verified": True,
        "run_reference": "workers-server/actions/runs/123456",
        "checks": {name: True for name in BUILD.AUTOMATION_CHECKS},
        "cases": list(BUILD.RELEASE.TEXT_CASES),
    }

def real():
    value = BUILD.REAL.template(REVISION)
    value["runtime"].update(
        running_sha=REVISION,
        source_dirty=False,
        recovery_epoch=EPOCH,
        evidence="test-site-runtime-status",
    )
    for row in value["devices"]:
        row.update(id=row["platform"] + "-real-1", passed=True, evidence="physical-device-run")
    value["ui"].update(
        weak_network=True, dark_mode=True, large_text=True, safe_area=True,
        evidence="real-device-ui",
    )
    for name, row in value["checks"].items():
        row.update(status="passed", evidence="real-" + name)
    return value

class L1EvidenceBuilderTests(unittest.TestCase):
    def test_merge_without_human_authorization_stays_blocked_only_on_authorization(self):
        result = BUILD.build(automation(), real(), REVISION)
        self.assertFalse(result["release_authorized"])
        self.assertEqual(
            ["human_release_authorization"],
            BUILD.RELEASE.validate(result, REVISION, "text"),
        )

    def test_explicit_authorization_produces_checker_ready_evidence(self):
        result = BUILD.build(automation(), real(), REVISION, "manual-review-2026-11-15")
        self.assertTrue(result["release_authorized"])
        self.assertEqual([], BUILD.RELEASE.validate(result, REVISION, "text"))

    def test_automation_revision_mismatch_blocks(self):
        value = automation()
        value["revision"] = "c" * 40
        with self.assertRaises(ValueError):
            BUILD.build(value, real(), REVISION)

    def test_missing_automation_coverage_blocks(self):
        value = automation()
        value["cases"].pop()
        with self.assertRaises(ValueError):
            BUILD.build(value, real(), REVISION)

    def test_missing_race_run_blocks(self):
        value = automation()
        value["checks"]["go_race"] = False
        with self.assertRaises(ValueError):
            BUILD.build(value, real(), REVISION)

    def test_real_runtime_sha_mismatch_blocks(self):
        value = real()
        value["runtime"]["running_sha"] = "d" * 40
        with self.assertRaises(ValueError):
            BUILD.build(automation(), value, REVISION)

    def test_real_wechat_safety_must_pass(self):
        value = real()
        value["checks"]["wechat_text_safety"]["status"] = "pending"
        with self.assertRaises(ValueError):
            BUILD.build(automation(), value, REVISION)

if __name__ == "__main__":
    unittest.main()
