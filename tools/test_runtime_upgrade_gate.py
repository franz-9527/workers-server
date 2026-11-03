#!/usr/bin/env python3
"""Independent repository: real export entry and shell upgrade gates."""
import getpass, json, os, pathlib, shutil, signal, subprocess, tempfile, time
root=pathlib.Path(__file__).resolve().parents[1]
if root.name!="workers-server" or os.environ.get("CHAT_UPGRADE_GATE_TEST")!="1":
    raise SystemExit("independent test repository required")
php=shutil.which("php");count=0
def check(ok,label):
    global count
    if not ok:raise AssertionError(label)
    count+=1;print("PASS",label,flush=True)
def run(args,cwd,env=None):
    return subprocess.run(args,cwd=cwd,env=env,text=True,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,timeout=30)
bootstrap=r"""<?php
namespace {
 function is_installed(){return getenv('FIXTURE_MODE')!=='not_installed';}
 function upgrade_required(){return getenv('FIXTURE_MODE')==='upgrade';}
 function app_config($key,$default=null){return $default;}
 function db(){return null;}
}
namespace WorkerAdmin\Foundation {
 class ApplicationContainer {public function __construct($db){} public function privateCipher(){return null;}}
}
namespace WorkerAdmin\Application\Chat {
 class ChatSigningKeyManager {public function __construct($db,$cipher){} public function status($config){return ['configured'=>true];}}
 class ChatGoRuntimeConfig {
  public function __construct($db,$cipher){}
  public function render($signing){if(getenv('FIXTURE_MODE')==='broken')throw new \RuntimeException('fixture-private-password');return ['config'=>['env'=>'test'],'environment'=>['CHAT_TEST_SECRET'=>'fixture-private-password'],'summary'=>['environment'=>'test']];}
  public static function environmentFile($env){return "export CHAT_TEST_SECRET='fixture-private-password'\n";}
 }
}
"""
with tempfile.TemporaryDirectory(prefix="chat-upgrade-gate-") as work:
    site=pathlib.Path(work);chat=site/"chat";state=chat/"bin/state"
    (site/"server/bin").mkdir(parents=True);(site/"server/config").mkdir()
    (chat/"deploy").mkdir(parents=True);state.mkdir(parents=True)
    shutil.copy(root/"server/bin/chat-runtime-config.php",site/"server/bin/chat-runtime-config.php")
    (site/"server/bootstrap.php").write_text(bootstrap)
    (site/"server/config/installed.php").write_text("<?php return ['app'=>['env'=>'testing']];")
    (chat/"go.mod").write_text("module fixture\n\ngo 1.27.3\n")
    for name in ["bt-deploy.sh","bt-run.sh"]:shutil.copy(root/"chat/deploy"/name,chat/"deploy"/name)
    env=os.environ.copy();env.update(CHAT_PHP_BIN=php,CHAT_RUN_USER=getpass.getuser())
    config=state/"chat.runtime.json";private=state/"runtime.env"
    config.write_text("old-private-config");private.write_text("old-private-environment")
    for mode,code,marker in [("not_installed",2,"CHAT_RUNTIME_CONFIG_NOT_INSTALLED"),("upgrade",3,"CHAT_RUNTIME_CONFIG_UPGRADE_REQUIRED"),("broken",2,"CHAT_RUNTIME_CONFIG_FAILED")]:
        env["FIXTURE_MODE"]=mode
        result=run([php,str(site/"server/bin/chat-runtime-config.php")],site,env)
        check(result.returncode==code and marker in result.stdout,"export distinguishes "+mode)
        check("fixture-private-password" not in result.stdout,"export never prints credentials: "+mode)
        check(config.read_text()=="old-private-config" and private.read_text()=="old-private-environment","failure preserves prior files: "+mode)
    check("系统升级" in run([php,str(site/"server/bin/chat-runtime-config.php")],site,{**env,"FIXTURE_MODE":"upgrade"}).stdout,"pending upgrade provides Chinese next step")
    for args in [["init","-b","main"],["config","user.name","Fixture"],["config","user.email","fixture@example.invalid"],["add","chat"],["commit","-m","isolated gate fixture"]]:
        result=run(["git",*args],site);check(result.returncode==0,"fixture git "+args[0])
    previous="a"*40;release=chat/"bin/releases"/previous;release.mkdir(parents=True)
    binary=release/"chatd";binary.write_text("#!/bin/sh\necho unexpected-start >> '"+str(site/"started")+"'\nexit 7\n");binary.chmod(0o755)
    (chat/"bin/current").symlink_to("releases/"+previous);(chat/"bin/deployed-sha").write_text(previous+"\n")
    env["FIXTURE_MODE"]="upgrade"
    result=run(["bash",str(chat/"deploy/bt-deploy.sh")],site,env)
    check(result.returncode==3 and "CHAT_DEPLOY_UPGRADE_REQUIRED" in result.stdout,"real deploy preserves upgrade exit status")
    check((state/"last-error").read_text().strip()=="CHAT_DEPLOY_UPGRADE_REQUIRED","deploy records a stable actionable code")
    check((chat/"bin/current").readlink()==pathlib.Path("releases/"+previous) and (chat/"bin/deployed-sha").read_text().strip()==previous,"pending database never switches live release")
    check(config.read_text()=="old-private-config" and private.read_text()=="old-private-environment","deploy never reuses or replaces config to bypass upgrade")
    check(not (site/"started").exists(),"pending deployment executes no old or new Go binary")
    log=open(site/"launcher.log","w+")
    launcher=subprocess.Popen(["bash",str(chat/"deploy/bt-run.sh")],cwd=site,env=env,stdout=log,stderr=log,start_new_session=True)
    try:
        deadline=time.monotonic()+5
        while time.monotonic()<deadline and (state/"last-error").read_text().strip()!="CHAT_RUN_UPGRADE_REQUIRED":time.sleep(.05)
        check((state/"last-error").read_text().strip()=="CHAT_RUN_UPGRADE_REQUIRED","real launcher reports waiting for database upgrade")
        check(not (site/"started").exists(),"launcher does not start Go while upgrade is pending")
    finally:
        os.killpg(launcher.pid,signal.SIGTERM);launcher.wait(timeout=20);log.close()
    tool=site/"fake-go";tool.write_text('#!/bin/sh\nif [ "$1" = env ]; then echo go1.27.3; exit 0; fi\necho FIXTURE_BUILD_REACHED\nexit 17\n');tool.chmod(0o755)
    env.update(FIXTURE_MODE="ready",CHAT_GO_BIN=str(tool))
    result=run(["bash",str(chat/"deploy/bt-deploy.sh")],site,env)
    check(result.returncode==17 and "FIXTURE_BUILD_REACHED" in result.stdout,"completed database upgrade resumes actual deployment build path")
    check("CHAT_RUNTIME_CONFIG_READY" in result.stdout and "fixture-private-password" not in result.stdout,"successful export prints only safe readiness summary")
    check(json.loads(config.read_text())["env"]=="test" and "CHAT_TEST_SECRET" in private.read_text(),"successful export atomically writes generated files")
    check(config.stat().st_mode&0o777==0o640 and private.stat().st_mode&0o777==0o600,"generated files retain restrictive permissions")
    check((chat/"bin/current").readlink()==pathlib.Path("releases/"+previous),"build failure still preserves old target")
    driver=site/"ui.php";source=str(root/"server/app/Application/Chat/ChatGoRuntimeStatus.php")
    driver.write_text('<?php require '+json.dumps(source)+'; $c=new ReflectionClass(WorkerAdmin\\Application\\Chat\\ChatGoRuntimeStatus::class);$o=$c->newInstanceWithoutConstructor();$m=$c->getMethod("errorMessage");foreach(["CHAT_RUN_UPGRADE_REQUIRED","CHAT_DEPLOY_UPGRADE_REQUIRED"] as $code)echo $m->invoke($o,$code)."\\n";')
    result=run([php,str(driver)],site,env)
    check(result.returncode==0 and result.stdout.count("系统升级")==2,"real backend displays Chinese upgrade guidance for both codes")
print("PASS TOTAL",count)
