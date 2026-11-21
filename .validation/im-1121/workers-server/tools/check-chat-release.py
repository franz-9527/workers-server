#!/usr/bin/env python3
"""Fail-closed joint acceptance; fixture/source evidence cannot satisfy host/device gates."""
import argparse, json, math, pathlib, re, sys
CASES=([f"C{i:02}" for i in range(1,48) if i!=20]+[f"G{i:02}" for i in range(1,17)]
       +[f"U{i:02}" for i in list(range(1,10))+list(range(12,18))]+[f"I{i:02}" for i in range(1,9)]+[f"AUD{i:02}" for i in range(1,11)])
AREAS=("automation","devices","capacity","deployment","channels","recovery")

# L1 text excludes image, large mixed-capacity, reminders and test-center cases.
TEXT_CASES=([f"C{i:02}" for i in list(range(1,8))+list(range(10,14))+[15]+list(range(16,20))+list(range(22,25))+list(range(34,43))+[44]]
            +[f"G{i:02}" for i in list(range(1,8))+[10,11]+list(range(13,17))]
            +[f"U{i:02}" for i in list(range(1,10))+list(range(12,18))]
            +[f"AUD{i:02}" for i in (1,2,5,6,10)])
IMAGE_CASES=([f"C{i:02}" for i in [8,9,14]+list(range(25,34))+[43,45,46]]
             +[f"I{i:02}" for i in range(1,9)]
             +[f"AUD{i:02}" for i in (3,4,8,9)])
PROFILE_CAPABILITIES={
    "text":["text","governance"],
    "text-images":["text","governance","images"],
    "full":["text","images","governance","reminders"],
    "selected":["text","images","governance","reminders","audio","asr","video","calls","paid_sms"],
}
TEXT_AREAS=("automation","devices","deployment","recovery")

def expected_database_version():
    version=(pathlib.Path(__file__).resolve().parents[1]/"server"/"VERSION").read_text(encoding="utf-8").strip()
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+",version):
        raise ValueError("invalid server VERSION")
    return version

def profile_cases(profile):
    if profile=="text": return TEXT_CASES
    if profile=="text-images": return TEXT_CASES+IMAGE_CASES
    return CASES

def profile_areas(profile):
    if profile=="text": return TEXT_AREAS
    if profile=="text-images": return TEXT_AREAS+("channels",)
    return AREAS

