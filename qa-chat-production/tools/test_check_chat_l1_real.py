#!/usr/bin/env python3
import importlib.util
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("chat_l1_real", ROOT / "tools" / "check-chat-l1-real.py")
CHECK = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CHECK)
REVISION = "a" * 40

def ready():
    data = CHECK.template(REVISION)
    for row in data["devices"]:
        row.update(id=row["platform"] + "-real-1", passed=True, evidence="physical-device-run")
    data["ui"].update(
        weak_network=True, dark_mode=True, large_text=True, safe_area=True,
        evidence="device-ui-run",
    )
    for name, row in data["checks"].items():
        row.update(status="passed", evidence="real-" + name)
    return data

class L1RealEvidenceTests(unittest.TestCase):
    def test_ready_record_passes(self):
        self.assertEqual([], CHECK.validate(ready(), REVISION))

    def test_pending_check_blocks(self):
        data = ready()
        data["checks"]["wss_reconnect"]["status"] = "pending"
        self.assertIn("wss_reconnect", CHECK.validate(data, REVISION))

    def test_fake_device_blocks(self):
        data = ready()
        data["devices"][0]["physical"] = False
        self.assertIn("two_real_devices", CHECK.validate(data, REVISION))

    def test_test_environment_is_mandatory(self):
        data = ready()
        data["environment"] = "production"
        self.assertIn("test_environment_only", CHECK.validate(data, REVISION))

    def test_revision_mismatch_blocks(self):
        data = ready()
        self.assertIn("exact_revision", CHECK.validate(data, "b" * 40))

    def test_short_evidence_blocks(self):
        data = ready()
        data["checks"]["history_backfill"]["evidence"] = "x"
        self.assertIn("history_backfill", CHECK.validate(data, REVISION))

if __name__ == "__main__":
    unittest.main()
