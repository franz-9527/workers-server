#!/usr/bin/env python3
"""Validate a continuous archived binlog range; never execute its SQL."""
import argparse, base64, hashlib, hmac, json, os, pathlib, re, subprocess, sys, tempfile

def encode(v):
    return json.dumps(v,ensure_ascii=False,separators=(",",":")).encode()
def read(path,limit=65536):
    path=pathlib.Path(path)
    if path.is_symlink() or not path.is_file() or path.stat().st_size>limit or path.stat().st_mode&0o022:
        raise ValueError("protected file required")
    def pairs(items):
        out={}
        for k,v in items:
            if k in out: raise ValueError("duplicate field")
            out[k]=v
        return out
    return json.loads(path.read_bytes(),object_pairs_hook=pairs)
def sealed(path,key):
    v=read(path);p=v["payload"]
    if not hmac.compare_digest(v["mac"],hmac.new(key,encode(p),"sha256").hexdigest()):
        raise ValueError("checkpoint signature")
    return p
def coordinate(v):
    name=v["file"];m=re.fullmatch(r"([A-Za-z0-9_-]+\.)([0-9]{6})",name)
    pos=v["position"]
    if not m or not re.fullmatch(r"[0-9]{1,20}",pos) or int(pos)<4 or not re.fullmatch(r"[0-9a-f-]{36}",v["server_uuid"]):
        raise ValueError("binlog coordinate")
    return m[1],int(m[2]),int(pos)
def verify_range(start,end,directory,binary):
    a,first,position=coordinate(start);b,last,stop=coordinate(end)
    if a!=b or start["server_uuid"]!=end["server_uuid"] or first>last or last-first>10000 or first==last and position>stop:
        raise ValueError("noncontinuous range")
    root=pathlib.Path(directory).resolve(strict=True)
    if not root.is_dir(): raise ValueError("archive directory")
    hashes={}
    for n in range(first,last+1):
        name=a+str(n).zfill(6);path=root/name
        if path.is_symlink() or not path.is_file() or path.resolve().parent!=root:
            raise ValueError("binlog gap")
        size=path.stat().st_size
        lo=position if n==first else 4
        hi=stop if n==last else size
        if lo>hi or hi>size: raise ValueError("incomplete archive tail")
        with path.open("rb") as f:
            if f.read(4)!=b"\xfebin": raise ValueError("binlog header")
            digest=hashlib.sha256()
            f.seek(0)
            while chunk:=f.read(1024*1024): digest.update(chunk)
        cmd=[binary,"--no-defaults","--verify-binlog-checksum","--start-position="+str(lo),"--stop-position="+str(hi),str(path)]
        p=subprocess.run(cmd,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=120)
        if p.returncode: raise ValueError("binlog verification")
        hashes[name]={"bytes":size,"sha256":digest.hexdigest(),"start":lo,"stop":hi}
    return hashes

def main(args):
    encoded=os.environ.get(args.key_env,"")
    if not re.fullmatch(r"CHAT_[A-Z0-9_]{1,60}",args.key_env): raise ValueError("key name")
    key=base64.b64decode(encoded,validate=True)
    if len(key)!=32 or base64.b64encode(key).decode()!=encoded: raise ValueError("key")
    directory=pathlib.Path(args.control).resolve(strict=True)
    gate=read(directory/"gate.json",1024)
    if gate["state"]!="blocked": raise ValueError("maintenance required")
    before=sealed(args.backup_checkpoint,key)
    after=sealed(directory/"checkpoint.json",key)
    latest=read(directory/"latest.json",4096)
    latest_hash=hashlib.sha256(encode(read(directory/"checkpoint.json"))).hexdigest()
    if latest_hash!=latest["sha256"] or latest["watermark"]!=after["watermark"]:
        raise ValueError("stale checkpoint")
    for field in ("schema","environment","epoch"):
        if before[field]!=after[field] or after[field]!=gate[field] or (field=="schema" and after[field]!=1):
            raise ValueError("environment or epoch")
    binary=pathlib.Path(args.mysqlbinlog).resolve(strict=True)
    if not binary.is_file() or not os.access(binary,os.X_OK): raise ValueError("explicit mysqlbinlog executable")
    hashes=verify_range(before["watermark"],after["watermark"],args.archive,str(binary))
    p={"schema":1,"environment":after["environment"],"epoch":after["epoch"],"watermark":after["watermark"],
        "complete":True,"from":before["watermark"],"archive_sha256":hashlib.sha256(encode(hashes)).hexdigest()}
    proof={"payload":p,"mac":hmac.new(key,encode(p),"sha256").hexdigest()}
    fd,name=tempfile.mkstemp(prefix=".continuity-",dir=directory)
    try:
        os.fchmod(fd,0o640)
        with os.fdopen(fd,"wb") as out:
            out.write(encode(proof));out.flush();os.fsync(out.fileno())
        os.replace(name,directory/"continuity.json")
    finally:
        if os.path.exists(name): os.unlink(name)
    print(json.dumps({"status":"verified","files":len(hashes),"sql_executed":False}))
if __name__=="__main__":
    p=argparse.ArgumentParser()
    p.add_argument("--control",required=True);p.add_argument("--backup-checkpoint",required=True)
    p.add_argument("--archive",required=True);p.add_argument("--mysqlbinlog",required=True)
    p.add_argument("--key-env",default="CHAT_RECOVERY_KEY")
    try: main(p.parse_args())
    except ValueError as error:
        allowed={"protected file required","duplicate field","checkpoint signature","binlog coordinate","noncontinuous range",
            "archive directory","binlog gap","incomplete archive tail","binlog header","binlog verification","key name",
            "key","maintenance required","stale checkpoint","environment or epoch","explicit mysqlbinlog executable"}
        reason=str(error) if str(error) in allowed else "invalid structure"
        print(json.dumps({"status":"blocked","code":"CHAT_RECOVERY_INCREMENT_GAP","reason":reason}));sys.exit(1)
    except (OSError,KeyError,TypeError,subprocess.SubprocessError):
        print('{"status":"blocked","code":"CHAT_RECOVERY_INCREMENT_GAP","reason":"dependency or structure unavailable"}');sys.exit(1)
