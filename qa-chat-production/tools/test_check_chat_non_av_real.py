#!/usr/bin/env python3
import importlib.util, pathlib
ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("real",ROOT/"tools"/"check-chat-non-av-real.py");m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
R="a"*40;E="b"*64
d=m.template(R)
assert "runtime_binding" in m.validate(d,R)
d["runtime"].update(deployed_sha=R,running_sha=R,source_dirty=False,recovery_epoch=E,evidence="runtime evidence")
for i,row in enumerate(d["devices"]):
    row.update(id=f"device-{i}",passed=True,evidence="physical device evidence")
d["ui"].update(weak_network=True,dark_mode=True,large_text=True,safe_area=True,album_permissions=True,evidence="ui evidence")
for row in d["checks"].values():row.update(status="passed",evidence="real test evidence")
d["channels"].update(status="passed",simulated=False,evidence="real channels evidence",private_media_expiry=True,platform_safety=True,subscription_receipts=True,field_images_reviewed=True,manual_wait_reviewed=True)
d["capacity"].update(status="passed",environment="target_host",connections=[30,100,300],history_sizes=[1000,10000,100000],mixed_order_media_notifications=True,baseline_order_p95_ms=100,mixed_order_p95_ms=108,headroom_percent=35,evidence="capacity evidence")
assert m.validate(d,R)==[],m.validate(d,R)
bad=dict(d);bad["revision"]="c"*40
assert "exact_revision" in m.validate(bad,R)
bad=__import__("copy").deepcopy(d);bad["checks"]["reassignment_revocation"]["status"]="pending"
assert "reassignment_revocation" in m.validate(bad,R)
bad=__import__("copy").deepcopy(d);bad["capacity"]["mixed_order_p95_ms"]=111
assert "target_mixed_capacity" in m.validate(bad,R)
print("non-AV real evidence validator passed")
