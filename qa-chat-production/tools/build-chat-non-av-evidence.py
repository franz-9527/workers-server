#!/usr/bin/env python3
"""Merge isolated non-AV automation with test-site real evidence into the existing full release gate."""
import argparse, importlib.util, json, pathlib, re, sys
ROOT=pathlib.Path(__file__).resolve().parents[1]
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
RELEASE=load("chat_release",ROOT/"tools"/"check-chat-release.py")
REAL=load("chat_non_av_real",ROOT/"tools"/"check-chat-non-av-real.py")
AUTOMATION_SCHEMA=2
AUTOMATION_CHECKS=(
    "source_manifest","release_profiles","frontend_regressions","php_non_av_contracts",
    "order_authorization_and_revocation","platform_order_and_demo_isolation","go_race_all_packages",
    "images_automated","governance_automated","reminders_automated","paid_sms_policy_automated",
    "deployment_and_rollback","production_non_av","images_runtime","reminders_runtime","recovery_gate",
    "mysql_credential_contract","authenticated_connection_capacity",
    "opaque_action_id_and_expiry","recall_safe_audit_search","admin_identity_isolation",
)
def ref(v):return isinstance(v,str) and 5<=len(v.strip())<=512
def read(path):
    p=pathlib.Path(path)
    if p.stat().st_size>1048576:raise ValueError("evidence too large")
    v=json.loads(p.read_text(encoding="utf-8"))
    if not isinstance(v,dict):raise ValueError("evidence object required")
    return v
def validate_auto(d,revision):
    miss=[]
    if d.get("schema")!=AUTOMATION_SCHEMA:miss.append("schema")
    if d.get("profile")!="non-av-automation":miss.append("profile")
    if d.get("environment")!="isolated":miss.append("isolated_environment")
    if d.get("revision")!=revision or re.fullmatch(r"[0-9a-f]{40}",revision or "") is None:miss.append("exact_revision")
    if d.get("go_version")!="go1.27.3":miss.append("go_version")
    if d.get("database_version")!=RELEASE.expected_database_version():miss.append("database_version")
    if d.get("source_verified") is not True:miss.append("source_verified")
    if not ref(d.get("run_reference")):miss.append("run_reference")
    checks=d.get("checks") if isinstance(d.get("checks"),dict) else {}
    for name in AUTOMATION_CHECKS:
        if checks.get(name) is not True:miss.append(name)
    cases=d.get("cases")
    if not isinstance(cases,list) or len(cases)!=len(set(cases)) or set(cases)!=set(RELEASE.CASES):miss.append("full_case_coverage")
    return miss
def compact(*values):
    return "; ".join(str(v).strip() for v in values if isinstance(v,str) and v.strip())[:512]
def build(auto,real,revision,authorization=""):
    am=validate_auto(auto,revision);rm=REAL.validate(real,revision)
    if am:raise ValueError("automation:"+",".join(am))
    if rm:raise ValueError("real:"+",".join(rm))
    ev=RELEASE.template("full");runtime=real["runtime"];auto_ref=auto["run_reference"]
    ev.update(revision=revision,environment="test",recovery_epoch=runtime["recovery_epoch"],go_version=auto["go_version"],
              database_version=auto["database_version"],release_authorized=bool(authorization),authorization_reference=authorization.strip(),
              capabilities=RELEASE.PROFILE_CAPABILITIES["full"].copy())
    for case in RELEASE.CASES:
        ev["cases"][case].update(status="passed",revision=revision,evidence=auto_ref)
    ev["automation"].update(status="passed",revision=revision,evidence=auto_ref,race=True,mysql57=True,php_version="8.3.36",redis=True,source_verified=True)
    ev["devices"].update(status="passed",revision=revision,evidence=compact(*(r.get("evidence","") for r in real["devices"]),real["ui"].get("evidence","")),
        physical_devices=[{"id":r["id"],"platform":r["platform"],"physical":True,"passed":True} for r in real["devices"]],
        weak_network=True,large_text=True,dark_mode=True,safe_area=True,album_permissions=True)
    cap=real["capacity"]
    ev["capacity"].update(status="passed",revision=revision,evidence=cap["evidence"],environment="target_host",
        connections=cap["connections"],history_sizes=cap["history_sizes"],mixed_order_media_notifications=True,
        baseline_order_p95_ms=cap["baseline_order_p95_ms"],mixed_order_p95_ms=cap["mixed_order_p95_ms"],headroom_percent=cap["headroom_percent"],
        authenticated_limit=cap["authenticated_limit"],over_limit_upgrade_completed=cap["over_limit_upgrade_completed"],
        over_limit_auth_ok=cap["over_limit_auth_ok"])
    ev["deployment"].update(status="passed",revision=revision,evidence=compact(auto_ref,runtime["evidence"],real["checks"]["rollback"]["evidence"]),
        actual_sha=revision,source_dirty=False,nonroot_processes=["serve","worker"],private_internal_port=True,php_routes_preserved=True,rollback_passed=True,authenticated_wss=True)
    ch=real["channels"]
    ev["channels"].update(status="passed",revision=revision,evidence=ch["evidence"],simulated=False,
        private_media_expiry=True,platform_safety=True,subscription_receipts=True,field_images_reviewed=True,manual_wait_reviewed=True)
    ev["recovery"].update(status="passed",revision=revision,evidence=compact(auto_ref,real["checks"]["recovery_gate_and_no_revive"]["evidence"]),
        independent_increment_complete=True,old_permissions_denied=True,recall_not_revived=True,notification_not_resent=True,wallet_not_recharged=True,gap_blocks_access=True)
    ev["database_contract"]=dict(real["database"])
    ev["non_av_contracts"]={name:real["checks"][name]["evidence"] for name in (
        "opaque_action_id_and_expiry","recall_safe_audit_search","admin_identity_isolation",
    )}
    failures=RELEASE.validate(ev,revision,"full");expected=[] if authorization else ["human_release_authorization"]
    if failures!=expected:raise ValueError("merged:"+",".join(failures))
    return ev
def main():
    p=argparse.ArgumentParser();p.add_argument("--automation",required=True);p.add_argument("--real",required=True);p.add_argument("--revision",required=True);p.add_argument("--authorization-reference",default="");p.add_argument("--output");a=p.parse_args()
    ev=build(read(a.automation),read(a.real),a.revision,a.authorization_reference);payload=json.dumps(ev,ensure_ascii=False,indent=2)+"\n"
    if a.output:
        pathlib.Path(a.output).write_text(payload,encoding="utf-8")
        print(json.dumps({"status":"non_av_evidence_complete" if a.authorization_reference else "ready_for_human_authorization","revision":a.revision,"output":a.output,"production_business_retest":False},ensure_ascii=False))
    else:sys.stdout.write(payload)
    return 0
if __name__=="__main__":
    try:sys.exit(main())
    except (OSError,ValueError,TypeError,KeyError,json.JSONDecodeError) as e:
        print(json.dumps({"status":"blocked","code":"CHAT_NON_AV_EVIDENCE_MERGE_INVALID","reason":str(e)[:512],"production_business_retest":False},ensure_ascii=False));sys.exit(2)
