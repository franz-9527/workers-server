#!/usr/bin/env python3
"""Validate test.20m.cc real-device/channel evidence for the non-AV release."""
import argparse, json, math, pathlib, re, sys

REAL_SCHEMA=2
CHECKS=(
    "ordinary_order_chat","platform_order_chat","measurement_order_chat","collaboration_group",
    "identity_and_cross_order_denial","reassignment_revocation","closure_and_ban_revocation",
    "text_reply_edit_recall","opaque_action_id_and_expiry","image_send_preview_private_access",
    "history_read_cache_reconnect","wechat_text_safety","qiniu_image_channel","subscription_reminder_channel",
    "notification_dedupe_no_paid_sms","admin_governance_audit","recall_safe_audit_search",
    "admin_identity_isolation","release_gate","recovery_gate_and_no_revive","rollback",
)
CHANNEL_FLAGS=(
    "private_media_expiry","platform_safety","subscription_receipts",
    "field_images_reviewed","manual_wait_reviewed",
)

def valid_revision(v): return isinstance(v,str) and re.fullmatch(r"[0-9a-f]{40}",v) is not None
def valid_epoch(v): return isinstance(v,str) and re.fullmatch(r"[0-9a-f]{64}",v) is not None
def evidence(row):
    v=row.get("evidence") if isinstance(row,dict) else None
    return isinstance(v,str) and 5<=len(v.strip())<=512
def finite(v): return type(v) in (int,float) and math.isfinite(v)

def template(revision):
    if not valid_revision(revision): raise ValueError("revision")
    return {
        "schema":REAL_SCHEMA,"profile":"non-av-real","revision":revision,"environment":"test",
        "runtime":{"deployed_sha":"","running_sha":"","source_dirty":False,"recovery_epoch":"","evidence":""},
        "database":{"mode":"","shared_app_credentials":None,"dedicated_least_privilege_verified":False,
                    "overgrant_readiness_blocked":False,"overgrant_error_code":"","evidence":""},
        "devices":[
            {"id":"","platform":"ios","physical":True,"passed":False,"evidence":""},
            {"id":"","platform":"android","physical":True,"passed":False,"evidence":""},
        ],
        "ui":{"weak_network":False,"dark_mode":False,"large_text":False,"safe_area":False,"album_permissions":False,"evidence":""},
        "checks":{name:{"status":"pending","evidence":"","notes":""} for name in CHECKS},
        "channels":{"status":"pending","simulated":False,"evidence":"","private_media_expiry":False,
                    "platform_safety":False,"subscription_receipts":False,"field_images_reviewed":False,
                    "manual_wait_reviewed":False},
        "capacity":{"status":"pending","environment":"target_host","connections":[],"history_sizes":[],
                    "mixed_order_media_notifications":False,"baseline_order_p95_ms":0,
                    "mixed_order_p95_ms":0,"headroom_percent":0,"authenticated_limit":300,
                    "over_limit_upgrade_completed":False,"over_limit_auth_ok":None,"evidence":""},
    }

def validate(data,revision):
    missing=[]
    if not isinstance(data,dict): return ["evidence_object"]
    if data.get("schema")!=REAL_SCHEMA: missing.append("schema")
    if data.get("profile")!="non-av-real": missing.append("profile")
    if data.get("environment")!="test": missing.append("test_environment_only")
    if not valid_revision(revision) or data.get("revision")!=revision: missing.append("exact_revision")
    runtime=data.get("runtime") if isinstance(data.get("runtime"),dict) else {}
    if runtime.get("deployed_sha")!=revision or runtime.get("running_sha")!=revision or runtime.get("source_dirty") is not False or not valid_epoch(runtime.get("recovery_epoch")) or not evidence(runtime):
        missing.append("runtime_binding")
    database=data.get("database") if isinstance(data.get("database"),dict) else {}
    dedicated=(database.get("mode")=="dedicated" and database.get("shared_app_credentials") is False
               and database.get("dedicated_least_privilege_verified") is True
               and database.get("overgrant_readiness_blocked") is True
               and database.get("overgrant_error_code")=="CHAT_DATABASE_GRANTS_INVALID")
    managed=(database.get("mode")=="managed_shared" and database.get("shared_app_credentials") is True
             and database.get("dedicated_least_privilege_verified") is False
             and database.get("overgrant_readiness_blocked") is False
             and database.get("overgrant_error_code")=="")
    if not evidence(database) or not (dedicated or managed): missing.append("database_credential_contract")
    rows=data.get("devices") if isinstance(data.get("devices"),list) else []
    good=[r for r in rows if isinstance(r,dict) and isinstance(r.get("id"),str) and r.get("id").strip()
          and r.get("platform") in ("ios","android") and r.get("physical") is True and r.get("passed") is True and evidence(r)]
    if len(good)<2 or len({r["id"] for r in good})<2 or {"ios","android"}-{r["platform"] for r in good}: missing.append("two_real_devices")
    ui=data.get("ui") if isinstance(data.get("ui"),dict) else {}
    if not all(ui.get(k) is True for k in ("weak_network","dark_mode","large_text","safe_area","album_permissions")) or not evidence(ui):
        missing.append("device_ui")
    checks=data.get("checks") if isinstance(data.get("checks"),dict) else {}
    for name in CHECKS:
        row=checks.get(name) if isinstance(checks.get(name),dict) else {}
        if row.get("status")!="passed" or not evidence(row): missing.append(name)
    channels=data.get("channels") if isinstance(data.get("channels"),dict) else {}
    if channels.get("status")!="passed" or channels.get("simulated") is not False or not evidence(channels) or not all(channels.get(k) is True for k in CHANNEL_FLAGS):
        missing.append("actual_channels")
    cap=data.get("capacity") if isinstance(data.get("capacity"),dict) else {}
    vals=(cap.get("baseline_order_p95_ms"),cap.get("mixed_order_p95_ms"),cap.get("headroom_percent"))
    capacity_contract=(cap.get("status")=="passed" and cap.get("environment")=="target_host"
        and cap.get("connections")==[30,100,300] and cap.get("history_sizes")==[1000,10000,100000]
        and cap.get("mixed_order_media_notifications") is True and cap.get("authenticated_limit")==300
        and cap.get("over_limit_upgrade_completed") is True and cap.get("over_limit_auth_ok") is False
        and evidence(cap) and all(finite(v) for v in vals) and vals[0]>0
        and 0<vals[1]<=vals[0]*1.10 and 30<=vals[2]<=100)
    if not capacity_contract: missing.append("target_mixed_capacity")
    return missing

def main():
    p=argparse.ArgumentParser();p.add_argument("--template",action="store_true");p.add_argument("--evidence");p.add_argument("--revision",required=True);a=p.parse_args()
    if a.template:
        print(json.dumps(template(a.revision),ensure_ascii=False,indent=2));return 0
    if not a.evidence:p.error("--evidence is required unless --template is used")
    path=pathlib.Path(a.evidence)
    if path.stat().st_size>524288: raise ValueError("evidence too large")
    data=json.loads(path.read_text(encoding="utf-8"));missing=validate(data,a.revision)
    print(json.dumps({"status":"blocked" if missing else "ready_for_non_av_evidence_merge","profile":"non-av-real","revision":a.revision,"missing":missing,"production_business_retest":False},ensure_ascii=False))
    return 1 if missing else 0

if __name__=="__main__":
    try:sys.exit(main())
    except (OSError,ValueError,TypeError,json.JSONDecodeError):
        print('{"status":"blocked","code":"CHAT_NON_AV_REAL_EVIDENCE_INVALID","production_business_retest":false}');sys.exit(2)
