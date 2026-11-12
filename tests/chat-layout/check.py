import json, os, pathlib, shutil, socket, subprocess, tempfile, time, urllib.request

source=pathlib.Path(__file__).resolve().parents[2]
assert source.name=="workers-server"
sha="a"*40
token="b"*64

def check(ok, text):
    if not ok:
        raise AssertionError(text)
    print("PASS",text,flush=True)

def request(php_root, scope, env, query=""):
    with socket.socket() as server:
        server.bind(("127.0.0.1",0))
        port=server.getsockname()[1]
    command=["php","-d",f"open_basedir={scope}:/tmp","-d","display_errors=1",
             "-S",f"127.0.0.1:{port}",str(php_root/"router.php")]
    child=subprocess.Popen(command,env=dict(os.environ,CHAT_LAYOUT_ENV=env),cwd=php_root,
                           stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
    try:
        for _ in range(35):
            try:
                with urllib.request.urlopen(f"http://127.0.0.1:{port}/"+query,timeout=4) as handle:
                    raw=handle.read().decode()
                    try:
                        return json.loads(raw)
                    except ValueError as error:
                        raise RuntimeError(raw) from error
            except urllib.error.URLError as error:
                if child.poll() is not None:
                    raise RuntimeError(child.stderr.read().decode()) from error
                time.sleep(.1)
        raise RuntimeError("web fixture did not listen")
    finally:
        child.terminate()
        child.wait(timeout=8)
        child.stderr.close()

def install(site):
    shutil.copytree(source/"server",site)
    shutil.copy(source/"tests/chat-layout/router.php",site/"router.php")

with tempfile.TemporaryDirectory(dir=source,prefix="chat-layout-") as work:
    work=pathlib.Path(work)
    # Production backend ZIP puts the PHP app directly under worker/.
    flat=work/"worker"
    install(flat)
    outside=work/"chat"/"bin"/"state"
    outside.mkdir(parents=True)
    (outside/"running-sha").write_text(sha+"\n")
    old=work/"worker-old"
    install(old)
    for name in ["ChatGoRuntimeConfig.php","ChatGoRuntimeStatus.php","ChatRuntimeControl.php"]:
        shutil.copy(source/"tests/chat-layout/previous"/name,old/"app/Application/Chat"/name)
    old_result=request(old,old,"production")
    check(any("open_basedir restriction" in e for e in old_result["errors"]),"released-style layout reproduces warnings before fix")
    flat_result=request(flat,flat,"production","?config=1&apply=1")
    check(flat_result["errors"]==[],f"standalone installation makes no out-of-scope probes {flat_result['errors']}")
    check(flat_result["rendered"] and flat_result["go_state"]=="not_deployed","chat settings renders a normal not-deployed status")
    check(not flat_result["runtime_config"] and not flat_result["calls"] and not flat_result["apply"],"flat site does not infer Go capabilities or request reload")
    check("Go 自动部署目录" in flat_result["config_error"],"CLI config builder reports the missing managed layout")

    repo=work/"workers-test"
    nested=repo/"server"
    install(nested)
    shutil.copytree(source/"chat",repo/"chat")
    bin_dir=repo/"chat"/"bin"
    state=bin_dir/"state"
    state.mkdir(parents=True)
    (bin_dir/"deployed-sha").write_text(sha+"\n")
    (state/"running-sha").write_text(sha+"\n")
    (state/"chat.runtime.json").write_text(json.dumps({"env":"test","calls":{"enabled":True}}))
    (state/"runtime-reload.request").write_text(token+"\n")
    (state/"runtime-reload.applied").write_text(token+"\n")
    allowed=request(nested,repo,"testing","?config=1")
    check(allowed["errors"]==[],f"full repository uses only permitted paths {allowed['errors']}")
    check(allowed["deployed"]==sha and allowed["running"]==sha and allowed["runtime_config"] and allowed["calls"],"nested site keeps Go version, runtime configuration and capability reads")
    check(allowed["reload"]=="applied","nested site keeps reading existing reload acknowledgments")
    check(allowed["php_script"]==str(nested/"bin/chat-images.php") and allowed["python_script"]==str(repo/"chat/helpers/images/inspect.py"),"Go configuration points to correct PHP and Python helpers")
    restricted=request(nested,nested,"testing")
    check(restricted["errors"]==[] and restricted["go_state"]=="not_deployed" and restricted["deployed"]=="" and not restricted["calls"],"PHP-only scope does not read sibling Go metadata")
