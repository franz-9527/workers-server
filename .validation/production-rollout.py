import base64
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
from urllib.error import HTTPError
from urllib.request import Request, urlopen

repo = Path.cwd()
token = "SyntheticToken1234"
with tempfile.TemporaryDirectory(prefix="chat-sites-") as work:
    work = Path(work)
    source = work / "source"
    source.mkdir()
    shutil.copytree(repo / "chat", source / "chat")
    chat = source / "chat"
    php_root = work / "worker"
    installed = php_root / "config" / "installed.php"
    installed.parent.mkdir(parents=True)
    env = os.environ | {
        "CHAT_PHP_ROOT": str(php_root), "CHAT_PHP_BIN": shutil.which("php"),
        "CHAT_GO_BIN": shutil.which("go"), "CHAT_RUN_USER": subprocess.check_output(["id", "-un"], text=True).strip(),
        "CHAT_WECHAT_CALLBACK_TOKEN": token,
        "CHAT_WECHAT_CALLBACK_AES_KEY": base64.b64encode(bytes(32)).decode().rstrip("="),
    }

    def command(*args, expect=None):
        result = subprocess.run(args, cwd=source, env=env, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=120)
        if expect is None:
            assert result.returncode == 0, result.stdout
        else:
            assert result.returncode != 0 and expect in result.stdout, result.stdout
        return result.stdout.strip()

    def set_php_environment(name):
        installed.write_text("<?php return ['app'=>['env'=>'" + name + "']];")

    command("git", "init", "-b", "main")
    command("git", "config", "user.name", "Isolated Test")
    command("git", "config", "user.email", "isolated@example.invalid")
    command("git", "add", "chat")
    command("git", "commit", "-m", "Fixture")
    commit = command("git", "rev-parse", "HEAD")
    config = json.loads((chat / "config.example.json").read_text())
    config.update(env="production")
    config["http"] = {"listen": "127.0.0.1:9501", "internal_listen": "127.0.0.1:9502"}
    config["safety"]["app_id"] = "wx0123456789abcdef"
    config["av"]["callback_verification"] = True
    config_path = chat / "chat.json"
    config_path.write_text(json.dumps(config))
    deploy = ("bash", "chat/deploy/bt-deploy.sh", "--production")
    set_php_environment("testing")
    command(*deploy, expect="CHAT_DEPLOY_PRODUCTION_SITE_REQUIRED")
    set_php_environment("production")
    config["http"]["listen"] = "127.0.0.1:19501"
    config_path.write_text(json.dumps(config))
    command(*deploy, expect="CHAT_DEPLOY_SITE_CONFIG_MISMATCH")
    config["http"]["listen"] = "127.0.0.1:9501"
    config_path.write_text(json.dumps(config))
    test_installed = source / "server/config/installed.php"
    test_installed.parent.mkdir(parents=True)
    test_installed.write_text("<?php return ['app'=>['env'=>'testing']];")
    command(*deploy, expect="CHAT_DEPLOY_PRODUCTION_SITE_REQUIRED")
    command("bash", "chat/deploy/bt-deploy.sh", "invalid", expect="CHAT_DEPLOY_USAGE")
    env["CHAT_CONFIG_MANUAL"] = "1"
    command("bash", "chat/deploy/bt-deploy.sh", expect="CHAT_DEPLOY_SITE_CONFIG_MISMATCH")
    test_config = json.loads((chat / "config.example.json").read_text())
    test_config["env"] = "test"
    config_path.write_text(json.dumps(test_config))
    assert "CHAT_DEPLOY_BUILT: " + commit in command("bash", "chat/deploy/bt-deploy.sh")
    test_installed.unlink()
    config_path.write_text(json.dumps(config))
    assert "CHAT_DEPLOY_BUILT: " + commit in command(*deploy)

    launch = ("bash", "chat/deploy/bt-run.sh", "--production")
    state = chat / "bin/state"
    with (work / "go.log").open("w+") as log:
        proc = subprocess.Popen(launch, cwd=source, env=env, stdout=log, stderr=log)
        try:
            for _ in range(80):
                if (state / "running-sha").is_file():
                    break
                if proc.poll() is not None:
                    break
                time.sleep(.2)
            log.seek(0)
            assert (state / "running-sha").exists(), log.read()
            assert (state / "running-sha").read_text().strip() == commit
            callback = "http://127.0.0.1:9501/chat/api/v1/av/callback"
            timestamp = str(int(time.time()))
            nonce = "fixture"
            signature = hashlib.sha1("".join(sorted([token, timestamp, nonce])).encode()).hexdigest()
            url = callback + f"?timestamp={timestamp}&nonce={nonce}&echostr=proof&signature={signature}"
            with urlopen(url, timeout=5) as reply:
                assert reply.status == 200 and reply.read() == b"proof"
            for request, status in [(Request(callback), 403), (Request(callback, data=b"{}", method="POST"), 503)]:
                try:
                    urlopen(request, timeout=5).close()
                except HTTPError as error:
                    assert error.code == status, error
                else:
                    raise AssertionError(f"expected HTTP {status}")
            print("PASS: production and test site isolation, actual Linux build and startup, signed challenge, unsigned GET and closed event processing")
        finally:
            proc.terminate()
            proc.wait(timeout=50)
    assert not (state / "running-sha").exists()
