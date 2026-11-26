import json, os, re, subprocess, time, urllib.request, urllib.error
from pathlib import Path

root = Path(__file__).resolve().parent
image = "runtime-php:8.3.36"

def docker(*args, check=True):
    return subprocess.run(["docker", *args], text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, check=check)

def request(path, payload=None):
    req = urllib.request.Request("http://127.0.0.1:18080"+path, data=payload)
    try:
        with urllib.request.urlopen(req, timeout=5) as r:
            return r.status, r.headers, r.read().decode()
    except urllib.error.HTTPError as r:
        return r.code, r.headers, r.read().decode()

def check(condition, name):
    if not condition:
        raise AssertionError(name)
    print("PASS", name, flush=True)

def start(name, php_flags="", blocked_log=False):
    command = ("cp -a /work/source/server /tmp/site; mkdir -p /tmp/site/config /tmp/site/storage; "
               "cp /work/http-defaults.php /tmp/site/config/defaults.php; "
               "printf '%s\\n' '<?php private function broken() {}' > /tmp/site/config/broken.php; "
               + ("touch /tmp/site/storage/logs; " if blocked_log else "")
               + "exec php "+php_flags+" -S 0.0.0.0:8080 -t /tmp/site")
    docker("run","-d","--name",name,"-p","127.0.0.1:18080:8080","-v",str(root)+":/work:ro",image,"sh","-ec",command)
    for _ in range(40):
        try:
            request("/api/index.php")
            return
        except (OSError, urllib.error.URLError):
            time.sleep(.25)
    raise AssertionError("fixture HTTP server did not start")

def stop(name):
    print(docker("logs",name,check=False).stdout[-6000:])
    docker("rm","-f",name,check=False)

name="runtime-http-normal"
try:
    start(name)
    code,h,body=request("/api/index.php?fixture=error")
    obj=json.loads(body)
    check(code==500 and obj["code"]=="API_ERROR" and len(obj["request_id"])>=8 and "application/json" in h["Content-Type"] and "no-store" in h["Cache-Control"] and "fixture-secret" not in body,"early bootstrap exception JSON + correlation ID (even debug=true)")
    requestid=obj["request_id"]
    code,h,body=request("/admin/index.php?fixture=error")
    check(code==500 and "text/html" in h["Content-Type"] and "请求编号" in body and "fixture-secret" not in body,"admin HTML error redaction")
    code,h,body=request("/api/index.php?fixture=parse")
    check(code==500 and json.loads(body)["code"]=="API_ERROR" and "broken.php" not in body,"actual included PHP parse error contained")
    for route in ("wechatpay.notify","wechatpay.refund_notify"):
        code,h,body=request("/api/index.php?fixture=error&route="+route,b"<xml><fixture>1</fixture></xml>")
        check(code==500 and "text/xml" in h["Content-Type"] and "<![CDATA[FAIL]]>" in body,route+" v2 failure XML")
        code,h,body=request("/api/index.php?fixture=error&route="+route,b'{"fixture":1}')
        check(code==500 and json.loads(body)["code"]=="FAIL",route+" v3 failure JSON")
    records=docker("exec",name,"cat","/tmp/site/storage/logs/app.log").stdout
    rows=[json.loads(s) for s in records.splitlines() if s.strip()]
    check(any(r.get("request_id")==requestid for r in rows) and any(r.get("category")=="php_parse" for r in rows) and "fixture-secret" not in records,"structured disk log matches HTTP request and redacts message")
    code,h,body=request("/api/index.php?route[]=bad")
    check(code==400 and json.loads(body)["code"]=="INVALID_ROUTE","array route rejected")
finally:
    stop(name)

for name,flags in [("runtime-http-disabled-session","-d disable_functions=session_start"),("runtime-http-bad-session","-d session.save_path=/nonexistent/runtime-fixture")]:
    try:
        start(name,flags)
        code,h,body=request("/api/index.php")
        check(code==503 and json.loads(body)["code"]=="PHP_SESSION_UNAVAILABLE","session fail closed: "+name)
    finally:
        stop(name)

name="runtime-http-log-failure"
try:
    start(name,blocked_log=True)
    code,h,body=request("/api/index.php?fixture=error")
    check(code==500 and json.loads(body)["code"]=="API_ERROR" and "fixture-secret" not in body,"logging failure preserves API response")
    logs=docker("logs",name).stdout
    check("workers log write failed channel=app request_id=" in logs and "fixture-secret" not in logs,"native fallback contains no raw exception")
finally:
    stop(name)
