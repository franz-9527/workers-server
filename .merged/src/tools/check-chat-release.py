#!/usr/bin/env python3
"""Fail-closed joint acceptance; fixture/source evidence cannot satisfy host/device gates."""
import argparse, base64, hashlib, hmac, json, math, os, pathlib, re, sys, tempfile, time
CASES=([f"C{i:02}" for i in range(1,48) if i!=20]+[f"G{i:02}" for i in range(1,17)]
       +[f"U{i:02}" for i in list(range(1,10))+list(range(12,18))]+[f"I{i:02}" for i in range(1,9)]+[f"AUD{i:02}" for i in range(1,11)])
AREAS=("automation","devices","capacity","deployment","channels","recovery")
def validate(e,revision):
    failures=[]
    def require(ok,code):
        if not ok: failures.append(code)
    require(e.get("schema")==1,"schema")
    require(e.get("environment") in ("test","development","production") and bool(re.fullmatch(r"[0-9a-f]{64}",e.get("recovery_epoch",""))),"environment_and_recovery_epoch")
    require(bool(re.fullmatch(r"[0-9a-f]{40}",revision)) and e.get("revision")==revision,"exact_revision")
    require(e.get("go_version")=="go1.27.3" and e.get("database_version")=="2.7.0","runtime_versions")
    require(e.get("release_authorized") is True and isinstance(e.get("authorization_reference"),str) and 5<=len(e.get("authorization_reference",""))<=512,"human_release_authorization")
    cases=e.get("cases",{})
    for case in CASES:
        row=cases.get(case,{})
        require(row.get("status")=="passed" and row.get("revision")==revision and bool(row.get("evidence")),case)
    for area in AREAS:
        row=e.get(area,{})
        require(row.get("status")=="passed" and row.get("revision")==revision and bool(row.get("evidence")),area)
    a=e.get("automation",{})
    require(a.get("race") is True and a.get("mysql57") is True and a.get("php_version")=="8.3.36"
            and a.get("redis") is True and a.get("source_verified") is True,"automation_contract")
    d=e.get("devices",{})
    devices=d.get("physical_devices",[])
    require(len(devices)>=2 and len({x.get("id") for x in devices})>=2
            and {"ios","android"}<={x.get("platform") for x in devices}
            and all(x.get("physical") is True and x.get("passed") is True for x in devices),"two_real_devices")
    require(d.get("weak_network") is True and d.get("large_text") is True and d.get("dark_mode") is True
            and d.get("album_permissions") is True and d.get("safe_area") is True,"device_ui")
    c=e.get("capacity",{})
    require(c.get("environment")=="target_host" and c.get("connections")==[30,100,300]
            and c.get("history_sizes")==[1000,10000,100000]
            and c.get("mixed_order_media_notifications") is True,"target_mixed_capacity")
    base=c.get("baseline_order_p95_ms",0);mixed=c.get("mixed_order_p95_ms",0);headroom=c.get("headroom_percent",0)
    numeric=all(type(v) in (int,float) and math.isfinite(v) for v in (base,mixed,headroom))
    require(numeric and base>0 and 0<mixed<=base*1.10 and 30<=headroom<=100,"order_budget_and_headroom")
    deploy=e.get("deployment",{})
    require(deploy.get("actual_sha")==revision and deploy.get("source_dirty") is False
            and deploy.get("nonroot_processes")==["serve","worker"] and deploy.get("private_internal_port") is True
            and deploy.get("php_routes_preserved") is True and deploy.get("rollback_passed") is True
            and deploy.get("authenticated_wss") is True,"actual_deployment_and_rollback")
    channels=e.get("channels",{})
    require(channels.get("simulated") is False and channels.get("private_media_expiry") is True
            and channels.get("platform_safety") is True and channels.get("subscription_receipts") is True
            and channels.get("field_images_reviewed") is True and channels.get("manual_wait_reviewed") is True,"actual_channels")
    recovery=e.get("recovery",{})
    require(recovery.get("independent_increment_complete") is True and recovery.get("old_permissions_denied") is True
            and recovery.get("recall_not_revived") is True and recovery.get("notification_not_resent") is True
            and recovery.get("wallet_not_recharged") is True and recovery.get("gap_blocks_access") is True,"safe_incremental_restore")
    caps=e.get("capabilities",["text","images","governance","reminders"])
    require(isinstance(caps,list) and len(caps)==len(set(caps)) and set(caps)<=set(("text","images","governance","reminders","audio","video","calls","paid_sms")),"capabilities")
    if isinstance(caps,list) and ("audio" in caps or "video" in caps):
        required={5,6,8,9,11,12,13,14,15,16,18,19,20}
        if "video" in caps: required.update((1,2,3,4,7,10,17))
        for n in sorted(required):
            case="AV"+str(n).zfill(2);row=cases.get(case,{})
            require(row.get("status")=="passed" and row.get("revision")==revision and bool(row.get("evidence")),case)
        require(channels.get("actual_asr_contract") is True and channels.get("audio_callback_crypto") is True,"actual_av_channels")
        require(d.get("microphone_permissions") is True and d.get("local_cache_after_cloud_expiry") is True,"actual_av_devices")
        if "video" in caps: require(channels.get("full_clip_review") is True and channels.get("retained_frames") is True,"actual_video_review")
    if isinstance(caps,list) and "calls" in caps:
        call=e.get("calls",{})
        require(call.get("status")=="passed" and call.get("revision")==revision and bool(call.get("evidence")),"calls_evidence")
        for key in ("two_real_devices","wechat_real_media","shengwang_real_media","weak_network","background_cleanup",
                    "microphone_camera_permissions","mutual_exclusion","business_revocation","provider_fallback",
                    "unknown_media_retained","shared_quota_race","verified_entitlements","free_boundary_hard_stop",
                    "cycle_rollover","supplier_reconciliation","crash_recovery","no_recording","no_wallet_charge",
                    "publish_privilege_enforced","metering_upper_bound_verified"):
            require(call.get(key) is True,"calls_"+key)
        require(call.get("simulated") is False and call.get("default_closed") is True,"calls_real_channels")
    if isinstance(caps,list) and "paid_sms" in caps:
        sms=e.get("paid_sms",{})
        require(sms.get("status")=="passed" and sms.get("revision")==revision and bool(sms.get("evidence")),"paid_sms_evidence")
        for key in ("approved_template","real_delivery_receipt","wallet_idempotency","read_revocation_suppression","no_body_disclosure","rate_limit_race","recharge_return","recovery_no_resend"):
            require(sms.get(key) is True,"paid_sms_"+key)
        require(sms.get("simulated") is False and sms.get("default_closed") is True,"paid_sms_real_channel")
    return failures
