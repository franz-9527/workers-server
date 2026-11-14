#!/usr/bin/env python3
import importlib.util
import pathlib
import unittest
from unittest.mock import patch

ROOT = pathlib.Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("check_chat_release", ROOT / "tools" / "check-chat-release.py")
CHECK = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CHECK)

REVISION = "a" * 40
EPOCH = "b" * 64

def ready_evidence(profile):
    with patch.object(CHECK, "expected_database_version", return_value="2.7.6"):
        evidence = CHECK.template(profile)
    evidence.update({
        "revision": REVISION,
        "environment": "test",
        "recovery_epoch": EPOCH,
        "go_version": "go1.27.3",
        "database_version": "2.7.6",
        "release_authorized": True,
        "authorization_reference": "workers-server/actions/runs/123456",
    })
    for row in evidence["cases"].values():
        row.update(status="passed", revision=REVISION, evidence="isolated-run-123456")
    for area in CHECK.profile_areas(profile):
        evidence[area].update(status="passed", revision=REVISION, evidence="isolated-run-123456")
    evidence["automation"] = {
        "race": True, "mysql57": True, "php_version": "8.3.36",
        "redis": True, "source_verified": True,
    }
    evidence["devices"] = {
        "physical_devices": [
            {"id": "ios-1", "platform": "ios", "physical": True, "passed": True},
            {"id": "android-1", "platform": "android", "physical": True, "passed": True},
        ],
        "weak_network": True, "large_text": True, "dark_mode": True,
        "safe_area": True, "album_permissions": True,
    }
    evidence["deployment"] = {
        "actual_sha": REVISION, "source_dirty": False,
        "nonroot_processes": ["serve", "worker"], "private_internal_port": True,
        "php_routes_preserved": True, "rollback_passed": True,
        "authenticated_wss": True,
    }
    evidence["recovery"] = {
        "independent_increment_complete": True, "old_permissions_denied": True,
        "recall_not_revived": True, "notification_not_resent": True,
        "wallet_not_recharged": True, "gap_blocks_access": True,
    }
    if profile in ("text", "text-images"):
        evidence["text_channels"] = {
            "status": "passed", "revision": REVISION, "evidence": "wechat-text-safety-run",
            "simulated": False, "platform_safety": True, "wechat_text_safety": True,
        }
    if profile == "text-images":
        evidence["channels"].update({
            "simulated": False, "private_media_expiry": True, "platform_safety": True,
            "field_images_reviewed": True, "manual_wait_reviewed": True,
        })
        evidence["image_acceptance"].update({
            "status": "passed", "revision": REVISION, "evidence": "private-image-run",
            "private_storage": True, "upload_download": True, "pre_publish_review": True,
            "recall_and_expiry": True, "simulated": False,
        })
    if profile == "full":
        evidence["capacity"] = {
            "environment": "target_host", "connections": [30, 100, 300],
            "history_sizes": [1000, 10000, 100000], "mixed_order_media_notifications": True,
            "baseline_order_p95_ms": 100, "mixed_order_p95_ms": 105, "headroom_percent": 40,
        }
        evidence["channels"] = {
            "status": "passed", "revision": REVISION, "evidence": "full-channel-run",
            "simulated": False, "private_media_expiry": True, "platform_safety": True,
            "subscription_receipts": True, "field_images_reviewed": True, "manual_wait_reviewed": True,
        }
    return evidence

class ReleaseProfileTests(unittest.TestCase):
    def validate(self, evidence, profile):
        with patch.object(CHECK, "expected_database_version", return_value="2.7.6"):
            return CHECK.validate(evidence, REVISION, profile)

    def test_text_profile_passes_without_image_or_capacity_evidence(self):
        evidence = ready_evidence("text")
        self.assertEqual([], self.validate(evidence, "text"))
        self.assertIn("C22", evidence["cases"])
        self.assertIn("C44", evidence["cases"])
        self.assertIn("C15", evidence["cases"])
        self.assertNotIn("C08", evidence["cases"])
        self.assertNotIn("C47", evidence["cases"])
        self.assertNotIn("capacity", evidence)

    def test_text_profile_rejects_capability_expansion(self):
        evidence = ready_evidence("text")
        evidence["capabilities"] = ["text", "governance", "images"]
        self.assertIn("profile_capabilities", self.validate(evidence, "text"))

    def test_text_images_requires_real_image_evidence(self):
        evidence = ready_evidence("text-images")
        self.assertEqual([], self.validate(evidence, "text-images"))
        evidence["image_acceptance"]["pre_publish_review"] = False
        self.assertIn("image_acceptance", self.validate(evidence, "text-images"))

    def test_full_profile_keeps_capacity_and_all_base_cases(self):
        evidence = ready_evidence("full")
        self.assertEqual([], self.validate(evidence, "full"))
        evidence["capacity"]["connections"] = [30, 100]
        self.assertIn("target_mixed_capacity", self.validate(evidence, "full"))

    def test_full_profile_requires_its_base_capabilities(self):
        evidence = ready_evidence("full")
        evidence["capabilities"] = ["text", "governance"]
        self.assertIn("capabilities", self.validate(evidence, "full"))

    def test_full_profile_optional_audio_requires_av_evidence(self):
        evidence = ready_evidence("full")
        evidence["capabilities"].append("audio")
        self.assertIn("AV05", self.validate(evidence, "full"))

    def test_profile_mismatch_fails_closed(self):
        evidence = ready_evidence("text")
        self.assertIn("profile_mismatch", self.validate(evidence, "text-images"))

    def test_revision_mismatch_blocks(self):
        evidence = ready_evidence("text")
        evidence["deployment"]["actual_sha"] = "c" * 40
        self.assertIn("actual_deployment_and_rollback", self.validate(evidence, "text"))

    def test_fake_or_missing_physical_device_blocks(self):
        evidence = ready_evidence("text")
        evidence["devices"]["physical_devices"][0]["physical"] = False
        self.assertIn("two_real_devices", self.validate(evidence, "text"))

    def test_malformed_nested_evidence_fails_closed(self):
        evidence = ready_evidence("text")
        evidence["cases"] = "not-an-object"
        evidence["devices"]["physical_devices"] = None
        failures = self.validate(evidence, "text")
        self.assertIn("C01", failures)
        self.assertIn("two_real_devices", failures)

if __name__ == "__main__":
    unittest.main()