def validate(e,revision,profile=None):
    failures=[]
    def require(ok,code):
        if not ok: failures.append(code)
    if not isinstance(e,dict): return ["evidence_object"]
    def obj(value):
        return value if isinstance(value,dict) else {}
    profile=profile or e.get("profile","full")
    if not isinstance(profile,str) or profile not in PROFILE_CAPABILITIES: return ["profile"]
    def has_evidence(row):
        value=row.get("evidence")
        return isinstance(value,str) and len(value.strip())>=5
    require(e.get("profile","full")==profile,"profile_mismatch")
    require(e.get("schema")==1,"schema")
    require(e.get("environment") in ("test","development","production") and isinstance(e.get("recovery_epoch"),str) and bool(re.fullmatch(r"[0-9a-f]{64}",e.get("recovery_epoch",""))),"environment_and_recovery_epoch")
    require(isinstance(revision,str) and bool(re.fullmatch(r"[0-9a-f]{40}",revision)) and e.get("revision")==revision,"exact_revision")
    require(e.get("go_version")=="go1.27.3" and e.get("database_version")==expected_database_version(),"runtime_versions")
    require(e.get("release_authorized") is True and isinstance(e.get("authorization_reference"),str) and 5<=len(e.get("authorization_reference",""))<=512,"human_release_authorization")
    cases=obj(e.get("cases"))
    for case in profile_cases(profile):
        row=obj(cases.get(case))
        require(row.get("status")=="passed" and row.get("revision")==revision and has_evidence(row),case)
    for area in profile_areas(profile):
        row=obj(e.get(area))
        require(row.get("status")=="passed" and row.get("revision")==revision and has_evidence(row),area)
    a=obj(e.get("automation"))
    require(a.get("race") is True and a.get("mysql57") is True and a.get("php_version")=="8.3.36"
            and a.get("redis") is True and a.get("source_verified") is True,"automation_contract")
    d=obj(e.get("devices"))
    devices=d.get("physical_devices",[])
    valid_devices=isinstance(devices,list) and all(isinstance(x,dict) and isinstance(x.get("id"),str) and x.get("id","").strip() for x in devices)
    require(valid_devices and len(devices)>=2 and len({x.get("id") for x in devices})>=2
            and {"ios","android"}<={x.get("platform") for x in devices}
            and all(x.get("physical") is True and x.get("passed") is True for x in devices),"two_real_devices")
    require(d.get("weak_network") is True and d.get("large_text") is True and d.get("dark_mode") is True
            and d.get("safe_area") is True,"device_ui")
    if profile in ("text-images","full","selected"):
        require(d.get("album_permissions") is True,"album_permissions")
    deploy=obj(e.get("deployment"))
    require(deploy.get("actual_sha")==revision and deploy.get("source_dirty") is False
            and deploy.get("nonroot_processes")==["serve","worker"] and deploy.get("private_internal_port") is True
            and deploy.get("php_routes_preserved") is True and deploy.get("rollback_passed") is True
            and deploy.get("authenticated_wss") is True,"actual_deployment_and_rollback")
    recovery=obj(e.get("recovery"))
    require(recovery.get("independent_increment_complete") is True and recovery.get("old_permissions_denied") is True
            and recovery.get("recall_not_revived") is True and recovery.get("notification_not_resent") is True
            and recovery.get("wallet_not_recharged") is True and recovery.get("gap_blocks_access") is True,"safe_incremental_restore")
    if profile in ("text","text-images"):
        tc=obj(e.get("text_channels"))
        require(tc.get("status")=="passed" and tc.get("revision")==revision and has_evidence(tc)
                and tc.get("simulated") is False and tc.get("platform_safety") is True
                and tc.get("wechat_text_safety") is True,"actual_text_channels")
    if profile=="text-images":
        channels=obj(e.get("channels"))
        require(channels.get("simulated") is False and channels.get("private_media_expiry") is True
                and channels.get("platform_safety") is True and channels.get("field_images_reviewed") is True
                and channels.get("manual_wait_reviewed") is True,"actual_image_channels")
        image=obj(e.get("image_acceptance"))
        require(image.get("status")=="passed" and image.get("revision")==revision and has_evidence(image)
                and image.get("private_storage") is True and image.get("upload_download") is True
                and image.get("pre_publish_review") is True and image.get("recall_and_expiry") is True
                and image.get("simulated") is False,"image_acceptance")
    if profile in ("full","selected"):
        c=obj(e.get("capacity"))
        require(c.get("environment")=="target_host" and c.get("connections")==[30,100,300]
                and c.get("history_sizes")==[1000,10000,100000]
                and c.get("mixed_order_media_notifications") is True,"target_mixed_capacity")
        require(c.get("authenticated_limit")==300 and c.get("over_limit_upgrade_completed") is True
                and c.get("over_limit_auth_ok") is False,"authenticated_connection_capacity")
        base=c.get("baseline_order_p95_ms",0);mixed=c.get("mixed_order_p95_ms",0);headroom=c.get("headroom_percent",0)
        numeric=all(type(v) in (int,float) and math.isfinite(v) for v in (base,mixed,headroom))
        require(numeric and base>0 and 0<mixed<=base*1.10 and 30<=headroom<=100,"order_budget_and_headroom")
        database=obj(e.get("database_contract"))
        dedicated=(database.get("mode")=="dedicated" and database.get("shared_app_credentials") is False
                   and database.get("dedicated_least_privilege_verified") is True
                   and database.get("overgrant_readiness_blocked") is True
                   and database.get("overgrant_error_code")=="CHAT_DATABASE_GRANTS_INVALID")
        managed=(database.get("mode")=="managed_shared" and database.get("shared_app_credentials") is True
                 and database.get("dedicated_least_privilege_verified") is False
                 and database.get("overgrant_readiness_blocked") is False
                 and database.get("overgrant_error_code")=="")
        require(has_evidence(database) and (dedicated or managed),"database_credential_contract")
        contracts=obj(e.get("non_av_contracts"))
        for name in ("opaque_action_id_and_expiry","recall_safe_audit_search","admin_identity_isolation"):
            value=contracts.get(name)
            require(isinstance(value,str) and 5<=len(value.strip())<=512,"non_av_contract_"+name)
        channels=obj(e.get("channels"))
        require(channels.get("simulated") is False and channels.get("private_media_expiry") is True
                and channels.get("platform_safety") is True and channels.get("subscription_receipts") is True
                and channels.get("field_images_reviewed") is True and channels.get("manual_wait_reviewed") is True,"actual_channels")
    caps=e.get("capabilities",PROFILE_CAPABILITIES["full"])
    valid_caps=isinstance(caps,list) and all(isinstance(x,str) for x in caps)
    caps_set=set(caps) if valid_caps else set()
    allowed_caps={"text","images","governance","reminders","audio","asr","video","calls","video_calls","paid_sms"}
    require(valid_caps and len(caps)==len(caps_set) and caps_set<=allowed_caps
            and set(PROFILE_CAPABILITIES[profile])<=caps_set,"capabilities")
    if profile == "selected":
        require(caps in (PROFILE_CAPABILITIES[profile], PROFILE_CAPABILITIES[profile]+["video_calls"]),"profile_capabilities")
    elif profile in ("text","text-images"):
        require(caps==PROFILE_CAPABILITIES[profile],"profile_capabilities")
    if isinstance(caps,list) and ("audio" in caps or "video" in caps):
        required={5,6,8,9,11,12,13,14,15,16,18,19,20}
        if "video" in caps: required.update((1,2,3,4,7,10,17))
        for n in sorted(required):
            case="AV"+str(n).zfill(2);row=obj(cases.get(case))
            require(row.get("status")=="passed" and row.get("revision")==revision and has_evidence(row),case)
        channels=obj(e.get("channels"))
        require(channels.get("actual_asr_contract") is True and channels.get("audio_callback_crypto") is True,"actual_av_channels")
        require(d.get("microphone_permissions") is True and d.get("local_cache_after_cloud_expiry") is True,"actual_av_devices")
        if "video" in caps: require(channels.get("full_clip_review") is True and channels.get("retained_frames") is True,"actual_video_review")
    if isinstance(caps,list) and "calls" in caps:
        call=obj(e.get("calls"))
        require(call.get("status")=="passed" and call.get("revision")==revision and has_evidence(call),"calls_evidence")
        for key in ("two_real_devices","weak_network","background_cleanup",
                    "mutual_exclusion","business_revocation","provider_fallback",
                    "unknown_media_retained","shared_quota_race","verified_entitlements","free_boundary_hard_stop",
                    "cycle_rollover","supplier_reconciliation","crash_recovery","no_recording","no_wallet_charge",
                    "publish_privilege_enforced","metering_upper_bound_verified"):
            require(call.get(key) is True,"calls_"+key)
        require(call.get("microphone_permissions") is True,"calls_microphone_permissions")
        require(call.get("wechat_real_audio") is True and call.get("shengwang_real_audio") is True,"calls_real_audio")
        require(call.get("simulated") is False and call.get("default_closed") is True,"calls_real_channels")
    if isinstance(caps,list) and "video_calls" in caps:
        call=obj(e.get("calls"))
        require(call.get("camera_permissions") is True and call.get("wechat_real_video") is True
                and call.get("shengwang_real_video") is True,"calls_real_video")
    if isinstance(caps,list) and "paid_sms" in caps:
        sms=obj(e.get("paid_sms"))
        require(sms.get("status")=="passed" and sms.get("revision")==revision and has_evidence(sms),"paid_sms_evidence")
        for key in ("approved_template","real_delivery_receipt","wallet_idempotency","read_revocation_suppression","no_body_disclosure","rate_limit_race","recharge_return","recovery_no_resend"):
            require(sms.get(key) is True,"paid_sms_"+key)
        require(sms.get("simulated") is False and sms.get("default_closed") is True,"paid_sms_real_channel")
    return failures