def template():
    e={"schema":1,"revision":"","environment":"test","recovery_epoch":"","go_version":"go1.27.3","database_version":"2.7.0",
       "release_authorized":False,"authorization_reference":"",
       "cases":{case:{"status":"pending","revision":"","evidence":""} for case in CASES}}
    for area in AREAS: e[area]={"status":"pending","revision":"","evidence":""}
    return e
def main():
    p=argparse.ArgumentParser()
    p.add_argument("--evidence");p.add_argument("--revision");p.add_argument("--template",action="store_true")
    p.add_argument("--seal");p.add_argument("--key-env",default="CHAT_RELEASE_KEY")
    a=p.parse_args()
    if a.template: print(json.dumps(template(),ensure_ascii=False,indent=2));return 0
    if not a.evidence or not a.revision: p.error("--evidence and --revision are required")
    path=pathlib.Path(a.evidence)
    if path.stat().st_size>1048576: raise ValueError("evidence too large")
    e=json.loads(path.read_bytes())
    failures=validate(e,a.revision)
    if a.seal and not failures:
        if e.get("environment") not in ("test","production","development") or not re.fullmatch(r"[0-9a-f]{64}",e.get("recovery_epoch","")):
            raise ValueError("release environment/epoch")
        if not re.fullmatch(r"CHAT_[A-Z0-9_]{1,60}",a.key_env): raise ValueError("release key name")
        encoded=os.environ.get(a.key_env,"");key=base64.b64decode(encoded,validate=True)
        if len(key)!=32 or base64.b64encode(key).decode()!=encoded: raise ValueError("release key")
        destination=pathlib.Path(a.seal)
        if not destination.is_absolute() or destination.is_symlink(): raise ValueError("private absolute seal path")
        body=json.dumps({"schema":1,"environment":e["environment"],"epoch":e["recovery_epoch"],"revision":a.revision,
            "expires_at":int(time.time())+86390,"capabilities":e.get("capabilities",["text","images","governance","reminders"])},separators=(",",":")).encode()
        envelope={"payload":base64.b64encode(body).decode(),"mac":hmac.new(key,body,"sha256").hexdigest()}
        fd,name=tempfile.mkstemp(prefix=".release-",dir=destination.parent)
        try:
            os.fchmod(fd,0o640)
            with os.fdopen(fd,"w") as out:
                json.dump(envelope,out,separators=(",",":"));out.flush();os.fsync(out.fileno())
            os.replace(name,destination)
        finally:
            if os.path.exists(name): os.unlink(name)
    print(json.dumps({"status":"blocked" if failures else "ready_for_authorized_release","missing":failures,"deployment_executed":False}))
    return 1 if failures else 0
if __name__=="__main__":
    try: sys.exit(main())
    except (OSError,ValueError,TypeError,KeyError,AttributeError):
        print('{"status":"blocked","code":"CHAT_RELEASE_EVIDENCE_INVALID"}');sys.exit(2)
