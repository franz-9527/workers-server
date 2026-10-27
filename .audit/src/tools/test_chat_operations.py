#!/usr/bin/env python3
import importlib.util,json,pathlib,tempfile,unittest,sys
ROOT=pathlib.Path(__file__).resolve().parents[1]
def module(name,path):
    spec=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
release=module("chat_release",ROOT/"tools/check-chat-release.py")
center=module("chat_center",ROOT/"chat/scripts/test-center.py")
archive=module("chat_archive",ROOT/"chat/scripts/seal-binlogs.py")
class ChatOperationsContract(unittest.TestCase):
    def complete(self):
        sha="a"*40;e=release.template();e.update(revision=sha,environment="production",recovery_epoch="b"*64,release_authorized=True,authorization_reference="explicit human release task")
        for case in e["cases"].values():case.update(status="passed",revision=sha,evidence="private-reviewed-evidence")
        for area in release.AREAS:e[area].update(status="passed",revision=sha,evidence="private-reviewed-evidence")
        e["automation"].update(race=True,mysql57=True,php_version="8.3.36",redis=True,source_verified=True)
        e["devices"].update(physical_devices=[{"id":"device-a","platform":"ios","physical":True,"passed":True},{"id":"device-b","platform":"android","physical":True,"passed":True}],weak_network=True,large_text=True,dark_mode=True,album_permissions=True,safe_area=True)
        e["capacity"].update(environment="target_host",connections=[30,100,300],history_sizes=[1000,10000,100000],mixed_order_media_notifications=True,baseline_order_p95_ms=100,mixed_order_p95_ms=105,headroom_percent=35)
        e["deployment"].update(actual_sha=sha,source_dirty=False,nonroot_processes=["serve","worker"],private_internal_port=True,php_routes_preserved=True,rollback_passed=True,authenticated_wss=True)
        e["channels"].update(simulated=False,private_media_expiry=True,platform_safety=True,subscription_receipts=True,field_images_reviewed=True,manual_wait_reviewed=True)
        e["recovery"].update(independent_increment_complete=True,old_permissions_denied=True,recall_not_revived=True,notification_not_resent=True,wallet_not_recharged=True,gap_blocks_access=True)
        return e,sha
    def test_pending_template_cannot_release(self):
        self.assertIn("human_release_authorization",release.validate(release.template(),"a"*40))
    def test_complete_schema_and_source_binding(self):
        e,sha=self.complete();self.assertEqual([],release.validate(e,sha))
        self.assertIn("exact_revision",release.validate(e,"b"*40))
        e["cases"]["C04"]["revision"]="b"*40
        self.assertIn("C04",release.validate(e,sha))
    def test_simulations_cannot_satisfy_devices_channels_or_host(self):
        e,sha=self.complete()
        e["devices"]["physical_devices"][0]["physical"]=False
        e["capacity"]["environment"]="isolated_runner";e["channels"]["simulated"]=True
        failed=release.validate(e,sha)
        for key in ("two_real_devices","target_mixed_capacity","actual_channels"):self.assertIn(key,failed)
    def test_load_budget_and_recovery_gaps_block(self):
        e,sha=self.complete();e["capacity"]["mixed_order_p95_ms"]=120;e["recovery"]["independent_increment_complete"]=False
        failed=release.validate(e,sha)
        self.assertIn("order_budget_and_headroom",failed);self.assertIn("safe_incremental_restore",failed)
        e,sha=self.complete();e["capacity"]["baseline_order_p95_ms"]=float("inf")
        self.assertIn("order_budget_and_headroom",release.validate(e,sha))
    def test_fixture_never_accepts_credentials_or_urls(self):
        f={"schema":1,"instance":"instance-a","suite":"media","environment":"test","provider":"simulated","order":{"workflow":"measurement","source":"consumer","dispatch":"single_grab"},"actors":["worker","customer","platform_staff"],"image_counts":[1,2,4,9]}
        self.assertEqual(f,center.validate(f))
        for key,value in (("provider","real"),("environment","production"),("suite","../outside")):
            bad=dict(f);bad[key]=value
            with self.assertRaises(ValueError):center.validate(bad)
        f["access_token"]="injected"
        with self.assertRaises(ValueError):center.validate(f)
    def test_archive_gap_and_incomplete_tail_rejected_before_execution(self):
        with tempfile.TemporaryDirectory() as d:
            start={"server_uuid":"a"*8+"-"+"b"*4+"-"+"c"*4+"-"+"d"*4+"-"+"e"*12,"file":"mysql-bin.000001","position":"4"}
            end=dict(start,file="mysql-bin.000002",position="200")
            with self.assertRaises(ValueError):archive.verify_range(start,end,d,sys.executable)
            (pathlib.Path(d)/"mysql-bin.000001").write_bytes(b"\\xfebin")
            with self.assertRaises(ValueError):archive.verify_range(start,start,d,sys.executable)
if __name__=="__main__":unittest.main()
