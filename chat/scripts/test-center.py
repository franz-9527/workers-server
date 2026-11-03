#!/usr/bin/env python3
"""Run existing Go/MySQL contracts from a sanitized Test Center fixture."""
import argparse, base64, hashlib, hmac, json, os, pathlib, re, subprocess, sys, tempfile

SUITES = {
    "recovery": r"S21|S08|S10|S15|S09",
    "media": r"S21|S16|S17",
    "governance": r"S21|S18|S19",
    "reminders": r"S21|S20",
    "retention": r"S21|S22",
}
def validate(f):
    if set(f) != {"schema","instance","suite","environment","provider","order","actors","image_counts"}:
        raise ValueError("fixture fields")
    if type(f["schema"]) is not int or f["schema"] != 1 or not re.fullmatch(r"[A-Za-z0-9_-]{1,96}", f["instance"]):
        raise ValueError("fixture identity")
    if f["suite"] not in SUITES or f["environment"] != "test" or f["provider"] != "simulated":
        raise ValueError("isolated fixture required")
    o=f["order"]
    if set(o) != {"workflow","source","dispatch"} or o["workflow"] not in ("construction","measurement") or o["source"] not in ("consumer","platform") or o["dispatch"] not in ("multi_quote","single_grab"):
        raise ValueError("order fixture")
    if f["actors"] != ["worker","customer","platform_staff"] or f["image_counts"] != [1,2,4,9] or any(type(n) is not int for n in f["image_counts"]):
        raise ValueError("no injected identity or image input")
    return f

def run(args):
    if bool(args.sign_env)!=bool(args.report_file): raise ValueError("report output and signing key must be paired")
    path=pathlib.Path(args.fixture).resolve()
    if path.stat().st_size > 4096:
        raise ValueError("fixture too large")
    raw=path.read_bytes()
    def pairs(items):
        d={}
        for k,v in items:
            if k in d: raise ValueError("duplicate fixture field")
            d[k]=v
        return d
    f=validate(json.loads(raw,object_pairs_hook=pairs))
    canonical={"schema":1,"instance":f["instance"],"suite":f["suite"],"environment":"test","provider":"simulated",
        "order":{name:f["order"][name] for name in ("workflow","source","dispatch")},
        "actors":f["actors"],"image_counts":f["image_counts"]}
    fixture_hash=hashlib.sha256(json.dumps(canonical,ensure_ascii=False,separators=(",",":")).encode()).hexdigest()
    for key in ("CHAT_AUTH_TEST_INTEGRATION","CHAT_INTEGRATION"):
        if os.environ.get(key) != "1": raise ValueError("explicit isolated integration required")
    for key in ("CHAT_INTEGRATION_MYSQL_ADDR","CHAT_INTEGRATION_REDIS_ADDR"):
        if not re.fullmatch(r"127\.0\.0\.1:[1-9][0-9]{0,4}",os.environ.get(key,"")):
            raise ValueError("loopback dependencies required")
    env=dict(os.environ, CHAT_TEST_CENTER_FIXTURE=str(path), GOTOOLCHAIN="local")
    root=pathlib.Path(__file__).resolve().parents[1]
    # Fixed executable and argument list; fixture cannot introduce paths, URLs or shell.
    required="go"+next(line.split()[1] for line in (root/"go.mod").read_text().splitlines() if line.startswith("go "))
    actual=subprocess.check_output(["go","env","GOVERSION"],cwd=root,env=env,text=True,timeout=10).strip()
    if actual!=required: raise ValueError("locked Go toolchain required")
    source_hash=hashlib.sha256()
    for source in sorted(list((root/"internal").rglob("*.go"))+list((root/"cmd").rglob("*.go"))+[root/"go.mod",root/"go.sum"]):
        source_hash.update(str(source.relative_to(root)).encode()+b"\\0"+hashlib.sha256(source.read_bytes()).digest())
    cmd=["go","test","-race","-count=1","-json","-run",SUITES[f["suite"]],"./internal/message"]
    p=subprocess.run(cmd,cwd=root,env=env,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,timeout=900)
    tests=[]; failed=[]; skipped=[]
    for line in p.stdout.decode("utf-8",errors="replace").splitlines():
        try: row=json.loads(line)
        except ValueError: continue
        if row.get("Test") and row.get("Action")=="pass": tests.append(row["Test"])
        if row.get("Test") and row.get("Action")=="fail": failed.append(row["Test"])
        if row.get("Test") and row.get("Action")=="skip": skipped.append(row["Test"])
    report={"schema":1,"instance":f["instance"],"suite":f["suite"],"fixture_sha256":fixture_hash,
        "go_version":actual,"source_sha256":source_hash.hexdigest(),"source_revision":os.environ.get("CHAT_VERIFIED_SOURCE_REVISION","unrecorded"),
        "status":"passed" if p.returncode==0 and tests and not failed and not skipped else "failed",
        "tests":tests,"failed":failed,"skipped":skipped,"simulated_provider":True,"real_channel":False}
    # Do not echo raw test logs (they may contain fixture SQL). Operators can retain private CI logs.
    if args.report_file:
        if not args.sign_env or not re.fullmatch(r"CHAT_[A-Z0-9_]{1,60}",args.sign_env): raise ValueError("report signing key name")
        key_text=os.environ.get(args.sign_env,"");key=base64.b64decode(key_text,validate=True)
        if len(key)!=32 or base64.b64encode(key).decode()!=key_text or not re.fullmatch(r"[0-9a-f]{40}",report["source_revision"]):
            raise ValueError("report key/source binding")
        payload=json.dumps(report,ensure_ascii=False,separators=(",",":")).encode()
        envelope={"payload":base64.b64encode(payload).decode(),"mac":hmac.new(key,payload,"sha256").hexdigest()}
        target=pathlib.Path(args.report_file)
        if not target.is_absolute() or target.is_symlink(): raise ValueError("private absolute report path")
        fd,name=tempfile.mkstemp(prefix=".chat-report-",dir=target.parent)
        try:
            os.fchmod(fd,0o640)
            with os.fdopen(fd,"w") as output:
                json.dump(envelope,output,separators=(",",":"));output.flush();os.fsync(output.fileno())
            os.replace(name,target)
        finally:
            if os.path.exists(name): os.unlink(name)
    print(json.dumps(report,ensure_ascii=False))
    return 0 if report["status"]=="passed" else 1

if __name__=="__main__":
    parser=argparse.ArgumentParser()
    parser.add_argument("--fixture",required=True)
    parser.add_argument("--report-file");parser.add_argument("--sign-env")
    try: sys.exit(run(parser.parse_args()))
    except (ValueError,OSError,subprocess.SubprocessError,TypeError,KeyError):
        print('{"status":"failed","code":"CHAT_TEST_CENTER_INVALID_OR_UNAVAILABLE"}')
        sys.exit(2)
