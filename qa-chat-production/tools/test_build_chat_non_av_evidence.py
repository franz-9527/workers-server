#!/usr/bin/env python3
import importlib.util, pathlib, copy
ROOT=pathlib.Path(__file__).resolve().parents[1]
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
b=load("builder",ROOT/"tools"/"build-chat-non-av-evidence.py")
r=load("real",ROOT/"tools"/"check-chat-non-av-real.py")
release=b.RELEASE
R="a"*40;E="b"*64
auto={"schema":b.AUTOMATION_SCHEMA,"profile":"non-av-automation","revision":R,"environment":"isolated","go_version":"go1.27.3",
      "database_version":release.expected_database_version(),"source_verified":True,"run_reference":"https://example.invalid/run/1",
      "checks":{name:True for name in b.AUTOMATION_CHECKS},"cases":list(release.CASES)}
real=r.template(R)
real["runtime"].update(deployed_sha=R,running_sha=R,source_dirty=False,recovery_epoch=E,evidence="runtime evidence")
real["database"].update(mode="dedicated",shared_app_credentials=False,dedicated_least_privilege_verified=True,
    overgrant_readiness_blocked=True,overgrant_error_code="CHAT_DATABASE_GRANTS_INVALID",evidence="database grant evidence")
for i,row in enumerate(real["devices"]):row.update(id=f"device-{i}",passed=True,evidence="device evidence")
real["ui"].update(weak_network=True,dark_mode=True,large_text=True,safe_area=True,album_permissions=True,evidence="ui evidence")
for row in real["checks"].values():row.update(status="passed",evidence="real evidence")
real["channels"].update(status="passed",simulated=False,evidence="channel evidence",private_media_expiry=True,platform_safety=True,subscription_receipts=True,field_images_reviewed=True,manual_wait_reviewed=True)
real["capacity"].update(status="passed",environment="target_host",connections=[30,100,300],history_sizes=[1000,10000,100000],mixed_order_media_notifications=True,baseline_order_p95_ms=100,mixed_order_p95_ms=105,headroom_percent=40,authenticated_limit=300,over_limit_upgrade_completed=True,over_limit_auth_ok=False,evidence="capacity evidence")
merged=b.build(auto,real,R)
assert release.validate(merged,R,"full")==["human_release_authorization"],release.validate(merged,R,"full")
assert merged["capabilities"]==["text","images","governance","reminders"]
assert "audio" not in merged["capabilities"] and "video" not in merged["capabilities"] and "calls" not in merged["capabilities"] and "paid_sms" not in merged["capabilities"]
assert merged["database_contract"]["mode"]=="dedicated"
assert merged["capacity"]["authenticated_limit"]==300 and merged["capacity"]["over_limit_auth_ok"] is False
assert set(merged["non_av_contracts"])=={"opaque_action_id_and_expiry","recall_safe_audit_search","admin_identity_isolation"}
authorized=b.build(auto,real,R,"approved-release-1")
assert release.validate(authorized,R,"full")==[],release.validate(authorized,R,"full")
bad=copy.deepcopy(auto);bad["cases"]=bad["cases"][:-1]
try:b.build(bad,real,R);raise AssertionError("missing case accepted")
except ValueError:pass
bad=copy.deepcopy(auto);bad["checks"]["opaque_action_id_and_expiry"]=False
try:b.build(bad,real,R);raise AssertionError("missing contract check accepted")
except ValueError:pass
bad=copy.deepcopy(auto);bad["schema"]=1
try:b.build(bad,real,R);raise AssertionError("legacy automation schema accepted")
except ValueError:pass
print("non-AV evidence merger passed")