def template(profile="full"):
    if profile not in PROFILE_CAPABILITIES: raise ValueError("unknown profile")
    e={"schema":1,"profile":profile,"capabilities":PROFILE_CAPABILITIES[profile].copy(),
       "revision":"","environment":"test","recovery_epoch":"","go_version":"go1.27.3","database_version":expected_database_version(),
       "release_authorized":False,"authorization_reference":"",
       "cases":{case:{"status":"pending","revision":"","evidence":""} for case in profile_cases(profile)}}
    for area in profile_areas(profile): e[area]={"status":"pending","revision":"","evidence":""}
    if profile in ("text","text-images"):
        e["text_channels"]={"status":"pending","revision":"","evidence":"","simulated":False,
                            "platform_safety":False,"wechat_text_safety":False}
    if profile=="text-images":
        e["image_acceptance"]={"status":"pending","revision":"","evidence":"","private_storage":False,
                               "upload_download":False,"pre_publish_review":False,"recall_and_expiry":False,"simulated":False}
    if profile in ("full","selected"):
        e["capacity"].update(environment="target_host",connections=[],history_sizes=[],mixed_order_media_notifications=False,
                             baseline_order_p95_ms=0,mixed_order_p95_ms=0,headroom_percent=0,authenticated_limit=300,
                             over_limit_upgrade_completed=False,over_limit_auth_ok=None)
        e["database_contract"]={"mode":"","shared_app_credentials":None,"dedicated_least_privilege_verified":False,
                                "overgrant_readiness_blocked":False,"overgrant_error_code":"","evidence":""}
        e["non_av_contracts"]={"opaque_action_id_and_expiry":"","recall_safe_audit_search":"",
                               "admin_identity_isolation":""}
        for n in range(1,21): e["cases"]["AV"+str(n).zfill(2)]={"status":"pending","revision":"","evidence":""}
        e["calls"]={"status":"pending","revision":"","evidence":""}
        e["paid_sms"]={"status":"pending","revision":"","evidence":""}
    return e
def main():
    p=argparse.ArgumentParser()
    p.add_argument("--evidence");p.add_argument("--revision");p.add_argument("--template",action="store_true")
    p.add_argument("--profile",choices=("text","text-images","full","selected"),default="full")
    a=p.parse_args()
    if a.template: print(json.dumps(template(a.profile),ensure_ascii=False,indent=2));return 0
    if not a.evidence or not a.revision: p.error("--evidence and --revision are required")
    path=pathlib.Path(a.evidence)
    if path.stat().st_size>1048576: raise ValueError("evidence too large")
    e=json.loads(path.read_bytes())
    if not isinstance(e,dict): raise ValueError("evidence must be a JSON object")
    failures=validate(e,a.revision,a.profile)
    caps=e.get("capabilities",PROFILE_CAPABILITIES["full"])
    print(json.dumps({"status":"blocked" if failures else "ready_for_authorized_release","profile":a.profile,
        "capabilities":caps,"missing":failures,"deployment_executed":False}))
    return 1 if failures else 0
if __name__=="__main__":
    try: sys.exit(main())
    except (OSError,ValueError,TypeError,KeyError,AttributeError):
        print('{"status":"blocked","code":"CHAT_RELEASE_EVIDENCE_INVALID"}');sys.exit(2)
