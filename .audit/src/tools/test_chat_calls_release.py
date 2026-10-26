#!/usr/bin/env python3
"""Release-contract tests only; never seal or fabricate a deployment result."""
import pathlib,runpy,unittest
checker=runpy.run_path(str(pathlib.Path(__file__).with_name("check-chat-release.py")))
class CallsReleaseTests(unittest.TestCase):
 def evidence(self):
  e=checker["template"]();e["capabilities"]=["calls"];e["revision"]="a"*40
  return e
 def test_calls_is_distinct_capability(self):
  self.assertNotIn("capabilities",checker["validate"](self.evidence(),"a"*40))
 def test_absent_field_evidence_fails_closed(self):
  failures=checker["validate"](self.evidence(),"a"*40)
  self.assertIn("calls_evidence",failures);self.assertIn("calls_free_boundary_hard_stop",failures);self.assertIn("calls_cycle_rollover",failures);self.assertIn("calls_real_channels",failures)
 def test_unaccepted_calls_cannot_hide_behind_message_audio(self):
  e=self.evidence();e["capabilities"]=["audio","calls"]
  self.assertIn("calls_two_real_devices",checker["validate"](e,"a"*40))
 def test_simulated_channels_and_open_defaults_are_rejected(self):
  e=self.evidence();e["calls"]={"status":"passed","revision":"a"*40,"evidence":"unit-only","simulated":True,"default_closed":False}
  self.assertIn("calls_real_channels",checker["validate"](e,"a"*40))
 def test_real_publish_and_metering_fences_are_required(self):
  failures=checker["validate"](self.evidence(),"a"*40)
  self.assertIn("calls_publish_privilege_enforced",failures);self.assertIn("calls_metering_upper_bound_verified",failures)
 def test_other_source_revision_is_rejected(self):
  e=self.evidence();e["calls"]={"status":"passed","revision":"b"*40,"evidence":"unit-only"}
  self.assertIn("calls_evidence",checker["validate"](e,"a"*40))
if __name__=="__main__":unittest.main()
