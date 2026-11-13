#!/usr/bin/env python3
"""Independent repository only: restricted web diagnostics and real launcher reload."""
import json, os, pathlib, shutil, signal, socket, subprocess, tempfile, time, urllib.request
root=pathlib.Path(__file__).resolve().parents[2]
if os.environ.get("CHAT_RUNTIME_TEST")!="1" or root.name!="workers-server":
    raise SystemExit("independent test repository required")
count=0
def check(ok,label):
    global count
    if not ok: raise AssertionError(label)
    count+=1
    print("PASS",label,flush=True)
def port():
    with socket.socket() as listener:
        listener.bind(("127.0.0.1",0))
        return listener.getsockname()[1]
def wait_for(fn,seconds=35):
    deadline=time.monotonic()+seconds
    while time.monotonic()<deadline:
        try:
            value=fn()
            if value:return value
        except (OSError,ValueError):
            pass
        time.sleep(.15)
    raise AssertionError("timed out")
php=shutil.which("php")
with tempfile.TemporaryDirectory(prefix="chat-restricted-web-") as work:
    work=pathlib.Path(work);router=work/"router.php";listen=port()
    router.write_text("""<?php
set_error_handler(static function($level,$message,$file,$line){if(error_reporting()&$level)throw new ErrorException($message,0,$level,$file,$line);});
require __CLASS__;
$python=(string)getenv('CHAT_TEST_PYTHON');
if(isset($_GET['stale']))$python='/not-readable/not-installed/python';
$check=(new \\WorkerAdmin\\Application\\Chat\\ChatMediaPreflight())->inspect(__ROOT__,\\WorkerAdmin\\Application\\Chat\\ChatMediaPreflight::phpBinary(),$python,'/usr/bin/ffmpeg','/usr/bin/ffprobe',false);
header('Content-Type: application/json');echo json_encode($check,JSON_THROW_ON_ERROR);
""".replace("__CLASS__",json.dumps(str(root/"server/app/Application/Chat/ChatMediaPreflight.php"))).replace("__ROOT__",json.dumps(str(root))))
    env=os.environ.copy();env["CHAT_TEST_PYTHON"]=shutil.which("python")
    log=open(work/"web.log","w+")
    process=subprocess.Popen([php,"-d","open_basedir="+str(root)+os.pathsep+"/tmp","-d","display_errors=1","-S","127.0.0.1:"+str(listen),str(router)],cwd=root,env=env,stdout=log,stderr=log)
    def response(query=""):
        with urllib.request.urlopen("http://127.0.0.1:"+str(listen)+"/"+query,timeout=3) as result:return result.read().decode()
    try:
        body=wait_for(lambda:response())
        data=json.loads(body)
        check(data["probe_status"]=="complete" and data["pillow"] and data["php_cli"],"restricted web reads actual CLI result without statting system binaries")
        stale=json.loads(response("?stale=1"))
        check(stale["probe_status"]=="stale" and stale["checked_at"]==0,"changed tool path becomes pending, not a false missing-binary diagnosis")
        log.flush();log.seek(0);check("open_basedir restriction" not in log.read(),"restricted web emits no open_basedir warnings")
    finally:
        process.terminate();process.wait(timeout=10);log.close()
with tempfile.TemporaryDirectory(prefix="chat-launcher-reload-") as work:
    work=pathlib.Path(work);chat=work/"chat";state=chat/"bin/state";state.mkdir(parents=True)
    (chat/"deploy").mkdir()
    for script in ("bt-run.sh", "bt-site.sh"):
        shutil.copy(root/"chat/deploy"/script, chat/"deploy"/script)
    revision="a"*40;release=chat/"bin/releases"/revision;release.mkdir(parents=True)
    shutil.copy(os.environ["CHATD_TEST_BINARY"],release/"chatd");(release/"chatd").chmod(0o755)
    (chat/"bin/current").symlink_to("releases/"+revision)
    config=json.loads((root/"chat/config.example.json").read_text())
    config.update(env="test");config["http"]={"listen":"127.0.0.1:"+str(port()),"internal_listen":"127.0.0.1:"+str(port())}
    (chat/"chat.json").write_text(json.dumps(config))
    env=os.environ.copy();env.update(CHAT_CONFIG_MANUAL="1",CHAT_PHP_BIN=php)
    log=open(work/"launcher.log","w+")
    launcher=subprocess.Popen(["bash",str(chat/"deploy/bt-run.sh")],cwd=work,env=env,stdout=log,stderr=log)
    def processes():return [int(v) for v in (state/"processes").read_text().split()]
    def alive(pid):
        try:os.kill(pid,0);return True
        except ProcessLookupError:return False
    try:
        wait_for(lambda:(state/"running-sha").exists())
        before=processes();check(len(before)==2 and all(alive(pid) for pid in before),"real launcher starts exactly one serve/worker pair")
        token="b"*64;(state/"runtime-reload.request").write_text(token+"\n")
        wait_for(lambda:(state/"runtime-reload.applied").read_text().strip()==token)
        after=processes();check(after!=before and all(alive(pid) for pid in after) and not any(alive(pid) for pid in before),"backend reload request replaces both children without duplicate processes")
        time.sleep(6);check(processes()==after,"same reload token does not restart repeatedly")
        (state/"runtime-reload.request").write_text("not-a-command; whoami\n")
        time.sleep(8);check(processes()==after,"invalid reload marker is ignored without restarting or executing commands")
    finally:
        launcher.terminate();launcher.wait(timeout=55);log.close()
        check(not (state/"running-sha").exists(),"launcher termination clears readiness")
print("PASS TOTAL",count)
