#!/usr/bin/env python3
"""Linux integration: real Go builds and paired process lifecycle, isolated Git/test config."""
import fcntl, getpass, json, os, pathlib, shutil, signal, socket, subprocess, sys, tempfile, time, urllib.request
source=pathlib.Path(sys.argv[1]).resolve()
branch=sys.argv[2] if len(sys.argv)>2 else "chat"
if branch not in ("chat","main"):raise ValueError("expected chat or main test branch")
count=0
def check(ok,label):
    global count
    if not ok: raise AssertionError(label)
    count+=1;print("PASS",label,flush=True)
def port():
    with socket.socket() as s:s.bind(("127.0.0.1",0));return s.getsockname()[1]
def wait_for(fn,seconds=20):
    until=time.monotonic()+seconds
    while time.monotonic()<until:
        value=fn()
        if value:return value
        time.sleep(.2)
    raise AssertionError("timed out waiting for "+repr(fn))
with tempfile.TemporaryDirectory(prefix="chat git deployment ") as work:
    root=pathlib.Path(work);shutil.copytree(source/"chat",root/"chat")
    chat=root/"chat";bin=chat/"bin";state=bin/"state"
    installed=root/"server/config/installed.php";installed.parent.mkdir(parents=True)
    installed.write_text("<?php return ['app'=>['env'=>'testing'],'marker'=>'private-fixture'];")
    env=os.environ.copy();env.update(CHAT_PHP_BIN=shutil.which("php"),CHAT_GO_BIN=shutil.which("go"),CHAT_RUN_USER=getpass.getuser())
    def cmd(*args,good=True):
        r=subprocess.run(args,cwd=root,env=env,text=True,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,timeout=180)
        if good and r.returncode:raise AssertionError(str(args)+"\n"+r.stdout)
        return r
    def git(*args):return cmd("git",*args).stdout.strip()
    git("init","-b",branch);git("config","user.name","Synthetic fixture");git("config","user.email","fixture@example.invalid")
    git("add","chat");git("commit","-m","isolated deployment fixture")
    first=git("rev-parse","HEAD")
    deploy=lambda good=True:cmd("bash","chat/deploy/bt-deploy.sh",good=good)
    r=deploy();check("CHAT_DEPLOY_BUILT:" in r.stdout,"first Git checkout builds Linux executable")
    check(json.loads((chat/"chat.json").read_text())["env"]=="test","first config is test only")
    check(not json.loads((chat/"chat.json").read_text())["messages"]["enabled"],"bootstrap does not enable business")
    check(json.loads((bin/"current/version.json").read_text())["source_revision"]==first,"real binary records exact committed source")
    check(installed.read_text().endswith("'private-fixture'];"),"installed PHP config survives")
    config=json.loads((chat/"chat.json").read_text());config["http"]={"listen":"127.0.0.1:"+str(port()),"internal_listen":"127.0.0.1:"+str(port())}
    (chat/"chat.json").write_text(json.dumps(config));saved=(chat/"chat.json").read_bytes()
    log=open(root/"runtime.log","w+");launcher=subprocess.Popen(["bash",str(chat/"deploy/bt-run.sh")],cwd=root,env=env,stdout=log,stderr=subprocess.STDOUT)
    def running(sha):
        try:return (state/"running-sha").read_text().strip()==sha
        except FileNotFoundError:return False
    def processes():return [int(p) for p in (state/"processes").read_text().split()]
    def alive(pid):
        try:os.kill(pid,0);return True
        except ProcessLookupError:return False
    try:
        wait_for(lambda:running(first))
        old=processes();check(len(old)==2 and all(alive(p) for p in old),"one project starts real serve and worker")
        check(b"serve" in pathlib.Path("/proc",str(old[0]),"cmdline").read_bytes(),"serve child uses expected role")
        check(b"worker" in pathlib.Path("/proc",str(old[1]),"cmdline").read_bytes(),"worker child uses expected role")
        check(urllib.request.urlopen("http://"+config["http"]["listen"]+"/livez",timeout=3).status==200,"real HTTP listener lives")
        dup=cmd("bash",str(chat/"deploy/bt-run.sh"),good=False)
        check(dup.returncode==2 and "CHAT_RUN_ALREADY_STARTED" in dup.stdout,"duplicate panel start cannot own a second pair")
        r=deploy();check("CHAT_DEPLOY_RUNNING:" in r.stdout and processes()==old,"same commit is reproducible and does not restart pair")
        with open(bin/"deploy.lock","w") as lock:
            fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB);r=deploy(False)
            check(r.returncode!=0 and "CHAT_DEPLOY_BUSY" in r.stdout,"concurrent deploy is rejected")
        bad=dict(config);bad["env"]="production";(chat/"chat.json").write_text(json.dumps(bad))
        r=deploy(False);check(r.returncode!=0 and os.readlink(bin/"current")=="releases/"+first,"production config cannot replace live test release")
        (chat/"chat.json").write_bytes(saved)
        # New committed version; a broken uncommitted source file must NOT be compiled.
        (chat/"deployment-fixture.txt").write_text("committed second revision")
        git("add","chat/deployment-fixture.txt");git("commit","-m","second synthetic source")
        second=git("rev-parse","HEAD")
        app=chat/"internal/bootstrap/app.go";original=app.read_text();app.write_text(original+"\ninvalid uncommitted Go source\n")
        r=deploy();check("CHAT_DEPLOY_RUNNING: "+second in r.stdout,"sync automatically replaces both processes")
        new=processes();check(new!=old and all(not alive(p) for p in old),"old serve and worker fully stop before replacement")
        check((chat/"chat.json").read_bytes()==saved,"private chat config is never overwritten")
        check(json.loads((bin/"current/version.json").read_text())["source_revision"]==second,"archive build excludes dirty worktree bytes")
        app.write_text(original+"\ninvalid committed Go source\n");git("add","chat/internal/bootstrap/app.go");git("commit","-m","synthetic compile failure")
        r=deploy(False);check(r.returncode!=0 and running(second) and os.readlink(bin/"current")=="releases/"+second,"compile failure leaves live release untouched")
        app.write_text(original);git("add","chat/internal/bootstrap/app.go");git("commit","-m","restore synthetic build")
        # Child crash: launcher recreates pair and retains the same version.
        before=processes();os.kill(before[1],signal.SIGTERM)
        wait_for(lambda:running(second) and (state/"processes").exists() and processes()!=before)
        check(all(not alive(p) for p in before),"worker exit restarts pair without orphaning old serve")
        # Valid build whose worker exits at runtime: deployment must restore prior pointer.
        broken=original.replace('role := command','if command == "worker" { return 1 }\n\trole := command',1)
        assert broken!=original
        app.write_text(broken);git("add","chat/internal/bootstrap/app.go");git("commit","-m","synthetic startup failure")
        r=deploy(False);check(r.returncode!=0 and "previous Go release restored" in r.stdout,"failed startup automatically restores previous Go target")
        wait_for(lambda:running(second))
        check(os.readlink(bin/"current")=="releases/"+second,"previous compiled version runs after rollback")
        installed.write_text("<?php return ['app'=>['env'=>'production']];")
        r=deploy(False);check(r.returncode!=0 and "CHAT_DEPLOY_TEST_SITE_REQUIRED" in r.stdout,"production PHP site cannot use test deploy helper")
        installed.write_text("<?php return ['app'=>['env'=>'testing']];")
        git("checkout","-b","unsupported-deploy");r=deploy(False)
        check(r.returncode!=0 and "CHAT_DEPLOY_BRANCH_REQUIRED" in r.stdout,"unsupported deployment branch is refused")
        git("checkout","--detach");r=deploy(False)
        check(r.returncode!=0 and "CHAT_DEPLOY_BRANCH_REQUIRED" in r.stdout,"detached deployment is refused")
        children=processes();launcher.terminate();launcher.wait(timeout=55)
        check(all(not alive(p) for p in children) and not (state/"running-sha").exists(),"panel TERM stops both children and clears running marker")
        check(not git("status","--porcelain","--untracked-files=no"),"deployment never edits tracked source")
    finally:
        if launcher.poll() is None:launcher.terminate();launcher.wait(timeout=55)
        log.seek(0);text=log.read();log.close()
        print("LIFECYCLE_LOG\n"+text[-7000:],flush=True)
print("BaoTa full Git deployment:",branch,count,"integration assertions passed")
